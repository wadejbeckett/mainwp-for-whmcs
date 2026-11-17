<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use MainWpForWhmcs\Engine\CareSite;
use MainWpForWhmcs\Engine\Clock;
use MainWpForWhmcs\Engine\Dashboard;
use MainWpForWhmcs\Engine\DashboardFailure;
use MainWpForWhmcs\Engine\DashboardSite;
use MainWpForWhmcs\Engine\LogEntry;
use MainWpForWhmcs\Engine\Approval;
use MainWpForWhmcs\Engine\HeldKey;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\Secrets;
use MainWpForWhmcs\Engine\Site;
use MainWpForWhmcs\Engine\SiteFailure;
use MainWpForWhmcs\Engine\SiteIndex;
use MainWpForWhmcs\Engine\Storage;

/** A dashboard that records every call; with $offline set, every call fails as an unreachable dashboard would. */
final class RecordingDashboard implements Dashboard
{
    /** @var list<string> */
    public array $calls = [];
    /** @var array<int, int> WHMCS client id => dashboard client id */
    public array $clients = [];
    public ?\Throwable $offline = null;
    private int $next = 100;
    /** Thrown by counts() alone when set. */
    public ?\Throwable $countsFail = null;
    /** @var array<int, \Throwable> dashboard site id => thrown by its sync alone (a site the dashboard waits for in vain, say) */
    public array $syncFails = [];

    public function counts(): array
    {
        $this->call('counts');
        if ($this->countsFail !== null) {
            throw $this->countsFail;
        }
        return ['clients' => count($this->clients), 'sites' => 0];
    }

    /** @var list<\MainWpForWhmcs\Engine\DashboardClient> what the dashboard says its clients are */
    public array $clientsList = [];

    /** Thrown by clients() alone when set. */
    public ?\Throwable $clientsFails = null;

    public function clients(): array
    {
        $this->call('clients');
        if ($this->clientsFails !== null) {
            throw $this->clientsFails;
        }
        return $this->clientsList;
    }

    /** @var list<DashboardSite> what the dashboard says it holds */
    public array $sitesList = [];
    /** Thrown by addSite alone when set: the dashboard is reachable but this call fails. */
    public ?\Throwable $addRefuses = null;

    /** Thrown by sites() alone when set. */
    public ?\Throwable $sitesRefuse = null;

    public function sites(): array
    {
        $this->call('sites');
        if ($this->sitesRefuse !== null) {
            throw $this->sitesRefuse;
        }
        return $this->sitesList;
    }

    /** @var list<int> dashboard site ids the dashboard does not hold (any more) */
    public array $unknown = [];
    /** Thrown by site() alone when set: the site's row cannot be read, though the dashboard answers. */
    public ?\Throwable $readRefuses = null;

    /** @var array<int, string> dashboard site id => the status the dashboard reports for it ("connected" when not named) */
    public array $status = [];
    /** @var array<int, list<string>> dashboard site id => what mends it: any of "sync", "reconnect", "admin" (pointed at an administrator), "plugin" (MainWP Child switched on again on the site) */
    public array $mendedBy = [];
    /** @var array<int, list<string>> dashboard site id => what has been done to it so far */
    private array $done = [];
    /** Set by a test when MainWP Child has been switched on again on the site. */
    public bool $pluginOn = false;

    public function site(int $dashboardSiteId): DashboardSite
    {
        $this->call('site ' . $dashboardSiteId);
        if ($this->readRefuses !== null) {
            throw $this->readRefuses;
        }
        $this->known($dashboardSiteId);
        return $this->row($dashboardSiteId);
    }

    private function row(int $id): DashboardSite
    {
        $status = $this->status[$id] ?? 'connected';
        return new DashboardSite($id, '', null, $status, $status === 'disconnected' ? null : '2026-11-10T00:00:05', $status === 'disconnected' ? 'MainWP Child plugin not detected or could not be reached' : '');
    }

    /** A step was taken on a broken site: it is connected again once every step it needs has been taken. */
    private function step(int $id, string $step): void
    {
        $this->done[$id][] = $step;
        $needs = $this->mendedBy[$id] ?? null;
        if ($needs !== null && ($this->status[$id] ?? '') === 'disconnected') {
            $have = array_merge($this->done[$id], $this->pluginOn ? ['plugin'] : []);
            if (array_diff($needs, $have) === []) {
                $this->status[$id] = 'connected';
            }
        }
    }

    private function known(int $dashboardSiteId): void
    {
        if (in_array($dashboardSiteId, $this->unknown, true)) {
            throw new DashboardFailure(DashboardFailure::NOT_FOUND, 'The dashboard does not know site ' . $dashboardSiteId . '.');
        }
    }

    public function syncSite(int $dashboardSiteId): DashboardSite
    {
        $this->call('syncSite ' . $dashboardSiteId);
        $this->known($dashboardSiteId);
        if (isset($this->syncFails[$dashboardSiteId])) {
            throw $this->syncFails[$dashboardSiteId];
        }
        if (isset($this->pointedAt[$dashboardSiteId], $this->syncRefuses[$this->pointedAt[$dashboardSiteId]])) {
            throw $this->syncRefuses[$this->pointedAt[$dashboardSiteId]];
        }
        if (isset($this->canConnectAs[$dashboardSiteId], $this->pointedAt[$dashboardSiteId])) {
            $this->status[$dashboardSiteId] = in_array($this->pointedAt[$dashboardSiteId], $this->canConnectAs[$dashboardSiteId], true) ? 'connected' : 'disconnected';
        }
        $this->step($dashboardSiteId, 'sync');
        if (($this->status[$dashboardSiteId] ?? 'connected') === 'disconnected') {
            // MainWP answers a failed sync with success 0 and no reason (bench, sixth run).
            throw new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to sync site ' . $dashboardSiteId . ': it gave no reason.');
        }
        return $this->row($dashboardSiteId);
    }

    public function findClient(int $whmcsClientId): ?int
    {
        $this->call('findClient ' . $whmcsClientId);
        return $this->clients[$whmcsClientId] ?? null;
    }

    /** Thrown by createClient alone when set: MainWP refuses the client (its email is taken, say). */
    public ?\Throwable $clientRefuses = null;

    public function createClient(int $whmcsClientId, string $name, string $email): int
    {
        $this->call('createClient ' . $whmcsClientId . ' ' . $name . ' ' . $email);
        if ($this->clientRefuses !== null) {
            throw $this->clientRefuses;
        }
        return $this->clients[$whmcsClientId] = $this->next++;
    }

    /** @var list<Login> the logins addSite was given */
    public array $added = [];

    public function addSite(int $dashboardClientId, string $url, Login $login): int
    {
        $this->added[] = $login;
        $this->call('addSite ' . $dashboardClientId . ' ' . $url . ' ' . $login->username);
        if ($this->addRefuses !== null) {
            throw $this->addRefuses;
        }
        $this->pointedAt[$this->next] = $login->username;
        return $this->next++;
    }

    /** Thrown by reconnectSite alone when set. */
    public ?\Throwable $reconnectRefuses = null;

    public function reconnectSite(int $dashboardSiteId): void
    {
        $this->call('reconnectSite ' . $dashboardSiteId);
        $this->known($dashboardSiteId);
        if ($this->reconnectRefuses !== null) {
            throw $this->reconnectRefuses;
        }
        $this->step($dashboardSiteId, 'reconnect');
        if (($this->status[$dashboardSiteId] ?? 'connected') === 'disconnected') {
            throw new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to reconnect site ' . $dashboardSiteId . ': Failed to reconnect to the site. Please remove the site and add it again.');
        }
    }

    /** @var array<int, list<string>> dashboard site id => its administrators' logins, in the order administrators() gives them; createAdministrator adds to it */
    public array $admins = [];
    /** Thrown by administrators() alone when set. */
    public ?\Throwable $adminsRefuse = null;

    /** As MainWP: a site whose last sync failed is left out before the site is asked (HTTP 500 no_website_found, bench eighth run). */
    private function reachable(int $dashboardSiteId, string $what): void
    {
        if (($this->status[$dashboardSiteId] ?? 'connected') === 'disconnected') {
            throw new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to ' . $what . ': No website found (MainWP answers so for a site whose last sync failed or that is suspended).');
        }
    }

    public function administrators(int $dashboardSiteId): array
    {
        $this->call('administrators ' . $dashboardSiteId);
        $this->known($dashboardSiteId);
        $this->reachable($dashboardSiteId, 'list the administrators of site ' . $dashboardSiteId);
        if ($this->adminsRefuse !== null) {
            throw $this->adminsRefuse;
        }
        return $this->admins[$dashboardSiteId] ?? [];
    }

    /** @var list<array{site: int, login: Login, email: string, firstName: string, lastName: string}> every administrator createAdministrator was asked to make, made or not */
    public array $created = [];
    /** @var list<\Throwable> thrown by createAdministrator, one per call in order, before it makes anything; when none is left it makes the account */
    public array $createRefuses = [];

    public function createAdministrator(int $dashboardSiteId, Login $account, string $email, string $firstName, string $lastName): void
    {
        $this->created[] = ['site' => $dashboardSiteId, 'login' => $account, 'email' => $email, 'firstName' => $firstName, 'lastName' => $lastName];
        $this->call('createAdministrator ' . $dashboardSiteId . ' ' . $account->username . ' ' . $email . ' ' . $firstName . ' ' . $lastName);
        $this->known($dashboardSiteId);
        $what = 'make administrator ' . $account->username . ' on site ' . $dashboardSiteId;
        $this->reachable($dashboardSiteId, $what);
        if ($this->createRefuses !== []) {
            throw array_shift($this->createRefuses);
        }
        if (in_array($account->username, $this->admins[$dashboardSiteId] ?? [], true)) {
            throw new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to ' . $what . ': ERROR: Sorry, that username already exists!');
        }
        $this->admins[$dashboardSiteId][] = $account->username;
        if (isset($this->canConnectAs[$dashboardSiteId]) && !in_array($account->username, $this->neverConnectsAs, true)) {
            $this->canConnectAs[$dashboardSiteId][] = $account->username;
        }
    }

    /** @var array<int, string> dashboard site id => the administrator setSiteAdmin last pointed it at */
    public array $pointedAt = [];
    /**
     * @var array<int, list<string>> dashboard site id => the administrators the dashboard can connect as;
     * when named, a sync connects the site exactly when it is pointed at one of them (createAdministrator adds to it)
     */
    public array $canConnectAs = [];
    /** @var array<string, \Throwable> login => what setSiteAdmin throws when pointed at it */
    public array $adminRefuses = [];
    /** @var array<string, \Throwable> login => what syncSite throws while the site is pointed at it */
    public array $syncRefuses = [];
    /** @var list<string> logins createAdministrator makes that the dashboard still cannot connect as */
    public array $neverConnectsAs = [];

    public function setSiteAdmin(int $dashboardSiteId, string $adminLogin): void
    {
        $this->call('setSiteAdmin ' . $dashboardSiteId . ' ' . $adminLogin);
        $this->known($dashboardSiteId);
        if (isset($this->adminRefuses[$adminLogin])) {
            throw $this->adminRefuses[$adminLogin];
        }
        $this->pointedAt[$dashboardSiteId] = $adminLogin;
        $this->step($dashboardSiteId, 'admin');
    }

    public function suspendSite(int $dashboardSiteId): void
    {
        $this->call('suspendSite ' . $dashboardSiteId);
        $this->known($dashboardSiteId);
    }

    public function resumeSite(int $dashboardSiteId): void
    {
        $this->call('resumeSite ' . $dashboardSiteId);
        $this->known($dashboardSiteId);
    }

    /** What MainWP says when it has removed a site; a normal removal switches its plugin off on the site. */
    public string $removeSays = 'The site has been removed and the MainWP Child plugin has been disabled';
    /** Thrown by removeSite alone when set; with $dropsWhenItRefuses the dashboard no longer holds the site afterwards. */
    public ?\Throwable $removeRefuses = null;
    public bool $dropsWhenItRefuses = false;

    public function removeSite(int $dashboardSiteId): string
    {
        $this->call('removeSite ' . $dashboardSiteId);
        $this->known($dashboardSiteId);
        if ($this->removeRefuses !== null) {
            if ($this->dropsWhenItRefuses) {
                $this->unknown[] = $dashboardSiteId;
            }
            throw $this->removeRefuses;
        }
        return $this->removeSays;
    }

    private function call(string $call): void
    {
        $this->calls[] = $call;
        if ($this->offline !== null) {
            throw $this->offline;
        }
    }
}

final class RecordingSite implements Site
{
    /** @var list<string> */
    public array $calls = [];
    /** The WordPress login name the site reports for the account; null means the name typed. */
    public ?string $loginName = null;
    public ?\Throwable $refuses = null;
    /** What the site's public index says; an exception is thrown instead. */
    public SiteIndex|\Throwable|null $index = null;

    public function index(string $url): SiteIndex
    {
        $this->calls[] = 'index ' . $url;
        if ($this->index instanceof \Throwable) {
            throw $this->index;
        }
        return $this->index ?? new SiteIndex($url . '/wp-json/', $url . '/wp-admin/authorize-application.php');
    }

    /** Run while the plugin is being installed on the password route: what a page loaded at that moment would see. */
    public ?\Closure $during = null;

    public function installPlugin(string $url, Login $login): string
    {
        $this->calls[] = 'installPlugin ' . $url . ' ' . $login->username;
        if ($this->during !== null) {
            ($this->during)();
        }
        if ($this->refuses !== null) {
            throw $this->refuses;
        }
        return $this->loginName ?? $login->username;
    }

    /** @var list<array{key: Login, care: Login, email: string, name: string}> what installPluginWithKey was given */
    public array $keyed = [];
    /** Thrown by removeAccount and by deleteKey when set. */
    public ?\Throwable $accountStays = null;
    public ?\Throwable $keyStays = null;

    public function installPluginWithKey(string $url, Login $key, Login $care, string $careEmail, string $careName): string
    {
        $this->keyed[] = ['key' => $key, 'care' => $care, 'email' => $careEmail, 'name' => $careName];
        $this->calls[] = 'installPluginWithKey ' . $url . ' ' . $key->username . ' ' . $care->username . ' ' . $careEmail;
        if ($this->refuses !== null) {
            throw $this->refuses;
        }
        return $care->username;
    }

    public function removeAccount(string $url, Login $key, string $username): void
    {
        $this->calls[] = 'removeAccount ' . $url . ' ' . $key->username . ' ' . $username;
        if ($this->accountStays !== null) {
            throw $this->accountStays;
        }
    }

    public function deleteKey(string $url, Login $key): void
    {
        $this->calls[] = 'deleteKey ' . $url . ' ' . $key->username;
        if ($this->keyStays !== null) {
            throw $this->keyStays;
        }
    }
}

/**
 * A site whose address only sends visitors on to $canonical, where the real site answers. $hops,
 * when given, names where single addresses lead instead (address => the address it redirects to),
 * for a site that redirects more than once.
 */
final class MovedSite implements Site
{
    /** @var list<string> */
    public array $calls = [];

    /** @param array<string, string> $hops */
    public function __construct(private readonly string $canonical, private readonly array $hops = [])
    {
    }

    /** The real site client reads the home page first and stops there: nothing is sent to an address that only redirects. */
    private function answersAt(string $url): void
    {
        if ($url !== $this->canonical) {
            $to = $this->hops[$url] ?? $this->canonical;
            throw new SiteFailure(SiteFailure::MOVED, 'The site at ' . parse_url($url, PHP_URL_HOST) . ' sends visitors on to ' . parse_url($to, PHP_URL_HOST) . '.', $to);
        }
    }

    public function index(string $url): SiteIndex
    {
        $this->calls[] = 'index ' . $url;
        $this->answersAt($url);
        return new SiteIndex($url . '/wp-json/', $url . '/wp-admin/authorize-application.php');
    }

    public function installPlugin(string $url, Login $login): string
    {
        $this->calls[] = 'installPlugin ' . $url . ' ' . $login->username;
        $this->answersAt($url);
        return $login->username;
    }

    public function installPluginWithKey(string $url, Login $key, Login $care, string $careEmail, string $careName): string
    {
        throw new \LogicException('not used');
    }

    /** What an earlier key attempt left cannot be removed at an address that only redirects. */
    public function removeAccount(string $url, Login $key, string $username): void
    {
        $this->calls[] = 'removeAccount ' . $url . ' ' . $key->username . ' ' . $username;
        throw new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
    }

    public function deleteKey(string $url, Login $key): void
    {
        $this->calls[] = 'deleteKey ' . $url . ' ' . $key->username;
        throw new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
    }
}

/**
 * A site whose bare domain sends every path on to its "www." form, where WordPress answers at the
 * main address only: a folder with no WordPress of its own, behind a redirect (both reviews of 0.2.1).
 */
final class WwwMainSite implements Site
{
    /** @var list<string> */
    public array $calls = [];

    private function answersAt(string $url): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!str_starts_with($host, 'www.')) {
            $to = 'https://www.' . $host . rtrim((string) parse_url($url, PHP_URL_PATH), '/');
            throw new SiteFailure(SiteFailure::MOVED, 'The site at ' . $host . ' sends visitors on to www.' . $host . '.', $to);
        }
    }

    public function index(string $url): SiteIndex
    {
        $this->calls[] = 'index ' . $url;
        $this->answersAt($url);
        return new SiteIndex('https://www.clientsite.example/wp-json/', 'https://www.clientsite.example/wp-admin/authorize-application.php', false, 'https://www.clientsite.example', 'https://www.clientsite.example');
    }

    public function installPlugin(string $url, Login $login): string
    {
        $this->calls[] = 'installPlugin ' . $url . ' ' . $login->username;
        $this->answersAt($url);
        return $login->username;
    }

    public function installPluginWithKey(string $url, Login $key, Login $care, string $careEmail, string $careName): string
    {
        throw new \LogicException('not used');
    }

    public function removeAccount(string $url, Login $key, string $username): void
    {
        throw new \LogicException('not used');
    }

    public function deleteKey(string $url, Login $key): void
    {
        throw new \LogicException('not used');
    }
}

final class MemoryStorage implements Storage
{
    /** The name of the care add-on, for every add-on row. */
    public string $serviceName = 'WordPress Care';

    public function serviceName(int $addonRowId): string
    {
        return $this->serviceName;
    }

    /** @var array<int, CareSite> */
    public array $sites = [];
    /** @var array<int, Login> */
    public array $logins = [];
    /** @var list<LogEntry> */
    public array $log = [];

    public function site(int $addonRowId): ?CareSite
    {
        return $this->sites[$addonRowId] ?? null;
    }

    public function sites(): array
    {
        return array_values($this->sites);
    }

    public function saveSite(CareSite $site): void
    {
        $this->sites[$site->addonRowId] = $site;
    }

    public function saveLogin(int $addonRowId, Login $login): void
    {
        $this->logins[$addonRowId] = $login;
    }

    public function login(int $addonRowId): ?Login
    {
        return $this->logins[$addonRowId] ?? null;
    }

    public function deleteLogin(int $addonRowId): void
    {
        unset($this->logins[$addonRowId]);
    }

    public function log(LogEntry $entry): void
    {
        $this->log[] = $entry;
    }

    /** @var array<string, array{addonRowId: int, expiresAt: string, used: ?string, key: ?HeldKey, keyHash?: string}> token hash => approval, oldest first */
    public array $approvals = [];
    /** @var list<int> add-ons another request is working on */
    public array $locked = [];
    /** @var list<string> every lock taken and released, in order */
    public array $locks = [];

    public function saveApproval(string $tokenHash, int $addonRowId, string $expiresAt): void
    {
        $this->approvals[$tokenHash] = ['addonRowId' => $addonRowId, 'expiresAt' => $expiresAt, 'used' => null, 'key' => null];
    }

    public function approval(string $tokenHash): ?Approval
    {
        $row = $this->approvals[$tokenHash] ?? null;
        return $row === null ? null : new Approval($tokenHash, $row['addonRowId'], $row['expiresAt'], $row['used'] !== null, $row['key'] !== null, $row['keyHash'] ?? null);
    }

    public function latestApproval(int $addonRowId): ?Approval
    {
        $latest = null;
        foreach ($this->approvals as $hash => $row) {
            if ($row['addonRowId'] === $addonRowId) {
                $latest = $this->approval($hash);
            }
        }
        return $latest;
    }

    /** When set, another request claims the approval with this key first, between the engine's look and its claim. */
    public ?HeldKey $claimedFirstBy = null;

    public function claimApproval(string $tokenHash, HeldKey $key, string $at): bool
    {
        if ($this->claimedFirstBy !== null && isset($this->approvals[$tokenHash]) && $this->approvals[$tokenHash]['used'] === null) {
            $first = $this->claimedFirstBy;
            $this->claimedFirstBy = null;
            $this->claimApproval($tokenHash, $first, $at);
        }
        if (!isset($this->approvals[$tokenHash]) || $this->approvals[$tokenHash]['used'] !== null || $this->approvals[$tokenHash]['expiresAt'] <= $at) {
            return false;
        }
        $this->approvals[$tokenHash]['used'] = $at;
        $this->approvals[$tokenHash]['key'] = $key;
        $this->approvals[$tokenHash]['keyHash'] = hash('sha256', $key->key->password);
        return true;
    }

    public function heldKey(int $addonRowId): ?HeldKey
    {
        $held = null;
        foreach ($this->approvals as $row) {
            if ($row['addonRowId'] === $addonRowId && $row['key'] !== null) {
                $held = $row['key'];
            }
        }
        return $held;
    }

    public function lapseApprovals(int $addonRowId, string $at): void
    {
        foreach ($this->approvals as $hash => $row) {
            if ($row['addonRowId'] === $addonRowId && $row['used'] === null && $row['expiresAt'] > $at) {
                $this->approvals[$hash]['expiresAt'] = $at;
            }
        }
    }

    public function releaseKey(int $addonRowId): void
    {
        foreach ($this->approvals as $hash => $row) {
            if ($row['addonRowId'] === $addonRowId) {
                $this->approvals[$hash]['key'] = null;
            }
        }
    }

    public function purgeApprovals(string $before): void
    {
        $this->approvals = array_filter($this->approvals, static fn (array $row): bool => $row['key'] !== null || $row['expiresAt'] >= $before);
    }

    /** @var list<int> how long each lock() was willing to wait, in seconds */
    public array $waits = [];
    /** Run once when a lock is taken: what another request did between this one's look and its turn. */
    public ?\Closure $whileWaiting = null;

    public function lock(int $addonRowId, int $waitSeconds = 0): bool
    {
        $this->waits[] = $waitSeconds;
        if (in_array($addonRowId, $this->locked, true)) {
            return false;
        }
        if ($this->whileWaiting !== null) {
            $changed = $this->whileWaiting;
            $this->whileWaiting = null;
            $changed();
        }
        $this->locked[] = $addonRowId;
        $this->locks[] = 'lock ' . $addonRowId;
        return true;
    }

    public function unlock(int $addonRowId): void
    {
        $this->locked = array_values(array_diff($this->locked, [$addonRowId]));
        $this->locks[] = 'unlock ' . $addonRowId;
    }

    /** @var list<int> WHMCS clients another request holds */
    public array $lockedClients = [];
    /** @var list<string> each client lock taken and released, in order */
    public array $clientLocks = [];
    /** @var list<int> how long each lockClient() was willing to wait, in seconds */
    public array $clientWaits = [];
    /** Run once when a client lock is taken: what another request did while this one waited. */
    public ?\Closure $whileWaitingForClient = null;

    public function lockClient(int $whmcsClientId, int $waitSeconds = 0): bool
    {
        $this->clientWaits[] = $waitSeconds;
        if (in_array($whmcsClientId, $this->lockedClients, true)) {
            return false;
        }
        if ($this->whileWaitingForClient !== null) {
            $changed = $this->whileWaitingForClient;
            $this->whileWaitingForClient = null;
            $changed();
        }
        $this->lockedClients[] = $whmcsClientId;
        $this->clientLocks[] = 'lock client ' . $whmcsClientId;
        return true;
    }

    public function unlockClient(int $whmcsClientId): void
    {
        $this->lockedClients = array_values(array_diff($this->lockedClients, [$whmcsClientId]));
        $this->clientLocks[] = 'unlock client ' . $whmcsClientId;
    }
}

/** Secrets a test can name: tokens count up from 1 (64 characters), one care login and one password unless the test names the next ones. */
final class FixedSecrets extends Secrets
{
    public const CARE_LOGIN = 'wpcarek7m2x9ab';
    public const CARE_PASSWORD = 'CarePass0123456789abcdefABCDEF01';
    /** The care login a test draws second when it names $logins. */
    public const SECOND_LOGIN = 'wpcareab12cd34';
    public const SECOND_PASSWORD = 'SecondPass0123456789abcdefABCDEF';

    /** @var list<string> the care logins handed out next, in order; when none is left, CARE_LOGIN */
    public array $logins = [];
    /** @var list<string> the passwords handed out next, in order; when none is left, CARE_PASSWORD */
    public array $passwords = [];

    /** @var list<string> every token handed out, in order */
    public array $tokens = [];

    public function token(): string
    {
        return $this->tokens[] = str_pad((string) (count($this->tokens) + 1), 64, 'a', STR_PAD_LEFT);
    }

    public function careLogin(): string
    {
        return array_shift($this->logins) ?? self::CARE_LOGIN;
    }

    public function password(): string
    {
        return array_shift($this->passwords) ?? self::CARE_PASSWORD;
    }
}

/** A clock a test sets: 9 November 2026, 14:00 unless told otherwise. */
final class FixedClock implements Clock
{
    public function __construct(public \DateTimeImmutable $now = new \DateTimeImmutable('2026-11-09 14:00:00'))
    {
    }

    public function forward(string $by): void
    {
        $this->now = $this->now->modify('+' . $by);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
