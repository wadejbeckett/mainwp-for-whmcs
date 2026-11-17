<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

/** Names, version and the production wiring of the WHMCS layer. */
final class Module
{
    public const NAME = 'mainwp';
    /**
     * The add-on module's own name, which staff read in WHMCS's admin area: its menu entry, its page,
     * its emails and its log lines. Clients never read it: what they bought is the host's own care
     * add-on, and its name is written where they read it (ServiceName).
     */
    public const DISPLAY_NAME = 'MainWP for WHMCS';
    public const VERSION = '0.6.0';

    /** @var (\Closure(): Repository)|null tests replace the database here */
    public static ?\Closure $repositoryFactory = null;

    /** @var (\Closure(): Engine\Http)|null tests replace the network here */
    public static ?\Closure $httpFactory = null;

    /** @var (\Closure(): Engine\Site)|null tests replace the client sites here */
    public static ?\Closure $siteFactory = null;

    /** @var (\Closure(): Engine\Secrets)|null tests name the token, the care login and its password here */
    public static ?\Closure $secretsFactory = null;

    /** @var (\Closure(string): void)|null tests take the redirect here; the request then goes on */
    public static ?\Closure $redirector = null;

    /** The words before the WHMCS client id in a dashboard client's note. */
    public const NOTE_PREFIX = 'WHMCS client';

    public static function repository(): Repository
    {
        return self::$repositoryFactory !== null ? (self::$repositoryFactory)() : new CapsuleRepository();
    }

    /**
     * The care engine as WHMCS runs it: storage on the module's tables, the mode from the settings,
     * the dashboard through MainWP's REST API, client sites through WordPress's own login and REST
     * routes. In dry-run neither is ever called.
     */
    public static function engine(Repository $repo, Settings $settings, ?Encrypter $encrypter = null, string $actor = 'module', int $siteTimeout = 60): Engine\CareEngine
    {
        return self::parts($repo, $settings, $encrypter, $actor, $siteTimeout)[0];
    }

    /**
     * Asks the dashboard how many clients and sites it holds, with the saved address and key, in
     * either mode: it reads two numbers and changes nothing, and it is how a key is proved before
     * the mode is switched to live.
     */
    public static function testConnection(Repository $repo, Settings $settings, Encrypter $encrypter, string $actor): Engine\Outcome
    {
        return self::parts($repo, $settings, $encrypter, $actor, 60, true)[0]->testConnection($settings->dashboardUrl, $actor);
    }

    /** The daily check as WHMCS runs it, on the same engine, dashboard and storage. */
    public static function dailyCheck(Repository $repo, Settings $settings, ?Encrypter $encrypter = null, string $actor = 'module'): Engine\DailyCheck
    {
        [$engine, $dashboard, $storage, $clock] = self::parts($repo, $settings, $encrypter, $actor, 60);
        return new Engine\DailyCheck($engine, $dashboard, $storage, $clock, $settings->dryRun);
    }

    /** @return array{Engine\CareEngine, Engine\Dashboard, Engine\Storage, Engine\Clock} */
    private static function parts(Repository $repo, Settings $settings, ?Encrypter $encrypter, string $actor, int $siteTimeout, bool $keyInDryRun = false): array
    {
        $encrypter ??= new WhmcsEncrypter();
        $clock = new SystemClock();
        $now = static fn (): string => $clock->now()->format('Y-m-d H:i:s');
        $storage = new RepositoryStorage($repo, $encrypter, $now);
        $dashboard = new Engine\LoggedDashboard(self::dashboard($settings, $encrypter, $keyInDryRun), $storage, $clock, $actor);
        $site = self::$siteFactory !== null ? (self::$siteFactory)() : new Engine\WordPressSite(self::$httpFactory !== null ? (self::$httpFactory)() : new Engine\CurlHttp(ServiceName::NEUTRAL, $siteTimeout));
        $engine = new Engine\CareEngine($dashboard, $site, $storage, $clock, $settings->dryRun, self::$secretsFactory !== null ? (self::$secretsFactory)() : null, $settings->careEmail);
        return [$engine, $dashboard, $storage, $clock];
    }

    /**
     * Where a site sends the client's browser back to after an approval: the module's client page
     * under WHMCS's System URL, with "{token}" for the one-time token. '' when the System URL is not
     * an https:// address: WordPress refuses to send a key back over plain HTTP, and so does the module.
     */
    public static function returnAddress(Repository $repo): string
    {
        $base = $repo->systemUrl();
        return str_starts_with(strtolower($base), 'https://') ? $base . '/index.php?m=' . self::NAME . '&' . ClientPage::Q_RETURN . '={token}' : '';
    }

    /**
     * Sends the browser on and ends the request, with nothing kept in a cache and no Referer for the
     * next page (the address of a return holds the key). When output has already started the request
     * goes on and the page shows the address as a link instead.
     */
    public static function redirect(string $url): void
    {
        if (self::$redirector !== null) {
            (self::$redirector)($url);
            return;
        }
        if (headers_sent()) {
            return;
        }
        header('Location: ' . $url, true, 303);
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        exit;
    }

    /**
     * The dashboard from the settings; a key that cannot be decrypted counts as no key. In dry-run
     * the key is not decrypted at all, since nothing is sent, except for the connection test.
     */
    public static function dashboard(Settings $settings, Encrypter $encrypter, bool $keyInDryRun = false): Engine\Dashboard
    {
        $key = '';
        if ($settings->hasApiKey() && (!$settings->dryRun || $keyInDryRun)) {
            try {
                $key = $settings->apiKey($encrypter);
            } catch (\RuntimeException) {
                $key = '';
            }
        }
        $http = self::$httpFactory !== null ? (self::$httpFactory)() : new Engine\CurlHttp(self::DISPLAY_NAME);
        return new Engine\MainWpDashboard($settings->dashboardUrl, $key, $http, self::NOTE_PREFIX);
    }

    /** WHMCS's yes/no field: only an explicit yes counts. */
    public static function yes(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['on', 'yes', '1', 'true'], true);
    }
}
