<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * The dashboard through MainWP's REST API (version 2) with a bearer key, over HTTPS only. MainWP
 * answers most refusals with HTTP 200 and success 0; each such answer, each HTTP error and each
 * call that gets no answer becomes one DashboardFailure with a reason in plain words. The key is
 * never part of a message.
 */
final class MainWpDashboard implements Dashboard
{
    /** Sites are read 20 rows at a time, the size MainWP's own site listing uses: a page of sites with many plugins and themes stays well under CurlHttp::MAX_BODY. */
    private const SITES_PAGE = 20;

    /** Clients are read 100 rows at a time: a client's row is small, and every provision reads the list. */
    private const CLIENTS_PAGE = 100;

    /** No list is read further than this many pages. */
    private const MAX_PAGES = 1000;

    private readonly string $base;

    /**
     * @param string $notePrefix the words before the billing-system client id in a dashboard client's note, e.g. "WHMCS client"
     */
    public function __construct(
        string $dashboardUrl,
        #[\SensitiveParameter] private readonly string $key,
        private readonly Http $http,
        private readonly string $notePrefix = 'Billing client',
    ) {
        $this->base = rtrim(trim($dashboardUrl), '/');
    }

    /**
     * Has the value the form of a key the dashboard makes? MainWP joins a key's two halves with
     * "==" and its server splits the bearer value there: a value with no "==" is answered "Invalid
     * OAuth parameter" whatever it is, and one with an empty half fails as a key the dashboard
     * does not know. The halves' length is MainWP's to change.
     */
    public static function isKey(#[\SensitiveParameter] string $value): bool
    {
        return preg_match('/\A[0-9A-Za-z]+==[0-9A-Za-z]+\z/', $value) === 1;
    }

    public function counts(): array
    {
        $clients = $this->call('GET', 'clients/count', 'count the clients');
        $sites = $this->call('GET', 'sites/count', 'count the sites');
        return ['clients' => self::int($clients['total'] ?? null, 'count the clients'), 'sites' => self::int($sites['count'] ?? null, 'count the sites')];
    }

    public function clients(): array
    {
        return $this->pages('clients', 'list the clients', self::CLIENTS_PAGE, fn (array $row): DashboardClient => new DashboardClient(
            self::int($row['id'] ?? null, 'list the clients'),
            self::text($row['name'] ?? ''),
            self::text($row['client_email'] ?? ''),
            $this->billingId(self::text($row['note'] ?? '')),
        ));
    }

    public function findClient(int $billingClientId): ?int
    {
        foreach ($this->clients() as $client) {
            if ($client->billingClientId === $billingClientId) {
                return $client->id;
            }
        }
        return null;
    }

    public function createClient(int $billingClientId, string $name, string $email): int
    {
        $answer = $this->call('POST', 'clients/add', 'create the client', ['name' => $name, 'client_email' => $email, 'note' => $this->notePrefix . ' ' . $billingClientId]);
        return self::int(self::row($answer)['id'] ?? null, 'create the client');
    }

    public function sites(): array
    {
        return $this->pages('sites', 'list the sites', self::SITES_PAGE, static fn (array $row): DashboardSite => self::toSite($row, 'list the sites'));
    }

    public function site(int $dashboardSiteId): DashboardSite
    {
        return self::toSite(self::row($this->call('GET', 'sites/' . $dashboardSiteId, 'read site ' . $dashboardSiteId)), 'read site ' . $dashboardSiteId);
    }

    public function addSite(int $dashboardClientId, string $url, Login $login): int
    {
        // MainWP strips slashes from what it is sent and URL-decodes the password once more (seen on a test bench).
        $answer = $this->call('POST', 'sites/add', 'connect ' . $url, [
            'url' => $url,
            'name' => (string) (parse_url($url, PHP_URL_HOST) ?: $url),
            'admin' => $login->username,
            'adminpassword' => rawurlencode(addslashes($login->password)),
            'client_id' => (string) $dashboardClientId,
        ], $login->password);
        return self::int(self::row($answer)['id'] ?? null, 'connect ' . $url);
    }

    public function syncSite(int $dashboardSiteId): DashboardSite
    {
        return self::toSite(self::row($this->call('POST', 'sites/' . $dashboardSiteId . '/sync', 'sync site ' . $dashboardSiteId)), 'sync site ' . $dashboardSiteId);
    }

    public function reconnectSite(int $dashboardSiteId): void
    {
        $this->call('POST', 'sites/' . $dashboardSiteId . '/reconnect', 'reconnect site ' . $dashboardSiteId);
    }

    /** Always for one named site: without one MainWP answers for none (seen on a test bench). */
    public function administrators(int $dashboardSiteId): array
    {
        $what = 'list the administrators of site ' . $dashboardSiteId;
        $data = $this->call('GET', 'users?websites=' . $dashboardSiteId . '&roles=administrator', $what)['data'] ?? null;
        if (!is_array($data)) {
            throw new DashboardFailure(DashboardFailure::BAD_ANSWER, 'The dashboard\'s answer to "' . $what . '" held no list.');
        }
        // The users come grouped under the site's address; the login is "username" ("name" is the nicename).
        $logins = [];
        foreach ($data as $users) {
            foreach (is_array($users) ? $users : [null] as $user) {
                $login = is_array($user) ? ($user['username'] ?? null) : null;
                if (!is_string($login) || $login === '') {
                    throw new DashboardFailure(DashboardFailure::BAD_ANSWER, 'The dashboard\'s answer to "' . $what . '" held a user without a login.');
                }
                $logins[] = $login;
            }
        }
        return $logins;
    }

    /**
     * POST users/create takes JSON only. The role is always named: without it MainWP makes a
     * subscriber. send_password is never sent: with it MainWP Child mails the password in plain text.
     * MainWP answers a site's refusal with HTTP 200 and success 1 (only total_failed and failed_sites
     * say it), and repeats the password in the clear in every answer (data.user_info.user_pass), so no
     * part of the answer leaves this method uncleaned (seen on a test bench).
     */
    public function createAdministrator(int $dashboardSiteId, Login $account, string $email, string $firstName, string $lastName): void
    {
        $what = 'make administrator ' . $account->username . ' on site ' . $dashboardSiteId;
        $body = json_encode([
            'websites' => (string) $dashboardSiteId,
            'username' => $account->username,
            'email' => $email,
            'role' => 'administrator',
            'password' => $account->password,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $data = self::row($this->call('POST', 'users/create', $what, $body, $account->password));
        $failed = is_array($data['failed_sites'] ?? null) ? ($data['failed_sites'][$dashboardSiteId] ?? null) : null;
        if (is_array($failed) || (is_numeric($data['total_failed'] ?? null) && (int) $data['total_failed'] > 0)) {
            $words = is_array($failed) && is_string($failed['message'] ?? null) ? self::firstLine($failed['message'], [$account->password, $this->key]) : '';
            $words = $words !== '' ? $words : 'the site gave no reason';
            throw new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to ' . $what . ': ' . $words . (preg_match('/[!?]$/', $words) === 1 ? '' : '.'));
        }
        $created = is_array($data['created_sites'] ?? null) ? ($data['created_sites'][$dashboardSiteId] ?? null) : null;
        if (self::text($data['total_failed'] ?? '') !== '0' || !is_array($created)) {
            throw new DashboardFailure(DashboardFailure::BAD_ANSWER, 'The dashboard\'s answer to "' . $what . '" did not say the administrator was made.');
        }
    }

    public function setSiteAdmin(int $dashboardSiteId, string $adminLogin): void
    {
        $this->call('POST', 'sites/' . $dashboardSiteId . '/edit', 'point site ' . $dashboardSiteId . ' at another administrator', ['admin' => $adminLogin]);
    }

    public function suspendSite(int $dashboardSiteId): void
    {
        $this->call('POST', 'sites/' . $dashboardSiteId . '/suspend', 'suspend site ' . $dashboardSiteId);
    }

    public function resumeSite(int $dashboardSiteId): void
    {
        $this->call('POST', 'sites/' . $dashboardSiteId . '/unsuspend', 'resume site ' . $dashboardSiteId);
    }

    public function removeSite(int $dashboardSiteId): string
    {
        return self::reason($this->call('DELETE', 'sites/' . $dashboardSiteId . '/remove', 'remove site ' . $dashboardSiteId), [$this->key]);
    }

    /**
     * Every row of a list route, page by page, until a page brings nothing new. MainWP's "total" does
     * not say where the list ends: its clients route, and its sites route on a WordPress without the
     * Abilities API, answer the number of rows on that page. Only a total larger than the page it
     * came with is one of the whole list, and ends the reading without another call. A route that
     * answers everything on every page brings nothing new on the second. A list that does not come
     * to an end is a failure. (A server that answered its first page again for every page number
     * could not be told from a list of one page; MainWP's routes honour the page.) Of each row only
     * what $keep makes of it is kept, so a long list of sites, each with its plugins and themes, is
     * never held whole.
     *
     * @template T
     * @param \Closure(array<string, mixed>): T $keep
     * @return list<T>
     */
    private function pages(string $route, string $what, int $size, \Closure $keep): array
    {
        $rows = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $answer = $this->call('GET', $route . '?page=' . $page . '&per_page=' . $size, $what);
            $data = $answer['data'] ?? null;
            if (!is_array($data)) {
                throw new DashboardFailure(DashboardFailure::BAD_ANSWER, 'The dashboard\'s answer to "' . $what . '" held no list.');
            }
            $new = 0;
            foreach ($data as $row) {
                if (is_array($row) && isset($row['id']) && !isset($rows[(string) $row['id']])) {
                    $rows[(string) $row['id']] = $keep($row);
                    $new++;
                }
            }
            $total = is_numeric($answer['total'] ?? null) ? (int) $answer['total'] : null;
            if ($new === 0 || ($total !== null && $total > count($data) && count($rows) >= $total)) {
                return array_values($rows);
            }
        }
        throw new DashboardFailure(DashboardFailure::BAD_ANSWER, 'The dashboard\'s answer to "' . $what . '" did not come to an end after ' . self::MAX_PAGES . ' pages.');
    }

    /**
     * One call to the API. Returns MainWP's JSON answer; throws a DashboardFailure for anything else.
     *
     * @param array<string, string>|string $form a form, or a JSON body as a string
     * @return array<string, mixed>
     */
    private function call(string $method, string $route, string $what, array|string $form = [], string ...$secrets): array
    {
        if ($this->base === '' || $this->key === '') {
            throw new DashboardFailure(DashboardFailure::NOT_CONFIGURED, 'The dashboard address and API key are not both set; enter them in the module\'s settings.');
        }
        if (!str_starts_with(strtolower($this->base), 'https://') || filter_var($this->base, FILTER_VALIDATE_URL) === false) {
            throw new DashboardFailure(DashboardFailure::NOT_HTTPS, 'The dashboard address must be an https:// address; the API key is never sent over plain HTTP. Nothing was sent.');
        }
        $secrets[] = $this->key;
        $host = (string) parse_url($this->base, PHP_URL_HOST);
        try {
            $headers = ['Authorization' => 'Bearer ' . $this->key, 'Accept' => 'application/json'] + (is_string($form) ? ['Content-Type' => 'application/json'] : []);
            $response = $this->http->request($method, $this->base . '/wp-json/mainwp/v2/' . $route, $headers, $form);
        } catch (HttpTimeout $e) {
            throw new DashboardFailure(DashboardFailure::TIMED_OUT, 'The dashboard at ' . $host . ' did not answer in time to ' . $what . ': ' . self::clean($e->getMessage(), $secrets) . '.');
        } catch (HttpTooLarge) {
            throw new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard at ' . $host . ' sent an answer larger than 2 MB when asked to ' . $what . ', so it was not read.');
        } catch (\RuntimeException $e) {
            throw new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard at ' . $host . ' could not be reached to ' . $what . ': ' . self::clean($e->getMessage(), $secrets) . '.');
        }
        $answer = json_decode($response->body, true);
        $message = is_array($answer) ? self::reason($answer, $secrets) : '';
        if ($response->status === 401 || $response->status === 403) {
            throw new DashboardFailure(DashboardFailure::KEY_REFUSED, 'The dashboard refused the API key (HTTP ' . $response->status . ($message !== '' ? ': ' . $message : '') . '). Make a key on the dashboard under API Access and enter it in the module\'s settings. If the key is right, the dashboard\'s web server may not be passing the Authorization header on to WordPress.');
        }
        if ($response->status === 404) {
            // Only MainWP's own 404 says the item is unknown ("mainwp_rest_invalid_site_id"). Any other one means that
            // MainWP's REST API is not at this address, which says nothing about what the dashboard holds.
            if (is_array($answer) && is_string($answer['code'] ?? null) && str_starts_with($answer['code'], 'mainwp_')) {
                throw new DashboardFailure(DashboardFailure::NOT_FOUND, 'The dashboard could not ' . $what . ': it has no such item (HTTP 404' . ($message !== '' ? ': ' . $message : '') . ').');
            }
            throw new DashboardFailure(DashboardFailure::BAD_ANSWER, 'The dashboard at ' . $host . ' does not answer as MainWP\'s REST API when asked to ' . $what . ' (HTTP 404' . ($message !== '' ? ': ' . $message : '') . '). Check the dashboard address in the module\'s settings and that MainWP Dashboard is active there; the dashboard\'s permalinks must not be set to Plain.');
        }
        if (is_array($answer) && ($answer['code'] ?? null) === 'no_website_found' && $response->status >= 400) {
            // HTTP 500 from the users routes: MainWP leaves out a site whose last sync failed, or that is suspended, before asking it.
            throw new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to ' . $what . ': No website found (MainWP answers so for a site whose last sync failed or that is suspended).');
        }
        if (is_array($answer) && is_string($answer['code'] ?? null) && str_starts_with($answer['code'], 'mainwp_') && $response->status >= 400) {
            // MainWP's own error with another status: it answers a reconnect it cannot do with HTTP 500.
            throw new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to ' . $what . ': ' . ($message !== '' ? $message : 'it gave no reason') . '.');
        }
        if ($response->status < 200 || $response->status > 299 || !is_array($answer)) {
            throw new DashboardFailure(DashboardFailure::BAD_ANSWER, 'The dashboard answered "' . $what . '" with HTTP ' . $response->status . ' and ' . ($message !== '' ? 'this: ' . $message : 'no answer MainWP gives') . '.');
        }
        if (array_key_exists('success', $answer) && (string) $answer['success'] !== '1') {
            // MainWP names the site it already holds in found_id, unless the key may not see that site.
            $held = $answer['found_id'] ?? null;
            if (is_numeric($held) || stripos($message, 'already connected to your MainWP Dashboard') !== false) {
                throw new DashboardFailure(DashboardFailure::ALREADY_HELD, 'The dashboard refused to ' . $what . ': it already holds a site at that address' . (is_numeric($held) ? ' (dashboard site ' . (int) $held . ')' : '') . '. When that site is connected and filed under this client, the next retry takes it over; otherwise remove it on the dashboard.');
            }
            // MainWP hides what the site's plugin answered when it names a key; seen when the plugin still held an earlier connection.
            if (str_starts_with($message, '[response content hidden')) {
                throw new DashboardFailure(DashboardFailure::EARLIER_CONNECTION, 'The dashboard refused to ' . $what . ': MainWP Child on the site refused it, and MainWP hid the reason. When the site was connected to a dashboard before, MainWP Child still holds that connection: clear it on the site (Settings, MainWP Child, Disconnect) and connect again.');
            }
            throw new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to ' . $what . ': ' . ($message !== '' ? $message : 'it gave no reason') . '.');
        }
        return $answer;
    }

    /**
     * MainWP's own words, first line only (its connection errors go on with diagnostics such as IP
     * addresses and response headers), without markup, entities or a trailing full stop.
     *
     * @param array<array-key, mixed> $answer
     * @param list<string> $secrets
     */
    private static function reason(array $answer, array $secrets): string
    {
        foreach (['error', 'message'] as $field) {
            $text = $answer[$field] ?? null;
            if (is_string($text) && trim($text) !== '') {
                return self::firstLine($text, $secrets);
            }
        }
        return '';
    }

    /**
     * The first line of a text of MainWP's, without markup, entities or a trailing full stop. What
     * follows the first line of its connection errors is diagnostics: IP addresses, its User-Agent,
     * the site's raw answer.
     *
     * @param list<string> $secrets
     */
    private static function firstLine(string $text, array $secrets): string
    {
        $line = trim(strtok(trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')), "\n") ?: '');
        return rtrim(self::clean($line, $secrets), '.');
    }

    /** @param list<string> $secrets */
    private static function clean(string $text, array $secrets): string
    {
        return rtrim(LogText::clean($text, ...$secrets), '.');
    }

    /**
     * @param array<string, mixed> $answer
     * @return array<string, mixed>
     */
    private static function row(array $answer): array
    {
        return is_array($answer['data'] ?? null) ? $answer['data'] : [];
    }

    /** @param array<array-key, mixed> $row */
    private static function toSite(array $row, string $what): DashboardSite
    {
        $client = $row['client_id'] ?? null;
        $sync = self::text($row['last_sync'] ?? '');
        return new DashboardSite(
            self::int($row['id'] ?? null, $what),
            self::text($row['url'] ?? ''),
            is_numeric($client) && (int) $client > 0 ? (int) $client : null,
            self::text($row['status'] ?? ''),
            $sync === '' ? null : $sync,
            self::firstLine(self::text($row['sync_errors'] ?? ''), []),
        );
    }

    private function billingId(string $note): ?int
    {
        return preg_match('/(?:^|\s)' . preg_quote($this->notePrefix, '/') . ' (\d+)\b/', $note, $m) === 1 ? (int) $m[1] : null;
    }

    private static function int(mixed $value, string $what): int
    {
        if (!is_numeric($value)) {
            throw new DashboardFailure(DashboardFailure::BAD_ANSWER, 'The dashboard\'s answer to "' . $what . '" held no number where one was expected.');
        }
        return (int) $value;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
