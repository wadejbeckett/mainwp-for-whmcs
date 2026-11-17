<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * A client's WordPress site over HTTPS, through WordPress's own login and REST routes, as the bench
 * recorded them (context/bench-2026-11-06/RESULTS.md). The password route: read the home page for
 * the REST root, open the login page and stop before sending the password if it shows a captcha,
 * a lockout or a code field; log in once (never twice: login limiters count every try); get the
 * REST nonce; check the account is an administrator; install and activate MainWP Child through
 * the REST plugins route; log out. The key route: the same REST routes with the approved key as
 * HTTP Basic authentication and no session; the care account made, found again or removed through
 * the REST users route; the key deleted by its own identifier. Every refusal is a SiteFailure in
 * plain words, never with a password or the key in it.
 */
final class WordPressSite implements Site
{
    private const PLUGIN = 'mainwp-child/mainwp-child';
    private const SLUG = 'mainwp-child';

    /** Text WordPress's login limiters show while they lock logins out (seen on a test bench). */
    private const LOCKOUT = [
        'too many failed login attempts',
        'exceeded maximum login retries',
        'temporarily locked',
        'locked out',
        'lockout',
        'has been locked',
        'has been blocked for security reasons',
        'aiowps-unlock-string-info',
    ];

    /** Markup of the login captchas in use (Google reCAPTCHA, hCaptcha, Cloudflare Turnstile, AIOS, Advanced Google reCAPTCHA, others). */
    private const CAPTCHA = [
        'g-recaptcha',
        'recaptcha/api.js',
        'h-captcha',
        'hcaptcha.com',
        'cf-turnstile',
        'challenges.cloudflare.com/turnstile',
        'frc-captcha',
        'aiowps-captcha',
        'aiowps_captcha',
        'wpcaptcha',
        'captcha_code',
        'cptch_',
    ];

    /** Fields that ask for a second-step code on the login form itself. */
    private const CODE_FIELD = ['name="authcode"', 'name="two_factor_code"', 'name="googleotp"', 'name="mfa_code"', 'name="wfls-token"'];

    /** @var array<string, string> */
    private array $cookies = [];

    /** The Authorization header of the key route, set only while a key call runs. */
    private ?string $authorization = null;

    public function __construct(private readonly Http $http)
    {
    }

    /** The public REST index: no login is used, and nothing is sent that could change the site. */
    public function index(string $url): SiteIndex
    {
        $url = $this->base($url);
        $this->cookies = [];
        $home = $this->get($url, $url . '/', 'open the home page');
        if ($home->status === 401 || $home->status === 403) {
            throw self::blocked($home->status);
        }
        self::notMoved($home, $url);
        $root = self::rootFrom($home, $url);
        // Only the fields read below: a site with many plugins has a whole index past CurlHttp::MAX_BODY.
        $answer = $this->get($url, $root . (str_contains($root, '?') ? '&' : '?') . '_fields=authentication,namespaces,home,url', 'read the REST index');
        if ($answer->status === 401 || $answer->status === 403) {
            throw self::blocked($answer->status);
        }
        $data = json_decode($answer->body, true);
        if ($answer->status !== 200 || !is_array($data)) {
            throw new SiteFailure(SiteFailure::BAD_ANSWER, 'WordPress did not answer at ' . (string) parse_url($url, PHP_URL_HOST) . ' as it should (its REST API gave HTTP ' . $answer->status . '): the address may not be a WordPress site, or that part of WordPress is switched off there. Check the address, or contact support.');
        }
        $approval = $data['authentication']['application-passwords']['endpoints']['authorization'] ?? null;
        $namespaces = is_array($data['namespaces'] ?? null) ? $data['namespaces'] : [];
        return new SiteIndex(
            $root,
            is_string($approval) && self::onSite($approval, $url) ? $approval : null,
            in_array('wordfence/v1', $namespaces, true),
            is_string($data['home'] ?? null) && $data['home'] !== '' ? $data['home'] : null,
            is_string($data['url'] ?? null) && $data['url'] !== '' ? $data['url'] : null,
        );
    }

    public function installPlugin(string $url, Login $login): string
    {
        $url = $this->base($url);
        $this->cookies = [];
        $root = $this->restRoot($url);
        $this->logIn($url, $login);
        try {
            $nonce = $this->restNonce($url);
            $name = $this->administrator($url, $root, $nonce);
            $this->installAndActivate($url, $root, $nonce);
            return $name;
        } finally {
            $this->logOut($url);
            $this->cookies = [];
        }
    }

    public function installPluginWithKey(string $url, Login $key, Login $care, string $careEmail, string $careName): string
    {
        return $this->withKey($url, $key, function (string $url, string $root) use ($care, $careEmail, $careName): string {
            $approver = $this->me($url, $root, null);
            $account = $this->ensureAccount($url, $root, $care, $careEmail, $careName);
            try {
                $this->installAndActivate($url, $root, null);
            } catch (SiteFailure $failure) {
                if ($failure->kind !== SiteFailure::INSTALL_FORBIDDEN && $failure->kind !== SiteFailure::INSTALL_FAILED) {
                    throw $failure;
                }
                try {
                    $this->deleteAccount($url, $root, $account['id'], $approver['id'], $account['name']);
                } catch (SiteFailure) {
                    throw new SiteFailure($failure->kind, $failure->getMessage() . ' The account ' . $account['name'] . ' made for care could not be removed again; delete it under Users in the site\'s dashboard.');
                }
                throw $failure;
            }
            return $account['name'];
        });
    }

    public function removeAccount(string $url, Login $key, string $username): void
    {
        if (preg_match('/^' . Secrets::CARE_LOGIN_PREFIX . '[a-z0-9-]+$/', $username) !== 1) {
            throw new \InvalidArgumentException('Only an account the module made for care (its name begins "' . Secrets::CARE_LOGIN_PREFIX . '") is ever removed.');
        }
        $this->withKey($url, $key, function (string $url, string $root) use ($username): void {
            $approver = $this->me($url, $root, null);
            $id = $this->accountId($url, $root, $username);
            if ($id !== null && $id !== $approver['id']) {
                $this->deleteAccount($url, $root, $id, $approver['id'], $username);
            }
        });
    }

    public function deleteKey(string $url, Login $key): void
    {
        $this->withKey($url, $key, function (string $url, string $root): void {
            $answer = $this->get($url, self::route($root, 'wp/v2/users/me/application-passwords/introspect'), 'find the key');
            $uuid = self::json($answer, 'find the key')['uuid'] ?? null;
            if (!is_string($uuid) || preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/i', $uuid) !== 1) {
                throw new SiteFailure(SiteFailure::BAD_ANSWER, 'The site did not say which key is in use, so we could not delete it.');
            }
            $answer = $this->send('DELETE', $url, self::route($root, 'wp/v2/users/me/application-passwords/' . $uuid));
            if ($answer->status === 404) {
                return;
            }
            if ((self::json($answer, 'delete the key')['deleted'] ?? null) !== true) {
                throw new SiteFailure(SiteFailure::BAD_ANSWER, 'The site did not confirm that the key was deleted.');
            }
        });
    }

    /**
     * Runs $work with the key as the Authorization header. The home page is read first, without the
     * key, for the REST root, which is only taken when it is on the site's own host: the key is
     * sent to the site itself and nowhere else.
     *
     * @template T
     * @param callable(string, string): T $work given the address and the REST root
     * @return T
     */
    private function withKey(string $url, Login $key, callable $work): mixed
    {
        $url = $this->base($url);
        $this->cookies = [];
        $this->authorization = null;
        $root = $this->restRoot($url);
        $this->authorization = 'Basic ' . base64_encode($key->username . ':' . $key->password);
        try {
            return $work($url, $root);
        } finally {
            $this->authorization = null;
        }
    }

    /**
     * The care account, with the display name $name: made new, or, when its login name is already there from an earlier attempt,
     * found and given the password again so that the dashboard can log in with it. When another
     * account holds the address, it is made once more with the address plus-tagged with its login.
     *
     * @return array{id: int, name: string}
     */
    private function ensureAccount(string $url, string $root, Login $care, string $email, string $name): array
    {
        $make = fn (string $email): HttpResponse => $this->post($url, self::route($root, 'wp/v2/users'), ['username' => $care->username, 'email' => $email, 'password' => $care->password, 'roles' => 'administrator', 'name' => $name], null, 'make the care account');
        $answer = $make($email);
        $data = json_decode($answer->body, true);
        if (is_array($data) && ($data['code'] ?? null) === 'existing_user_email') {
            $answer = $make(Secrets::plusTagged($email, $care->username));
            $data = json_decode($answer->body, true);
        }
        if ($answer->status === 201 && is_array($data) && is_numeric($data['id'] ?? null)) {
            return ['id' => (int) $data['id'], 'name' => is_string($data['username'] ?? null) && $data['username'] !== '' ? $data['username'] : $care->username];
        }
        if (is_array($data) && ($data['code'] ?? null) === 'existing_user_login') {
            $id = $this->accountId($url, $root, $care->username);
            if ($id !== null) {
                $answer = $this->post($url, self::route($root, 'wp/v2/users/' . $id), ['password' => $care->password, 'roles' => 'administrator'], null, 'reset the care account');
                $data = json_decode($answer->body, true);
                if ($answer->status === 200 && is_array($data)) {
                    return ['id' => $id, 'name' => $care->username];
                }
            }
        }
        throw new SiteFailure(SiteFailure::ACCOUNT_REFUSED, 'Your site did not let us create the administrator account for care (it said: ' . self::said($answer, $care->password) . '). Please contact support.');
    }

    /** The id of the account with this login name, or null when the site has none. */
    private function accountId(string $url, string $root, string $username): ?int
    {
        $list = self::json($this->get($url, self::route($root, 'wp/v2/users', 'context=edit&slug=' . rawurlencode($username)), 'look for the care account'), 'look for the care account');
        foreach ($list as $user) {
            if (is_array($user) && ($user['username'] ?? $user['slug'] ?? null) === $username && is_numeric($user['id'] ?? null)) {
                return (int) $user['id'];
            }
        }
        return null;
    }

    /** WordPress deletes a user only with force=true and a user to give its content to; an account already gone is done. */
    private function deleteAccount(string $url, string $root, int $id, int $reassignTo, string $username): void
    {
        $answer = $this->send('DELETE', $url, self::route($root, 'wp/v2/users/' . $id, 'force=true&reassign=' . $reassignTo));
        if ($answer->status === 404) {
            return;
        }
        $data = json_decode($answer->body, true);
        if ($answer->status !== 200 || !is_array($data) || ($data['deleted'] ?? null) !== true) {
            throw new SiteFailure(SiteFailure::ACCOUNT_REFUSED, 'The account ' . $username . ' made for care could not be removed: ' . self::said($answer) . '.');
        }
    }

    private function base(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (!str_starts_with(strtolower($url), 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new SiteFailure(SiteFailure::NOT_HTTPS, 'The site address must be an https:// address: the login is never sent over plain HTTP. Switch the site to HTTPS, or contact support.');
        }
        return $url;
    }

    /** The REST root from the home page's Link header (rel="https://api.w.org/") when it is on the site's own host, or /wp-json/. */
    private function restRoot(string $url): string
    {
        $home = $this->get($url, $url . '/', 'open the home page');
        self::notMoved($home, $url);
        return self::rootFrom($home, $url);
    }

    /**
     * A home page that redirects to another host means the site lives at that address (a server that
     * prefers the "www." form answers every address this way): nothing more is sent here. A redirect
     * within the host (a language, a landing page) is not a move.
     */
    private static function notMoved(HttpResponse $home, string $url): void
    {
        if (!in_array($home->status, [301, 302, 303, 307, 308], true)) {
            return;
        }
        $target = (string) $home->header('location');
        $from = strtolower((string) parse_url($url, PHP_URL_HOST));
        $to = strtolower((string) parse_url($target, PHP_URL_HOST));
        if ($to === '' || $to === $from) {
            return;
        }
        $scheme = str_starts_with(strtolower($target), 'https://') ? 'https' : 'http';
        throw new SiteFailure(SiteFailure::MOVED, 'The site at ' . $from . ' sends visitors on to ' . $to . '.', $scheme . '://' . $to . rtrim((string) parse_url($url, PHP_URL_PATH), '/'));
    }

    private static function rootFrom(HttpResponse $home, string $url): string
    {
        foreach ($home->headers('link') as $line) {
            foreach (explode(',', $line) as $link) {
                if (preg_match('~<([^>]+)>\s*;\s*rel="https://api\.w\.org/"~', $link, $m) === 1 && self::onSite($m[1], $url)) {
                    return $m[1];
                }
            }
        }
        return $url . '/wp-json/';
    }

    private function logIn(string $url, Login $login): void
    {
        $page = $this->get($url, $url . '/wp-login.php', 'open the login page');
        $this->loginPageFailure($url, $page);
        try {
            $answer = $this->http->request('POST', $url . '/wp-login.php', $this->headers(), [
                'log' => $login->username,
                'pwd' => $login->password,
                'redirect_to' => $url . '/wp-admin/',
                'testcookie' => '1',
            ]);
        } catch (\RuntimeException $e) {
            throw $this->unreachable($url, $e, $login->password);
        }
        $this->keepCookies($answer);
        if ($this->loggedIn()) {
            return;
        }
        $text = strtolower($answer->body);
        if (self::has($text, self::LOCKOUT)) {
            throw self::lockedOut();
        }
        if ($answer->status === 401 || $answer->status === 403 || $answer->status === 406 || $answer->status === 429 || $answer->status === 503) {
            throw self::blocked($answer->status);
        }
        if (str_contains($text, 'id="login_error"')) {
            $counted = preg_match('~attempt\(s\) left|attempts? remaining|attempts? left~', $text) === 1;
            throw new SiteFailure(SiteFailure::LOGIN_REFUSED, 'The username or password was not accepted by the site. Check them and try again.' . ($counted ? ' The site limits failed logins, so check carefully: a few more wrong tries lock our server out for a while.' : ''));
        }
        if (self::has($text, self::CAPTCHA)) {
            throw self::captcha();
        }
        throw new SiteFailure(SiteFailure::TWO_FACTOR, 'The site asks for a second step after the password (two-factor login), so we cannot log in. Use an administrator account without two-factor login, or contact support.');
    }

    /** Stops before the password is sent when the login page cannot take it. */
    private function loginPageFailure(string $url, HttpResponse $page): void
    {
        $text = strtolower($page->body);
        if ($page->status === 404 || ($page->status >= 300 && $page->status < 400)) {
            throw self::loginNotFound($url);
        }
        if (self::has($text, self::LOCKOUT)) {
            throw self::lockedOut();
        }
        if ($page->status !== 200) {
            throw self::blocked($page->status);
        }
        if (self::has($text, self::CAPTCHA)) {
            throw self::captcha();
        }
        if (self::has($text, self::CODE_FIELD)) {
            throw new SiteFailure(SiteFailure::TWO_FACTOR, 'The site\'s login page asks for a two-factor code, so we cannot log in. Use an administrator account without two-factor login, or contact support.');
        }
        if (!str_contains($text, 'name="pwd"')) {
            throw self::loginNotFound($url);
        }
    }

    private function restNonce(string $url): string
    {
        $answer = $this->get($url, $url . '/wp-admin/admin-ajax.php?action=rest-nonce', 'start a session');
        $nonce = trim($answer->body);
        if ($answer->status !== 200 || $nonce === '' || $nonce === '0' || preg_match('/^[a-f0-9]{6,32}$/i', $nonce) !== 1) {
            throw new SiteFailure(SiteFailure::BAD_ANSWER, 'The site accepted the login but gave us no session (HTTP ' . $answer->status . '); a plugin may be interfering. Contact support.');
        }
        return $nonce;
    }

    /** The account's login name, after checking it is an administrator. */
    private function administrator(string $url, string $root, string $nonce): string
    {
        return $this->me($url, $root, $nonce)['name'];
    }

    /**
     * The account the session or the key belongs to, after checking it is an administrator.
     *
     * @return array{id: int, name: string}
     */
    private function me(string $url, string $root, ?string $nonce): array
    {
        $answer = $this->get($url, self::route($root, 'wp/v2/users/me', 'context=edit'), 'read the account', $nonce);
        $me = self::json($answer, 'read the account');
        $name = is_string($me['username'] ?? null) ? $me['username'] : '';
        $roles = is_array($me['roles'] ?? null) ? $me['roles'] : [];
        if ($name === '' || !is_numeric($me['id'] ?? null)) {
            throw new SiteFailure(SiteFailure::BAD_ANSWER, 'The site\'s REST API did not say which account is logged in. Contact support.');
        }
        if (!in_array('administrator', $roles, true)) {
            throw new SiteFailure(SiteFailure::NOT_ADMIN, 'The account ' . $name . ' is not an administrator of the site. Use an administrator account.');
        }
        return ['id' => (int) $me['id'], 'name' => $name];
    }

    private function installAndActivate(string $url, string $root, ?string $nonce): void
    {
        $list = self::json($this->get($url, self::route($root, 'wp/v2/plugins', 'search=' . self::SLUG), 'list the plugins', $nonce), 'list the plugins');
        $status = null;
        foreach ($list as $plugin) {
            if (is_array($plugin) && ($plugin['plugin'] ?? null) === self::PLUGIN) {
                $status = is_string($plugin['status'] ?? null) ? $plugin['status'] : 'inactive';
            }
        }
        if ($status === 'active' || $status === 'network-active') {
            return;
        }
        if ($status === null) {
            $answer = $this->post($url, self::route($root, 'wp/v2/plugins'), ['slug' => self::SLUG, 'status' => 'active'], $nonce, 'install the plugin');
            $result = json_decode($answer->body, true);
            if ($answer->status === 403 && is_array($result) && ($result['code'] ?? null) === 'rest_cannot_install_plugin') {
                throw new SiteFailure(SiteFailure::INSTALL_FORBIDDEN, 'The site does not allow plugins to be installed from its dashboard (file changes are switched off). Contact support.');
            }
            if ($answer->status !== 201 || !is_array($result)) {
                throw self::installFailed('installed', $answer);
            }
            if (($result['status'] ?? null) === 'active') {
                return;
            }
        }
        $answer = $this->post($url, self::route($root, 'wp/v2/plugins/' . self::PLUGIN), ['status' => 'active'], $nonce, 'activate the plugin');
        $result = json_decode($answer->body, true);
        if ($answer->status !== 200 || !is_array($result) || ($result['status'] ?? null) !== 'active') {
            throw self::installFailed('activated', $answer);
        }
    }

    /** WordPress's logout: its confirmation page holds the logout link with its nonce. A failure here changes nothing. */
    private function logOut(string $url): void
    {
        if (!$this->loggedIn()) {
            return;
        }
        try {
            $page = $this->http->request('GET', $url . '/wp-login.php?action=logout', $this->headers());
            // Should the page come back as JSON all the same, the question is in its message.
            $said = json_decode($page->body, true);
            $body = is_array($said) && is_string($said['message'] ?? null) ? $said['message'] : $page->body;
            if (preg_match('~href="([^"]*action=logout(?:&amp;|&)_wpnonce=[A-Za-z0-9]+)"~', $body, $m) === 1) {
                $link = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_starts_with($link, $url . '/')) {
                    $this->http->request('GET', $link, $this->headers());
                }
            }
        } catch (\RuntimeException) {
            // The session ends by itself; nothing else depends on the logout.
        }
    }

    private function get(string $url, string $target, string $what, ?string $nonce = null): HttpResponse
    {
        return $this->send('GET', $url, $target, [], $nonce);
    }

    /** @param array<string, string> $form */
    private function post(string $url, string $target, array $form, ?string $nonce, string $what): HttpResponse
    {
        return $this->send('POST', $url, $target, $form, $nonce);
    }

    /**
     * One request to the site. On the key route, an HTTP 401 is told apart here: from WordPress
     * itself the key was not accepted, from anything in front of WordPress an HTTP login stops the request.
     *
     * @param array<string, string> $form
     */
    private function send(string $method, string $url, string $target, array $form = [], ?string $nonce = null): HttpResponse
    {
        $secrets = array_values(array_filter($form, static fn (string $value, string $name): bool => $name === 'password' || $name === 'pwd', ARRAY_FILTER_USE_BOTH));
        try {
            $answer = $this->http->request($method, $target, $this->headers($nonce), $form);
        } catch (\RuntimeException $e) {
            throw $this->unreachable($url, $e, ...$secrets);
        }
        $this->keepCookies($answer);
        if ($this->authorization !== null && $answer->status === 401) {
            $data = json_decode($answer->body, true);
            if ($answer->header('www-authenticate') !== null || !is_array($data) || !is_string($data['code'] ?? null)) {
                throw self::blocked($answer->status);
            }
            throw new SiteFailure(
                SiteFailure::KEY_REFUSED,
                'Your site did not accept the connection you approved. Please approve it again; if this message comes back, connect with an administrator username and password instead, or contact support.',
                null,
                'HTTP 401 from WordPress itself: the key is no longer on the site, or the server does not pass the Authorization header on to WordPress (WordPress\'s Site Health screen reports that)',
            );
        }
        return $answer;
    }

    /** @return array<string, string> */
    private function headers(?string $nonce = null): array
    {
        // A page is asked for as a browser asks: to a client that accepts JSON, WordPress answers its
        // own notices (the logout question among them) in JSON. REST routes are asked for as JSON.
        $headers = ['Accept' => $nonce !== null || $this->authorization !== null ? 'application/json' : 'text/html'];
        if ($this->authorization !== null) {
            // A key needs no session: no cookie and no nonce go with it.
            $headers['Authorization'] = $this->authorization;
            return $headers;
        }
        $pairs = [];
        foreach ($this->cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $pairs[] = 'wordpress_test_cookie=WP%20Cookie%20check';
        $headers['Cookie'] = implode('; ', array_unique($pairs));
        if ($nonce !== null) {
            $headers['X-WP-Nonce'] = $nonce;
        }
        return $headers;
    }

    private function keepCookies(HttpResponse $answer): void
    {
        foreach ($answer->headers('set-cookie') as $line) {
            $parts = array_map('trim', explode(';', $line));
            $pair = explode("=", (string) array_shift($parts), 2);
            if (count($pair) !== 2 || $pair[0] === '') {
                continue;
            }
            $expired = $pair[1] === '' || $pair[1] === 'deleted';
            foreach ($parts as $attribute) {
                if (strcasecmp($attribute, 'Max-Age=0') === 0 || (stripos($attribute, 'expires=') === 0 && ($t = strtotime(substr($attribute, 8))) !== false && $t < time())) {
                    $expired = true;
                }
            }
            if ($expired) {
                unset($this->cookies[$pair[0]]);
            } else {
                $this->cookies[$pair[0]] = $pair[1];
            }
        }
    }

    private function loggedIn(): bool
    {
        foreach (array_keys($this->cookies) as $name) {
            if (str_starts_with($name, 'wordpress_logged_in_')) {
                return true;
            }
        }
        return false;
    }

    /** Is $address an https:// address on the host of $url (with or without "www."), on the standard port? Nothing is sent to any other. */
    private static function onSite(string $address, string $url): bool
    {
        $host = static fn (string $u): string => (string) preg_replace('/^www\./', '', strtolower((string) parse_url($u, PHP_URL_HOST)));
        return str_starts_with(strtolower($address), 'https://') && filter_var($address, FILTER_VALIDATE_URL) !== false && parse_url($address, PHP_URL_PORT) === null && $host($address) !== '' && $host($address) === $host($url);
    }

    /** A REST route under the root, whether the root is /wp-json/ or ?rest_route=/. */
    private static function route(string $root, string $route, string $query = ''): string
    {
        if (str_contains($root, 'rest_route=')) {
            return rtrim($root, '/') . '/' . $route . ($query !== '' ? '&' . $query : '');
        }
        return rtrim($root, '/') . '/' . $route . ($query !== '' ? '?' . $query : '');
    }

    /** @return array<array-key, mixed> */
    private static function json(HttpResponse $answer, string $what): array
    {
        $data = json_decode($answer->body, true);
        if ($answer->status === 401 || $answer->status === 403) {
            throw new SiteFailure(SiteFailure::BAD_ANSWER, 'The site\'s REST API refused the logged-in account (HTTP ' . $answer->status . '), so we could not ' . $what . '. A security plugin may block the REST API; contact support.');
        }
        if ($answer->status !== 200 || !is_array($data)) {
            throw new SiteFailure(SiteFailure::BAD_ANSWER, 'The site\'s REST API did not answer as WordPress does (HTTP ' . $answer->status . '), so we could not ' . $what . '. Contact support.');
        }
        return $data;
    }

    /** @param list<string> $markers */
    private static function has(string $text, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }
        return false;
    }

    private function unreachable(string $url, \RuntimeException $e, string ...$secrets): SiteFailure
    {
        if ($e instanceof HttpTimeout) {
            return new SiteFailure(SiteFailure::UNREACHABLE, 'The site at ' . (string) parse_url($url, PHP_URL_HOST) . ' did not answer in time (' . rtrim(LogText::clean($e->getMessage(), ...$secrets), '.') . '). Try again later.');
        }
        if ($e instanceof HttpTooLarge) {
            return new SiteFailure(SiteFailure::UNREACHABLE, 'The site at ' . (string) parse_url($url, PHP_URL_HOST) . ' sent an answer larger than 2 MB, so it was not read.');
        }
        return new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached at ' . (string) parse_url($url, PHP_URL_HOST) . ' (' . rtrim(LogText::clean($e->getMessage(), ...$secrets), '.') . '). Check the address and try again.');
    }

    private static function loginNotFound(string $url): SiteFailure
    {
        return new SiteFailure(SiteFailure::LOGIN_NOT_FOUND, 'WordPress\'s login page was not found at ' . (string) parse_url($url, PHP_URL_HOST) . '/wp-login.php; a security plugin may have moved it. Contact support to connect the site.');
    }

    private static function lockedOut(): SiteFailure
    {
        return new SiteFailure(SiteFailure::LIMITER, 'The site has locked out logins from our server for a while (a login limiter). Try again after the lockout ends, usually 15 minutes to a few hours.');
    }

    private static function captcha(): SiteFailure
    {
        return new SiteFailure(SiteFailure::CAPTCHA, 'The site\'s login page has a captcha, so we cannot log in. Switch the login captcha off while the site is connected, or contact support.');
    }

    private static function blocked(int $status): SiteFailure
    {
        return new SiteFailure(SiteFailure::BLOCKED, 'Something in front of WordPress turned us away (HTTP ' . $status . '): a separate HTTP login or a firewall. Please contact support.');
    }

    /** WordPress's own words for a refusal, without markup, one or two sentences at most; the HTTP status when it gave none. */
    private static function said(HttpResponse $answer, string ...$secrets): string
    {
        $data = json_decode($answer->body, true);
        if (!is_array($data) || !is_string($data['message'] ?? null) || trim($data['message']) === '') {
            return 'HTTP ' . $answer->status;
        }
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($data['message']), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $text = (string) preg_replace('/^ERROR\s*:\s*/i', '', $text);
        if (preg_match('/^(.{20,200}?[.!?])(\s|$)/u', $text, $m) === 1) {
            $text = $m[1];
        } elseif (mb_strlen($text) > 200) {
            $text = mb_substr($text, 0, 200) . '…';
        }
        return rtrim(LogText::clean($text, ...$secrets), '.');
    }

    private static function installFailed(string $verb, HttpResponse $answer): SiteFailure
    {
        $data = json_decode($answer->body, true);
        $message = is_array($data) && is_string($data['message'] ?? null) ? rtrim(LogText::clean(strip_tags($data['message'])), '.') : 'HTTP ' . $answer->status;
        return new SiteFailure(SiteFailure::INSTALL_FAILED, 'The plugin could not be ' . $verb . ' on the site: ' . $message . '. Contact support.');
    }
}
