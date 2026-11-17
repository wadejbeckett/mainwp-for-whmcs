<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\Engine\Http;
use MainWpForWhmcs\Engine\HttpResponse;
use MainWpForWhmcs\Hooks;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Tests\Support\FixtureHttp;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\SiteFixtureHttp;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

/**
 * The password route from end to end as WHMCS runs it: staff save a login on the service page, the
 * module's own site client and dashboard client answer with the exchanges recorded on the bench.
 * No HTTP leaves the test.
 */
final class PasswordRouteTest extends TestCase
{
    private const PASSWORD = "O'Brien \"x\" \\9";

    private MemoryRepository $repo;
    private SiteFixtureHttp $site;
    private FixtureHttp $dashboard;

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        $this->repo->settings = ['dry_run' => '', 'access' => '1', 'dashboard_url' => 'https://mainwp-bench-dash.test', 'api_key' => (new WhmcsEncrypter())->encrypt('bench-key-0123456789')];
        $this->repo->addonModules = [16 => 'mainwp'];
        $this->repo->adminRoles = [1 => 1];
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', 'mainwp-bench-site.test', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[67] = ['addon_row_id' => 67, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://mainwp-bench-site.test', 'dashboard_client_id' => 1, 'dashboard_site_id' => null, 'state' => 'provisioned', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null];
        $repo = $this->repo;
        Module::$repositoryFactory = static fn (): MemoryRepository => $repo;
        $this->site = new SiteFixtureHttp();
        $this->dashboard = new FixtureHttp();
        $site = $this->site;
        $dashboard = $this->dashboard;
        Module::$httpFactory = static fn (): Http => new class ($site, $dashboard) implements Http {
            public function __construct(private Http $site, private Http $dashboard)
            {
            }

            public function request(string $method, string $url, array $headers, array|string $form = []): HttpResponse
            {
                return (parse_url($url, PHP_URL_HOST) === 'mainwp-bench-dash.test' ? $this->dashboard : $this->site)->request($method, $url, $headers, $form);
            }
        };
        \WHMCS\Session::$values['adminid'] = 1;
    }

    protected function tearDown(): void
    {
        Module::$repositoryFactory = null;
        Module::$httpFactory = null;
        $_POST = [];
    }

    private function save(string $username = 'owner'): void
    {
        // As the module's page hands the Connect Site form over: WHMCS's HTML-encoding of the POST taken off once.
        \MainWpForWhmcs\StaffConnect::attempt($this->repo, 1176, 1, 67, \MainWpForWhmcs\Admin\Page::decodeInput(['address' => 'mainwp-bench-site.test', 'username' => $username, 'password' => htmlspecialchars(self::PASSWORD, ENT_QUOTES)]));
    }

    private function siteAnswersUpToTheInstall(string $install): void
    {
        // The public index first (the address must be the site's own), then the home page again for the login.
        $this->site->on('GET', '/', 'home', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index')->on('GET', '/wp-login.php', 'login-page')->on('POST', '/wp-login.php', 'login-ok')
            ->on('GET', '/wp-admin/admin-ajax.php?action=rest-nonce', 'rest-nonce')
            ->on('GET', '/wp-json/wp/v2/users/me?context=edit', 'users-me')
            ->on('GET', '/wp-json/wp/v2/plugins?search=mainwp-child', 'plugins-search-absent')
            ->on('POST', '/wp-json/wp/v2/plugins', $install)
            ->on('GET', '/wp-login.php?action=logout', 'logout-confirm')
            ->on('GET', '/wp-login.php?action=logout&_wpnonce=abcdef0123', 'logout');
    }

    public function testAPasswordWithQuotesAndABackslashConnectsTheSite(): void
    {
        $this->siteAnswersUpToTheInstall('plugin-install');
        $this->dashboard->on('POST', 'sites/add', 'sites-add-with-client');

        $this->save();

        self::assertSame(['GET /', 'GET /wp-json/?_fields=authentication,namespaces,home,url', 'GET /', 'GET /wp-login.php', 'POST /wp-login.php'], array_slice($this->site->requests(), 0, 5));
        self::assertSame(self::PASSWORD, $this->site->sent[4]['form']['pwd'], 'WordPress gets the password as typed');
        self::assertSame(['url' => 'https://mainwp-bench-site.test', 'name' => 'mainwp-bench-site.test', 'admin' => 'owner', 'adminpassword' => rawurlencode(addslashes(self::PASSWORD)), 'client_id' => '1'], $this->dashboard->sent[0]['form'], 'MainWP gets it slashed, then encoded once more (bench, second check)');
        $row = $this->repo->careSites[67];
        self::assertSame(['connected', 1], [$row['state'], $row['dashboard_site_id']]);
        self::assertNotNull($row['last_sync']);
        $all = json_encode([$this->repo->log, $row, Hooks::adminServiceFields(['id' => 1176])], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Brien', $all, 'the password is in no log, row or page');
    }

    public function testWithACareAccountEmailTheCareAccountIsMadeThroughMainWpAndTakesThePlaceOfTheLoginTyped(): void
    {
        $this->repo->settings['care_email'] = 'wordpress@bench.test';
        $this->siteAnswersUpToTheInstall('plugin-install');
        $this->dashboard->on('POST', 'sites/add', 'sites-add-with-client')
            ->on('POST', 'users/create', 'users-create-care')
            ->on('POST', 'sites/1/edit', 'site-edit-admin-care')
            ->on('POST', 'sites/1/sync', 'site-sync-as-care')
            ->on('GET', 'users?websites=1&roles=administrator', 'users-list-admins');

        $this->save();

        self::assertSame(['POST sites/add', 'POST users/create', 'POST sites/1/edit', 'POST sites/1/sync', 'GET users?websites=1&roles=administrator'], array_map(static fn (array $r): string => $r['method'] . ' ' . preg_replace('~^.*/wp-json/mainwp/v2/~', '', $r['url']), $this->dashboard->sent));
        $made = json_decode((string) $this->dashboard->sent[1]['form'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['1', 'wordpress@bench.test', 'administrator', 'WordPress', 'Care'], [$made['websites'], $made['email'], $made['role'], $made['first_name'], $made['last_name']]);
        self::assertArrayNotHasKey('send_password', $made, 'nothing is mailed');
        self::assertSame($made['username'], $this->dashboard->sent[2]['form']['admin'] ?? null, 'the dashboard is pointed at the care account');
        $row = $this->repo->careSites[67];
        self::assertSame('connected', $row['state']);
        $stored = json_decode((new WhmcsEncrypter())->decrypt((string) $row['login_encrypted']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([$made['username'], $made['password']], [$stored['username'], $stored['password']], 'the care account is the stored login; the login typed is gone');
        self::assertSame('["owner"]', $row['other_admins']);
        $all = json_encode([$this->repo->log, $row], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Brien', $all);
        self::assertStringNotContainsString($made['password'], $all);
    }

    public function testACareAccountTheHostMadeIsConnectedWithoutALoginToTheSite(): void
    {
        $this->repo->settings['care_email'] = 'wordpress@bench.test';
        $this->site->on('GET', '/', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index');
        $this->dashboard->on('POST', 'sites/add', 'sites-add-as-care-host-route')
            ->on('GET', 'users?websites=2&roles=administrator', 'users-list-admins-host-route');

        $this->save('wpcareab12cd34');

        self::assertSame(['GET /', 'GET /wp-json/?_fields=authentication,namespaces,home,url'], $this->site->requests(), 'nobody logs in to the site: only its public index is read');
        self::assertSame('wpcareab12cd34', $this->dashboard->sent[0]['form']['admin'] ?? null);
        $row = $this->repo->careSites[67];
        self::assertSame(['connected', 2, '["owner"]'], [$row['state'], $row['dashboard_site_id'], $row['other_admins']]);
    }

    public function testAnInstallRefusedOnTheSiteEndsInFailedWithARetryAndNothingOnTheDashboard(): void
    {
        $this->siteAnswersUpToTheInstall('plugin-install-forbidden');

        $this->save();

        $row = $this->repo->careSites[67];
        self::assertSame('failed', $row['state']);
        self::assertStringContainsString('does not allow plugins to be installed', (string) $row['last_error']);
        self::assertNotNull($row['retry_after']);
        self::assertSame([], $this->dashboard->sent);
        self::assertContains('GET /wp-login.php?action=logout&_wpnonce=abcdef0123', $this->site->requests(), 'logged out after the refusal');
    }

    public function testADashboardThatRefusesTheLoginEndsInFailedWithMainWpsReason(): void
    {
        $this->siteAnswersUpToTheInstall('plugin-install');
        $this->dashboard->on('POST', 'sites/add', 'sites-add-wrong-password');

        $this->save();

        $row = $this->repo->careSites[67];
        self::assertSame('failed', $row['state']);
        self::assertStringContainsString('verify that your Admin Username and Password are correct', (string) $row['last_error'], 'the site\'s own address, which holds the word here, does not hide what the dashboard says about the login');
        self::assertNotNull($row['retry_after']);
    }
}
