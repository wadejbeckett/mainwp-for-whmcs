<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\Admin\Template;
use MainWpForWhmcs\Engine\Http;
use MainWpForWhmcs\Engine\HttpResponse;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Tests\Engine\FixedSecrets;
use MainWpForWhmcs\Tests\Support\FixtureHttp;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\SiteFixtureHttp;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

require_once __DIR__ . '/Engine/Fakes.php';

/**
 * The key route from end to end as WHMCS runs it: the client presses the button, their site sends
 * the browser back, and the module's own site client and dashboard client answer with the exchanges
 * recorded on the bench (fifth run, 10 November 2026). No HTTP leaves the test. One value differs
 * from the recording: the care account's email carries the add-on row here, the bench's did not.
 */
final class KeyRouteTest extends TestCase
{
    private const SITE = 'https://mainwp-bench-site.test';
    private const KEY = 'BenchKey0123456789ABCDEF';
    private const ME = '/wp-json/wp/v2/users/me?context=edit';
    private const USERS = '/wp-json/wp/v2/users';
    private const SEARCH = '/wp-json/wp/v2/plugins?search=mainwp-child';
    private const PLUGINS = '/wp-json/wp/v2/plugins';
    private const INTROSPECT = '/wp-json/wp/v2/users/me/application-passwords/introspect';
    private const DELETE_KEY = '/wp-json/wp/v2/users/me/application-passwords/252b18c6-f4f1-4f5c-a5a5-5900bfc8610a';

    private MemoryRepository $repo;
    private SiteFixtureHttp $site;
    private FixtureHttp $dashboard;
    private FixedSecrets $secrets;
    /** @var list<string> */
    private array $redirects = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../modules/addons/mainwp/mainwp.php';
    }

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        $this->repo->settings = ['dry_run' => '', 'access' => '1', 'care_email' => 'wordpress@care.bench.test', 'dashboard_url' => 'https://mainwp-bench-dash.test', 'api_key' => (new WhmcsEncrypter())->encrypt('bench-key-0123456789')];
        $this->repo->addonModules = [16 => 'mainwp'];
        $this->repo->systemUrl = 'https://whmcs.bench.test';
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', 'mainwp-bench-site.test', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[67] = ['addon_row_id' => 67, 'service_id' => 1176, 'client_id' => 540, 'site_url' => self::SITE, 'dashboard_client_id' => 1, 'dashboard_site_id' => null, 'state' => 'provisioned', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null, 'retry_after' => null];
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
        $this->secrets = new FixedSecrets();
        $secrets = $this->secrets;
        Module::$secretsFactory = static fn (): FixedSecrets => $secrets;
        $this->redirects = [];
        Module::$redirector = function (string $url): void {
            $this->redirects[] = $url;
        };
        \WHMCS\Session::$values['uid'] = 540;
        $_GET = ['m' => 'mainwp', 'addon' => '67'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        Module::$repositoryFactory = null;
        Module::$httpFactory = null;
        Module::$secretsFactory = null;
        Module::$redirector = null;
        $_GET = $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    private function page(): string
    {
        $page = \mainwp_clientarea([]);
        return Template::render($page['templatefile'] . '.tpl', $page['vars']);
    }

    /** The client presses "Approve on my site"; the site's index is read as recorded. Returns the token. */
    private function approve(string $index = 'rest-index'): string
    {
        $this->site->on('GET', '/', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', $index);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['token' => 'test-token', 'action' => 'approve', 'address' => 'mainwp-bench-site.test'];
        $this->page();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        self::assertCount(1, $this->redirects, 'sent to the site');
        self::assertStringStartsWith(self::SITE . '/wp-admin/authorize-application.php?app_name=WordPress%20Care%20%28', $this->redirects[0], 'the approval screen the site\'s own index names');
        $this->redirects = [];
        $this->site->sent = [];
        return $this->secrets->tokens[array_key_last($this->secrets->tokens)];
    }

    /** The browser comes back as the recorded redirect sends it, with this approval's token. */
    private function comeBack(string $token, string $fixture = 'approve-redirect'): void
    {
        parse_str((string) parse_url((string) SiteFixtureHttp::fixture($fixture)->header('location'), PHP_URL_QUERY), $query);
        // Recorded with the query names of 10 November 2026; replayed with today's.
        unset($query['mainwp_return']);
        $query['care_return'] = $token;
        /** @var array<string, string> $query */
        $_GET = $query;
        $this->page();
        $_GET = ['m' => 'mainwp', 'addon' => '67'];
    }

    private function untilTheInstall(string $install, string $me = 'key-users-me', string $create = 'key-user-create'): void
    {
        $this->site->on('GET', '/', 'home')->on('GET', self::ME, $me)->on('POST', self::USERS, $create)
            ->on('GET', self::SEARCH, 'plugins-search-absent')->on('POST', self::PLUGINS, $install);
    }

    private function theKeyIsDeleted(): void
    {
        $this->site->on('GET', '/', 'home')->on('GET', self::INTROSPECT, 'key-introspect')->on('DELETE', self::DELETE_KEY, 'key-delete');
    }

    private function kept(): string
    {
        return json_encode([$this->repo->log, $this->repo->careSites, $this->repo->approvals, $this->redirects, WhmcsCalls::$activity], JSON_THROW_ON_ERROR);
    }

    public function testApprovingOnTheSiteAndComingBackConnectsItAsTheCareAccountAndDeletesTheKey(): void
    {
        $token = $this->approve();
        $this->untilTheInstall('plugin-install');
        $this->dashboard->on('POST', 'sites/add', 'sites-add-with-client')->on('GET', 'users?websites=1&roles=administrator', 'users-list-admins');
        $this->theKeyIsDeleted();

        $this->comeBack($token);

        self::assertSame(['GET /', 'GET ' . self::ME, 'POST ' . self::USERS, 'GET ' . self::SEARCH, 'POST ' . self::PLUGINS, 'GET /', 'GET ' . self::INTROSPECT, 'DELETE ' . self::DELETE_KEY], $this->site->requests(), 'the key is used at once and deleted on the site as the last step');
        foreach ($this->site->sent as $request) {
            self::assertSame('mainwp-bench-site.test', parse_url($request['url'], PHP_URL_HOST));
            if (parse_url($request['url'], PHP_URL_PATH) !== '/') {
                self::assertSame('Basic ' . base64_encode('owner:' . self::KEY), $request['headers']['Authorization']);
            }
        }
        self::assertSame(['username' => 'wpcarek7m2x9ab', 'email' => 'wordpress@care.bench.test', 'password' => FixedSecrets::CARE_PASSWORD, 'roles' => 'administrator', 'name' => 'WordPress Care'], $this->site->sent[2]['form'], 'one care account, its email the Care Account Email');
        self::assertSame(['url' => self::SITE, 'name' => 'mainwp-bench-site.test', 'admin' => 'wpcare-k7m2x9', 'adminpassword' => FixedSecrets::CARE_PASSWORD, 'client_id' => '1'], $this->dashboard->sent[0]['form'], 'the dashboard connects as the care account, by the login the site answered it was made with (recorded on the bench)');

        $row = $this->repo->careSites[67];
        self::assertSame(['connected', 1, null, null], [$row['state'], $row['dashboard_site_id'], $row['last_error'], $row['retry_after']]);
        self::assertSame('["owner"]', $row['other_admins'], 'the site\'s other administrators, as MainWP lists them');
        self::assertSame('{"username":"wpcare-k7m2x9","password":"' . FixedSecrets::CARE_PASSWORD . '"}', (new WhmcsEncrypter())->decrypt((string) $row['login_encrypted']), 'the care account\'s login is the stored login, encrypted');
        $approval = $this->repo->approvals[0];
        self::assertNotNull($approval['used_at']);
        self::assertNull($approval['key_encrypted'], 'nothing of the key is kept once it is deleted on the site');
        self::assertSame(['https://whmcs.bench.test/index.php?m=mainwp&addon=67&care_note=connected'], $this->redirects);
        foreach ([self::KEY, FixedSecrets::CARE_PASSWORD, $token] as $secret) {
            self::assertStringNotContainsString($secret, $this->kept(), 'no key, password or token in a log, a row or an address');
        }
    }

    public function testTheKeyAsABrowserWithJavaScriptReturnsItInGroupsWithSpacesWorksTheSame(): void
    {
        $token = $this->approve();
        $this->untilTheInstall('plugin-install');
        $this->dashboard->on('POST', 'sites/add', 'sites-add-with-client');
        $this->theKeyIsDeleted();

        $this->comeBack($token, 'approve-redirect-browser');

        self::assertSame('Basic ' . base64_encode('owner:' . self::KEY), $this->site->sent[1]['headers']['Authorization'], 'sent without the spaces');
        self::assertSame('connected', $this->repo->careSites[67]['state']);
    }

    public function testARefusedInstallRemovesTheCareAccountAgainAndEndsInFailedWithARetryEntry(): void
    {
        $token = $this->approve();
        $this->untilTheInstall('plugin-install-forbidden');
        $this->site->on('DELETE', self::USERS . '/2?force=true&reassign=1', 'key-user-delete');

        $this->comeBack($token);

        self::assertSame('DELETE ' . self::USERS . '/2?force=true&reassign=1', $this->site->requests()[5], 'the care account made before the refusal is removed');
        self::assertCount(6, $this->site->sent, 'the key stays on the site for the retry');
        self::assertSame([], $this->dashboard->sent);
        $row = $this->repo->careSites[67];
        self::assertSame('failed', $row['state']);
        self::assertStringContainsString('does not allow plugins to be installed', (string) $row['last_error']);
        self::assertNotNull($row['retry_after']);
        self::assertArrayNotHasKey('login_encrypted', array_filter($row, static fn ($v): bool => $v !== null), 'no login is stored for a site that is not connected by the key route');
        $held = (string) $this->repo->approvals[0]['key_encrypted'];
        self::assertNotSame('', $held);
        self::assertStringNotContainsString(self::KEY, $held, 'kept for the retry, encrypted');
        self::assertStringContainsString('"key":"' . self::KEY . '"', (new WhmcsEncrypter())->decrypt($held));
        self::assertStringNotContainsString(self::KEY, $this->kept());
    }

    public function testAReturnOnAnExpiredLinkDeletesTheKeyOnTheSiteAndTheSiteIsFailedWithTheReason(): void
    {
        $token = $this->approve();
        $this->repo->approvals[0]['expires_at'] = '2026-01-01 00:00:00';
        $this->theKeyIsDeleted();

        $this->comeBack($token);

        self::assertSame(['GET /', 'GET ' . self::INTROSPECT, 'DELETE ' . self::DELETE_KEY], $this->site->requests(), 'the key is not used for anything but its own deletion');
        self::assertSame([], $this->dashboard->sent);
        $row = $this->repo->careSites[67];
        self::assertSame(['failed', null], [$row['state'], $row['retry_after']]);
        self::assertStringContainsString('not approved on your site within 24 hours', (string) $row['last_error']);
        self::assertStringContainsString('care_note=lapsed', $this->redirects[0]);
        self::assertStringNotContainsString('care_key=left', $this->redirects[0]);
    }

    public function testAServerThatDoesNotPassTheKeyOnEndsInFailedWithTheReasonAndARetryEntry(): void
    {
        $token = $this->approve();
        $this->site->on('GET', '/', 'home')->on('GET', self::ME, 'key-users-me-no-header');

        $this->comeBack($token);

        $row = $this->repo->careSites[67];
        self::assertSame('failed', $row['state']);
        self::assertStringContainsString('did not accept the connection you approved', (string) $row['last_error']);
        self::assertStringNotContainsString('Authorization', (string) $row['last_error']);
        self::assertStringContainsString('does not pass the Authorization header on to WordPress', (string) $this->repo->log[array_key_last($this->repo->log)]['detail'], 'the cause is in the log for staff');
        self::assertNotNull($row['retry_after']);
        self::assertNotNull($this->repo->approvals[0]['key_encrypted']);
    }

    public function testAnHttpLoginInFrontOfTheSiteEndsInFailedWithTheReasonAndARetryEntry(): void
    {
        $token = $this->approve();
        $this->site->on('GET', '/', 'home')->on('GET', self::ME, 'http-auth-users-me');

        $this->comeBack($token);

        $row = $this->repo->careSites[67];
        self::assertSame('failed', $row['state']);
        self::assertStringContainsString('a separate HTTP login or a firewall', (string) $row['last_error']);
        self::assertNotNull($row['retry_after']);
    }

    public function testPressingApproveAgainWhenTheDashboardAlreadyHoldsTheSiteSaysItIsConnectedAndSendsNobodyAnywhere(): void
    {
        $token = $this->approve();
        $this->untilTheInstall('plugin-install');
        // The dashboard connects the site, but its answer never arrives.
        $this->dashboard->on('POST', 'sites/add', new \RuntimeException('Operation timed out after 30001 milliseconds'));
        $this->comeBack($token);
        self::assertSame('failed', $this->repo->careSites[67]['state']);
        $this->redirects = [];

        $this->site->on('GET', '/', 'home', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index')->on('GET', self::INTROSPECT, 'key-introspect')->on('DELETE', self::DELETE_KEY, 'key-delete');
        $this->dashboard->on('GET', 'sites?page=1&per_page=20', 'sites-list');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['token' => 'test-token', 'action' => 'approve', 'address' => 'mainwp-bench-site.test'];
        $html = $this->page();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];

        self::assertSame([], $this->redirects, 'the client is not sent to approve a second key');
        self::assertStringContainsString('Your site is already connected at ' . self::SITE . '.', $html);
        self::assertStringContainsString('Your site is connected and cared for.', $html);
        self::assertSame('connected', $this->repo->careSites[67]['state']);
        self::assertNull($this->repo->approvals[0]['key_encrypted'], 'and the key of the first approval is deleted on the site');
    }

    public function testWordfenceWithItsDefaultsIsOfferedThePasswordRouteWithTheReason(): void
    {
        $this->site->on('GET', '/', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index-wordfence');

        $html = $this->page();

        self::assertStringNotContainsString('Approve on my site', $html);
        self::assertStringContainsString('Wordfence has switched application passwords off on your site', $html);
        self::assertStringContainsString('name="username"', $html);
        foreach ($this->site->sent as $request) {
            self::assertArrayNotHasKey('Authorization', $request['headers'], 'read without any login');
        }
    }

    public function testWordfenceWithKeysSwitchedOnIsOfferedTheKeyRouteWithTheAlertToExpect(): void
    {
        $this->site->on('GET', '/', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index-wordfence-keys-on');

        $html = $this->page();

        self::assertStringContainsString('Approve on my site', $html);
        self::assertStringContainsString('Your site runs Wordfence. It will report the account we create as an administrator "created outside of WordPress". That alert is expected.', $html);
    }

    /**
     * Recorded with a password of letters and digits only, which Wordfence refuses for an
     * administrator; that is why the module's care password carries hyphens (RandomSecrets). The
     * refusal still shows how a site that will not make the care account ends.
     */
    public function testASiteThatRefusesTheCareAccountEndsInFailedWithItsOwnWords(): void
    {
        $token = $this->approve('rest-index-wordfence-keys-on');
        $this->site->on('GET', '/', 'home')->on('GET', self::ME, 'key-users-me-wordfence')->on('POST', self::USERS, 'key-user-create-wordfence');

        $this->comeBack($token);

        $row = $this->repo->careSites[67];
        self::assertSame('failed', $row['state']);
        self::assertStringContainsString('did not let us create the administrator account for care (it said: The password provided is too weak', (string) $row['last_error']);
        self::assertStringNotContainsString('<', (string) $row['last_error']);
        self::assertNotNull($row['retry_after']);
        self::assertCount(3, $this->site->sent, 'nothing is installed for an account that could not be made');
    }

    public function testASiteWithKeysSwitchedOffOrBehindAnHttpLoginIsOfferedThePasswordRouteWithTheReason(): void
    {
        $this->site->on('GET', '/', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index-keys-off');
        $html = $this->page();
        self::assertStringNotContainsString('Approve on my site', $html);
        self::assertStringContainsString('Application passwords are switched off on your site', $html);

        $this->site->on('GET', '/', 'http-auth-home');
        $html = $this->page();
        self::assertStringNotContainsString('Approve on my site', $html);
        self::assertStringContainsString('a separate HTTP login or a firewall', $html);
        self::assertStringContainsString('name="username"', $html);
    }
}
