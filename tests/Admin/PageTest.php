<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Admin;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Admin\Page;
use MainWpForWhmcs\Admin\Template;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Settings;
use MainWpForWhmcs\Tests\Support\FixtureHttp;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

/** The module's admin page (Addons > MainWP for WHMCS), in three tabs, rendered through its Smarty template. */
final class PageTest extends TestCase
{
    private const KEY = '9f8e7d6c5b4a32109f8e7d6c5b4a32109f8e7d6c==0a1b2c3d4e5f67890a1b2c3d4e5f67890a1b2c3d';
    private const TYPED = '1111111111111111111111111111111111111111==2222222222222222222222222222222222222222';
    private const LINK = 'addonmodules.php?module=mainwp';

    private MemoryRepository $repo;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../modules/addons/mainwp/mainwp.php';
    }

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        // WHMCS writes the ticked roles of the Configure form as the "access" row: role ids, comma-separated
        $this->repo->settings = ['access' => '1,3', 'dry_run' => 'on'];
        $this->repo->addonModules = [16 => 'mainwp'];
        $this->repo->adminRoles = [1 => 1, 2 => 2];
        Settings::save($this->repo, new WhmcsEncrypter(), ['dashboard_url' => 'https://dash.example.com', 'api_key' => self::KEY, 'mode' => 'dry_run']);
        $this->repo->addLog(['created_at' => '2026-11-09 13:00:00', 'actor' => 'hook:AddonActivated', 'action' => 'provision', 'target' => 'add-on 68', 'outcome' => 'would run', 'dry_run' => 1, 'detail' => 'would build <script>alert(1)</script>']);
        WhmcsCalls::$api = [];
        Module::$repositoryFactory = fn (): MemoryRepository => $this->repo;
    }

    protected function tearDown(): void
    {
        Module::$repositoryFactory = null;
        Module::$httpFactory = null;
    }

    /** Live as an install already Live keeps it: a save would need a Care Account Email, which these tests leave out. */
    private function live(): void
    {
        $this->repo->settings['dry_run'] = '';
    }

    /** The dashboard answering the connection test: one client, one site. */
    private function counts(): FixtureHttp
    {
        $http = (new FixtureHttp())->on('GET', 'clients/count', 'clients-count')->on('GET', 'sites/count', 'sites-count');
        Module::$httpFactory = static fn (): FixtureHttp => $http;
        return $http;
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed>|string $get the request's query, or just the tab ("" for none: the page opens on Sites)
     */
    private function render(?int $adminId, string $method = 'GET', array $post = [], array|string $get = ''): string
    {
        return Page::render($this->repo, new WhmcsEncrypter(), $adminId, self::LINK, $method, $post, 'tok-123', is_array($get) ? $get : ($get === '' ? [] : ['tab' => $get]));
    }

    /** A care site of Demo Client, with its add-on row. */
    private function careSite(int $id, string $state, ?int $dashboardSiteId = null, string $client = 'Demo Client', int $clientId = 540): void
    {
        $this->repo->addons[$id] = new \MainWpForWhmcs\AddonRow($id, 1100 + $id, $clientId, 16, 'Active', 'Active', 'site' . $id . '.example', $client, 'demo-client@example.com', 0);
        $this->repo->careSites[$id] = [
            'addon_row_id' => $id, 'service_id' => 1100 + $id, 'client_id' => $clientId, 'site_url' => 'https://site' . $id . '.example', 'dashboard_client_id' => 1,
            'dashboard_site_id' => $dashboardSiteId, 'state' => $state, 'retry_count' => 0, 'last_sync' => $dashboardSiteId !== null ? '2026-11-09 15:55:13' : null, 'last_error' => null, 'retry_after' => null,
        ];
    }

    /** @return list<array{command: string, values: array<string, mixed>}> */
    private function emails(): array
    {
        return array_values(array_filter(WhmcsCalls::$api, static fn (array $call): bool => $call['command'] === 'SendAdminEmail'));
    }

    /** The sites in the order the list shows them. */
    private function listed(string $html): array
    {
        preg_match_all('#<tr><td class="text-nowrap">(?:<a [^>]*>)?(https://[^<]+)#', $html, $m);
        return $m[1];
    }

    // The page and its tabs

    public function testThePageHasThreeTabsEachWithItsOwnAddress(): void
    {
        foreach (['sites' => 'Sites', 'settings' => 'Settings', 'log' => 'Log'] as $tab => $label) {
            $html = $this->render(1, 'GET', [], $tab);
            self::assertStringContainsString('<ul class="nav nav-tabs admin-tabs" role="tablist">', $html, 'WHMCS\'s own tab strip');
            self::assertStringContainsString('<li class="active"><a href="addonmodules.php?module=mainwp&amp;tab=' . $tab . '">' . $label . '</a></li>', $html);
            foreach (['sites', 'settings', 'log'] as $other) {
                self::assertStringContainsString('href="addonmodules.php?module=mainwp&amp;tab=' . $other . '"', $html, 'every tab is a plain link, so each has its own address');
            }
            self::assertStringContainsString("<div class=\"tab-content admin-tabs\">\n<div class=\"tab-pane active\">", $html);
            self::assertStringNotContainsString('context-btn-container', $html, 'that bar bleeds over the tab strip in the Iris theme');
            self::assertStringNotContainsString('{$', $html, 'every variable rendered');
        }
        self::assertStringNotContainsString('Care sites', $this->render(1), 'the tab is called as the MainWP Dashboard calls its own');
    }

    public function testWithoutATabOrWithAnUnknownOneThePageOpensOnSites(): void
    {
        foreach (['', 'nonsense<b>', 'SETTINGS'] as $tab) {
            $html = $this->render(1, 'GET', [], $tab);
            self::assertStringContainsString('<li class="active"><a href="addonmodules.php?module=mainwp&amp;tab=sites">Sites</a></li>', $html);
            self::assertStringNotContainsString('nonsense', $html, 'what was asked for is never echoed');
        }
        $html = $this->render(1, 'GET', [], ['tab' => ['settings']]);
        self::assertStringContainsString('<li class="active"><a href="addonmodules.php?module=mainwp&amp;tab=sites">Sites</a></li>', $html, 'a tab that is not a word is no tab');
    }

    public function testEachTabShowsItsOwnContentAndNotTheOthers(): void
    {
        $sites = $this->render(1, 'GET', [], 'sites');
        $settings = $this->render(1, 'GET', [], 'settings');
        $log = $this->render(1, 'GET', [], 'log');

        self::assertStringContainsString('>Issue</a>', $sites);
        self::assertStringContainsString('value="daily_check"', $sites);
        self::assertStringNotContainsString('name="dashboard_url"', $sites);
        self::assertStringNotContainsString('>Outcome</a>', $sites);
        self::assertStringContainsString('name="dashboard_url"', $settings);
        self::assertStringContainsString('>Test Connection</button>', $settings);
        self::assertStringNotContainsString('>Issue</a>', $settings);
        self::assertStringNotContainsString('>Outcome</a>', $settings);
        self::assertStringNotContainsString('log_retention_months', $settings, 'the log\'s retention has one home: the Log tab');
        self::assertStringContainsString('>Outcome</a>', $log);
        self::assertStringContainsString('name="log_retention_months"', $log);
        self::assertStringNotContainsString('name="dashboard_url"', $log);
        self::assertStringNotContainsString('value="daily_check"', $log);
    }

    public function testEveryFormThatChangesSomethingPostsToItsOwnTabWithWhmcssToken(): void
    {
        $this->careSite(69, 'provisioned');
        $pages = [
            'sites' => $this->render(1, 'GET', [], 'sites'),
            'sites&amp;connect=69' => $this->render(1, 'GET', [], ['tab' => 'sites', 'connect' => '69']),
            'settings' => $this->render(1, 'GET', [], 'settings'),
            'log' => $this->render(1, 'GET', [], 'log'),
        ];
        foreach ($pages as $address => $html) {
            preg_match_all('#<form[^>]*method="post"[^>]*>.*?</form>#s', $html, $forms);
            self::assertNotSame([], $forms[0], $address);
            foreach ($forms[0] as $form) {
                self::assertStringContainsString('action="addonmodules.php?module=mainwp&amp;tab=' . $address . '"', $form);
                self::assertSame(1, substr_count($form, '<input type="hidden" name="token" value="tok-123">'), 'every posting form carries WHMCS\'s token');
            }
            preg_match_all('#<form[^>]*method="get"[^>]*>#', $html, $gets);
            foreach ($gets[0] as $form) {
                self::assertStringContainsString('action="addonmodules.php"', $form, 'a search or a jump is an address, with no token in it');
            }
            self::assertStringNotContainsString('tok-123', (string) preg_replace('#<form[^>]*method="post"[^>]*>.*?</form>#s', '', $html), 'the token is in no link and no search form');
        }
    }

    public function testInDryRunEveryTabSaysSoAndLinksToTheSettings(): void
    {
        foreach (['sites', 'log'] as $tab) {
            self::assertStringContainsString('<div class="alert alert-warning">Dry run: actions are only logged. Switch to Live under <a href="addonmodules.php?module=mainwp&amp;tab=settings">Settings</a>.</div>', $this->render(1, 'GET', [], $tab));
        }
        self::assertStringContainsString('<div class="alert alert-warning">Dry run: actions are only logged.</div>', $this->render(1, 'GET', [], 'settings'), 'the Settings tab does not send the reader to itself');
        $this->live();
        foreach (['sites', 'settings', 'log'] as $tab) {
            self::assertStringNotContainsString('Dry run:', $this->render(1, 'GET', [], $tab), 'live is the normal state and needs no banner');
        }
    }

    public function testTheWarningThatClientsCannotApproveOnTheirSiteShowsWhereItMatters(): void
    {
        // Setting up, in dry run: it is said beside the field that mends it, not on every tab.
        $warning = '<div class="alert alert-warning">No care account is made on clients&#039; sites, and clients can connect with a username and password only, until a Care Account Email is entered under Settings.</div>';
        self::assertStringContainsString($warning, $this->render(1, 'GET', [], 'settings'));
        self::assertStringNotContainsString($warning, $this->render(1, 'GET', [], 'sites'));
        self::assertStringNotContainsString($warning, $this->render(1, 'GET', [], 'log'));

        // Live: clients are affected now, so every tab says it.
        $this->live();
        foreach (['sites', 'settings', 'log'] as $tab) {
            self::assertStringContainsString($warning, $this->render(1, 'GET', [], $tab));
        }
        $this->repo->settings['care_email'] = 'wordpress@care.example.com';
        $this->repo->systemUrl = 'http://billing.example.com';
        self::assertStringContainsString('<div class="alert alert-warning">Clients can connect with a username and password only, until WHMCS&#039;s System URL (General Settings) starts with https://.</div>', $this->render(1), 'with where it is mended');
        $this->repo->systemUrl = 'https://billing.example.com';
        self::assertStringNotContainsString('Clients can connect', $this->render(1));
    }

    public function testThePageIsDrawnByTheAdminThemeAlone(): void
    {
        $this->careSite(69, 'provisioned');
        foreach (['sites', 'settings', 'log', ['tab' => 'sites', 'connect' => '69'], ['tab' => 'settings', 'key' => 'change']] as $get) {
            $html = $this->render(1, 'GET', [], $get);
            foreach (['<style', '<script', ' style=', 'onclick', 'onchange', 'javascript:'] as $own) {
                self::assertStringNotContainsString($own, $html, 'no style and no script of the module\'s own');
            }
            self::assertDoesNotMatchRegularExpression('/class="[^"]*\\b(card|card-body|mb-3|badge|bg-\\w+)\\b/', $html, 'WHMCS\'s admin area is Bootstrap 3: no class of a later Bootstrap');
        }
    }

    public function testARoleOffTheAccessListOrNoAdminIsRefused(): void
    {
        $this->careSite(69, 'provisioned');
        foreach ([2, null, 99] as $admin) {
            foreach (['sites', 'settings', 'log', ['tab' => 'sites', 'connect' => '69']] as $get) {
                $html = $this->render($admin, 'GET', [], $get);
                self::assertStringContainsString('Your admin role has no access to MainWP for WHMCS. An administrator can grant it under System Settings &gt; Addon Modules &gt; Configure.', $html);
                self::assertStringNotContainsString('<form', $html);
                self::assertStringNotContainsString('dash.example.com', $html);
                self::assertStringNotContainsString('site69', $html);
                self::assertStringNotContainsString('would build', $html);
            }
        }
        $http = new FixtureHttp();
        Module::$httpFactory = static fn (): FixtureHttp => $http;
        $before = [$this->repo->settings, $this->repo->log, $this->repo->careSites];
        foreach ([['dashboard_url' => 'https://evil.example', 'mode' => 'live'], ['action' => 'test_connection'], ['action' => 'daily_check'], ['action' => 'clear_log_confirmed'], ['action' => 'connect', 'site_username' => 'owner', 'site_password' => 'pw']] as $post) {
            $this->render(2, 'POST', $post, ['tab' => 'sites', 'connect' => '69']);
        }
        self::assertSame($before, [$this->repo->settings, $this->repo->log, $this->repo->careSites], 'a refused role saves, runs, clears and connects nothing');
        self::assertSame([], $http->sent);
    }

    // Sites

    public function testBeforeItsFirstCheckTheListShowsEveryCareSiteAndOffersTheCheck(): void
    {
        $this->careSite(68, 'connected', 5);

        $html = $this->render(1);

        self::assertStringContainsString('<p class="form-control-static">Last check: Never</p>', $html);
        self::assertStringContainsString('<button type="submit" name="action" value="daily_check" class="btn btn-default">Check Now</button>', $html);
        self::assertStringContainsString('<a href="https://dash.example.com" target="_blank" rel="noopener" class="btn btn-default">Open MainWP</a>', $html, 'one button opens the dashboard in a new tab');
        self::assertStringContainsString('<tr><td class="text-nowrap"><a href="clientsservices.php?userid=540&amp;id=1168">https://site68.example</a></td><td class="text-nowrap"><a href="clientssummary.php?userid=540">Demo Client</a></td><td class="text-nowrap"><span class="label active">Connected</span></td><td class="text-nowrap">2026-11-09 15:55:13</td><td></td>', $html, 'the site opens its service in WHMCS, the client their summary');
        self::assertStringContainsString('1 Records Found, Page 1 of 1', $html);
        foreach (['sends no email', 'Runs the daily check', 'What the last check found', 'label-info">new', 'Nothing to report', 'Not checked yet'] as $gone) {
            self::assertStringNotContainsString($gone, $html, 'the page says what is needed and no more');
        }

        $this->repo->careSites = [];
        $this->repo->addons = [];
        $html = $this->render(1);
        self::assertStringContainsString('<tr><td colspan="6">No sites yet. A site appears when its care add-on is paid.</td></tr>', $html);
        self::assertStringContainsString('0 Records Found, Page 1 of 1', $html);
    }

    public function testTheCheckLineHasTheCheckAndItsLastRunSideBySide(): void
    {
        $html = $this->render(1);
        self::assertMatchesRegularExpression('#<div class="row">\s*<div class="col-sm-6"><p class="form-control-static">Last check: Never</p></div>\s*<div class="col-sm-6 text-right">.*?value="daily_check"[^>]*>Check Now</button></div>\s*</div>#s', $html, 'one row: the last run on the left, the buttons on the right');
        unset($this->repo->settings['dashboard_url']);
        self::assertStringNotContainsString('Open MainWP', $this->render(1), 'no dashboard address, no button to it');
    }

    public function testRunningTheCheckFromThePageListsEverySiteWithWhatHasAnIssueFirst(): void
    {
        $this->careSite(68, 'connected', 5);
        $this->careSite(69, 'provisioned');
        $this->careSite(70, 'failed');
        $this->repo->careSites[70]['last_error'] = 'The login page shows a captcha <b>.';
        $this->careSite(71, 'removed');
        $this->repo->addons[71] = new \MainWpForWhmcs\AddonRow(71, 1171, 540, 16, 'Cancelled', 'Active', 'site71.example', 'Demo Client', 'demo-client@example.com', 0);
        $this->repo->addons[72] = new \MainWpForWhmcs\AddonRow(72, 1172, 541, 16, 'Active', 'Active', 'site72.example', 'Second Client', 'second@example.com', 0);
        $this->repo->settings['report_by_email'] = 'on';

        $html = $this->render(1, 'POST', ['action' => 'daily_check']);

        self::assertStringContainsString('<div class="alert alert-success">Checked: 3 issues.</div>', $html);
        self::assertSame([], $this->emails(), 'a check from the page sends no email');
        self::assertSame(['https://site69.example', 'https://site70.example', 'https://site72.example', 'https://site68.example'], $this->listed($html), 'what has an issue first, then by site; a removed site with nothing to report is history');
        self::assertStringContainsString('<span class="label pending">Provisioned</span></td><td class="text-nowrap">Never</td><td>Waiting for the client to connect the site.</td>', $html);
        self::assertStringContainsString('<span class="label terminated">Failed</span></td><td class="text-nowrap">Never</td><td>The login page shows a captcha &lt;b&gt;.</td>', $html);
        self::assertStringContainsString('<a href="clientsservices.php?userid=541&amp;id=1172">https://site72.example</a></td><td class="text-nowrap"><a href="clientssummary.php?userid=541">Second Client</a></td><td class="text-nowrap"><span class="label pending">Not provisioned</span></td><td class="text-nowrap">Never</td><td>Would be provisioned (dry run).</td>', $html, 'a billed add-on without a care site is listed too');
        self::assertStringContainsString('<span class="label active">Connected</span></td><td class="text-nowrap">2026-11-09 15:55:13</td><td></td>', $html, 'a site with nothing found says nothing');
        self::assertMatchesRegularExpression('/Last check: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \(dry run\)</', $html, 'a list made by a dry run says so, also after the mode is switched');
        self::assertStringNotContainsString('label-info', $html, 'what is new is the report email\'s business, not the list\'s');
        self::assertStringNotContainsString('{$', $html);
    }

    public function testEveryStatusIsOneOfWhmcssOwnLabelsAndTheKeyUnderTheListSaysWhatEachMeans(): void
    {
        foreach (['provisioned', 'connecting', 'connected', 'failed', 'suspended', 'removed'] as $i => $state) {
            $this->careSite(60 + $i, $state, $state === 'connected' ? 5 : null);
        }
        $html = $this->render(1, 'GET', [], ['tab' => 'sites', 'status' => 'removed']) . $this->render(1);

        foreach (['pending">Provisioned', 'pending">Connecting', 'active">Connected', 'terminated">Failed', 'suspended">Suspended', 'cancelled">Removed'] as $label) {
            self::assertStringContainsString('<span class="label ' . $label . '</span>', $html);
        }
        $key = substr($html, (int) strrpos($html, '<tr><th width="170">Status</th><th>Meaning</th></tr>'));
        self::assertSame(8, substr_count($key, '<tr><td class="text-nowrap"><span class="label '), 'every status, as the label the list shows');
        self::assertStringContainsString('<tr><td class="text-nowrap"><span class="label pending">Provisioned</span></td><td>Paid for, and the client is in MainWP. The site is not connected yet.</td></tr>', $key, 'the owner\'s own definition');
        self::assertStringContainsString('<span class="label active">Connected</span></td><td>Connected to MainWP and syncing.</td>', $key);
        self::assertStringContainsString('<span class="label terminated">Disconnected</span></td><td>MainWP cannot reach the site.</td>', $key);
        self::assertStringContainsString('<span class="label pending">Not provisioned</span></td><td>Paid for. The client is added to MainWP at the next check.</td>', $key);
        self::assertStringContainsString("<div class=\"tablebg\">\n<table class=\"datatable\" width=\"100%\" border=\"0\" cellspacing=\"1\" cellpadding=\"3\">\n<tr><th width=\"170\">Status</th>", $html, 'drawn as the list above it is');
    }

    public function testInLiveModeTheListShowsADashboardFaultAndTheSitesOnlyTheDashboardHolds(): void
    {
        $this->live();
        $this->careSite(68, 'connected', 5);
        $http = (new FixtureHttp())->on('GET', 'sites?page=1&per_page=20', 'sites-list')->on('GET', 'clients?page=1&per_page=100', 'clients-list')->on('GET', 'sites/5', 'site-get-unknown', 'site-get-unknown');
        Module::$httpFactory = static fn (): FixtureHttp => $http;

        $html = $this->render(1, 'POST', ['action' => 'daily_check']);

        self::assertStringContainsString('<div class="alert alert-success">Checked: 2 issues.</div>', $html);
        self::assertStringContainsString('<tr><td class="text-nowrap">https://mainwp-bench-site.test/</td><td class="text-nowrap">Demo Client</td><td class="text-nowrap"><span class="label active">Connected</span></td><td class="text-nowrap"></td><td>On the dashboard without a care add-on.</td><td class="text-right text-nowrap"></td></tr>', $html, 'a site only the dashboard holds is a row of the list, in MainWP\'s own word for it, with no link to follow');
        self::assertStringNotContainsString('Also found by the last check', $html);

        // The next run cannot read the dashboard at all.
        $http->on('GET', 'sites?page=1&per_page=20', 'key-wrong');
        $html = $this->render(1, 'POST', ['action' => 'daily_check']);
        self::assertStringContainsString('<div class="alert alert-danger top-margin-10">The dashboard could not be read, so nothing was changed: The dashboard refused the API key', $html);
        self::assertStringNotContainsString(self::KEY, $html);
    }

    public function testAConnectedSiteTheLastCheckFoundDisconnectedIsShownInMainWpsWord(): void
    {
        $this->careSite(68, 'connected', 5);
        $this->careSite(69, 'connected', 6);
        $this->repo->saveSettings(['last_check' => json_encode(['at' => '2026-11-11 00:03:54', 'by' => 'cron:daily', 'dry' => false, 'fault' => null, 'total' => 2, 'new' => 0, 'email' => '', 'lines' => [
            ['k' => 'disconnected:add-on 69', 'a' => 69, 'c' => 'Demo Client (client 540)', 's' => 'https://site69.example', 't' => 'Connected', 'p' => 'disconnected on the dashboard (HTTP error 500)', 'n' => false],
            ['k' => 'log 2026-11-10 09:00:00 suspend', 'a' => null, 'c' => '', 's' => 'a hosting service', 't' => 'from the log', 'p' => 'WHMCS did not say which service was suspended <i>', 'n' => true],
        ]], JSON_THROW_ON_ERROR)]);

        $html = $this->render(1);

        self::assertStringContainsString('<span class="label terminated">Disconnected</span></td><td class="text-nowrap">2026-11-09 15:55:13</td><td>Disconnected on the dashboard (HTTP error 500).</td><td class="text-right text-nowrap"> <a href="https://dash.example.com/wp-admin/admin.php?page=managesites&amp;dashboard=6" target="_blank" rel="noopener" class="btn btn-default btn-sm">Open in MainWP</a></td>', $html, 'with the way to the site on the dashboard');
        self::assertStringContainsString('<span class="label active">Connected</span>', $html);
        self::assertStringContainsString('<div class="alert alert-warning top-margin-10"><ul><li>a hosting service: WHMCS did not say which service was suspended &lt;i&gt;</li></ul></div>', $html, 'what the check found that is no site\'s');
        self::assertSame(['https://site69.example', 'https://site68.example'], $this->listed($html));
    }

    public function testWhenTheCheckFoundMoreThanItKeepsTheListSaysSo(): void
    {
        $this->live();
        for ($id = 1; $id <= 101; $id++) {
            $this->careSite($id, 'provisioned');
        }
        $http = (new FixtureHttp())->on('GET', 'sites?page=1&per_page=20', 'sites-list-empty')->on('GET', 'clients?page=1&per_page=100', 'clients-list');
        Module::$httpFactory = static fn (): FixtureHttp => $http;

        $html = $this->render(1, 'POST', ['action' => 'daily_check'], ['tab' => 'sites', 'page' => '3']);

        self::assertStringContainsString('<div class="alert alert-success">Checked: 101 issues.</div>', $html);
        self::assertStringContainsString('<p class="text-muted">The last check found 1 more issue than it could keep.</p>', $html);
        self::assertStringContainsString('101 Records Found, Page 3 of 3', $html);
    }

    public function testASecondCheckWhileOneIsGoingSaysSo(): void
    {
        $this->careSite(69, 'provisioned');
        $this->repo->lockedElsewhere = ['addon:0'];
        self::assertStringContainsString('<div class="alert alert-info">A check is already running.</div>', $this->render(1, 'POST', ['action' => 'daily_check']));
    }

    public function testTheListIsNarrowedByWordsAndByStatusThroughWhmcssSearchFilterStrip(): void
    {
        $this->careSite(68, 'connected', 5, 'Acme Studio', 101);
        $this->careSite(69, 'provisioned', null, 'Harbour Books', 102);
        $this->careSite(70, 'provisioned', null, 'Acme Studio', 101);

        $html = $this->render(1);
        self::assertStringContainsString('<ul class="nav nav-tabs admin-tabs admin-tabs--toggleable top-margin-10" role="tablist"><li><a class="tab-top" href="#mainwp-filter" role="tab" data-toggle="tab" aria-expanded="false">Search/Filter</a></li></ul>', $html, 'closed until it is opened, as on WHMCS\'s own lists');
        self::assertStringContainsString('<div class="tab-pane" id="mainwp-filter">', $html);
        self::assertStringContainsString('<form method="get" action="addonmodules.php">' . "\n" . '<input type="hidden" name="module" value="mainwp"><input type="hidden" name="tab" value="sites">', $html, 'a search is an address');
        self::assertStringContainsString('<option value="">Any</option><option value="notprovisioned">Not provisioned</option><option value="provisioned">Provisioned</option>', $html);
        self::assertStringNotContainsString('>Reset</a>', $html);

        $html = $this->render(1, 'GET', [], ['tab' => 'sites', 'search' => ' ACME ', 'status' => 'provisioned']);
        self::assertSame(['https://site70.example'], $this->listed($html));
        self::assertStringContainsString('1 Records Found, Page 1 of 1', $html);
        self::assertStringContainsString('<li class="active"><a class="tab-top" href="#mainwp-filter" role="tab" data-toggle="tab" aria-expanded="true">', $html, 'open while it narrows the list, and drawn as WHMCS draws an open strip');
        self::assertStringContainsString('<div class="tab-pane active" id="mainwp-filter">', $html);
        self::assertStringContainsString('name="search" value="ACME"', $html);
        self::assertStringContainsString('<option value="provisioned" selected>Provisioned</option>', $html);
        self::assertStringContainsString('<a href="addonmodules.php?module=mainwp&amp;tab=sites" class="btn btn-default">Reset</a>', $html);

        self::assertSame(['https://site69.example'], $this->listed($this->render(1, 'GET', [], ['tab' => 'sites', 'search' => 'site69'])), 'in the site as in the client');
        $none = $this->render(1, 'GET', [], ['tab' => 'sites', 'search' => 'nobody <b>']);
        self::assertStringContainsString('<tr><td colspan="6">No Records Found</td></tr>', $none);
        self::assertStringContainsString('name="search" value="nobody &lt;b&gt;"', $none);
        self::assertSame(['https://site68.example', 'https://site69.example', 'https://site70.example'], $this->listed($this->render(1, 'GET', [], ['tab' => 'sites', 'status' => 'archived'])), 'a status that is none narrows nothing');
    }

    public function testAHeadingSortsTheListAndShowsWhichWay(): void
    {
        $this->careSite(68, 'connected', 5, 'Zeta Farm', 101);
        $this->careSite(69, 'provisioned', null, 'Acme Studio', 102);
        $this->careSite(70, 'failed', null, 'Mid Books', 103);
        $this->repo->careSites[68]['last_sync'] = '2026-11-10 23:40:00';

        $html = $this->render(1);
        self::assertStringContainsString('<table id="sortabletbl1" class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">', $html, 'WHMCS\'s own list table');
        self::assertStringContainsString('<th><a href="addonmodules.php?module=mainwp&amp;tab=sites&amp;orderby=client&amp;order=asc">Client</a></th>', $html);
        self::assertStringContainsString('<th><a href="addonmodules.php?module=mainwp&amp;tab=sites&amp;orderby=issue&amp;order=desc">Issue</a> <img src="images/asc.gif" class="absmiddle" alt="asc"></th>', $html, 'the order the list opens in is marked as every order is: what has an issue first');
        self::assertSame(1, substr_count($html, '.gif'), 'one heading carries the arrow');

        $byClient = $this->render(1, 'GET', [], ['tab' => 'sites', 'orderby' => 'client']);
        self::assertSame(['https://site69.example', 'https://site70.example', 'https://site68.example'], $this->listed($byClient));
        self::assertStringContainsString('<th><a href="addonmodules.php?module=mainwp&amp;tab=sites&amp;orderby=client&amp;order=desc">Client</a> <img src="images/asc.gif" class="absmiddle" alt="asc"></th>', $byClient, 'WHMCS\'s own arrow; the heading turns the order round');
        self::assertSame(['https://site68.example', 'https://site70.example', 'https://site69.example'], $this->listed($this->render(1, 'GET', [], ['tab' => 'sites', 'orderby' => 'client', 'order' => 'desc'])));
        self::assertSame(['https://site69.example', 'https://site68.example', 'https://site70.example'], $this->listed($this->render(1, 'GET', [], ['tab' => 'sites', 'orderby' => 'status'])), 'in the order of a site\'s life');
        self::assertSame(['https://site68.example', 'https://site69.example', 'https://site70.example'], $this->listed($this->render(1, 'GET', [], ['tab' => 'sites', 'orderby' => 'lastsync', 'order' => 'desc'])));
        self::assertSame(['https://site70.example', 'https://site69.example', 'https://site68.example'], $this->listed($this->render(1, 'GET', [], ['tab' => 'sites', 'orderby' => 'site', 'order' => 'desc'])));
        self::assertSame($this->listed($html), $this->listed($this->render(1, 'GET', [], ['tab' => 'sites', 'orderby' => 'issue; DROP TABLE x', 'order' => 'up'])), 'anything else is the order the list opens in');
    }

    public function testTheListIsCutIntoPagesTheWayWhmcssOwnListsAre(): void
    {
        $this->repo->recordsPerPage = '2';
        foreach ([64, 65, 66, 67, 68] as $id) {
            $this->careSite($id, 'connected', $id);
        }

        $html = $this->render(1, 'GET', [], ['tab' => 'sites', 'page' => '2', 'search' => 'site']);

        self::assertSame(['https://site66.example', 'https://site67.example'], $this->listed($html));
        self::assertStringContainsString('<form id="frmRecordsFound" method="get" action="addonmodules.php" class="top-margin-10">', $html, 'the form WHMCS\'s own script submits when a page is picked');
        self::assertStringContainsString('<input type="hidden" name="module" value="mainwp"><input type="hidden" name="tab" value="sites"><input type="hidden" name="search" value="site"><input type="hidden" name="page" value="2">', $html);
        self::assertStringContainsString('<div class="col-md-6 col-sm-12">5 Records Found, Page 2 of 3</div>', $html);
        self::assertStringContainsString('Jump to Page: <div class="btn-group paging-dropdown" role="group"><button type="button" class="btn btn-default btn-xs dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span id="currentPage">2</span> <span class="caret"></span></button>', $html);
        self::assertStringContainsString('<li class="active"><a href="addonmodules.php?module=mainwp&amp;tab=sites&amp;search=site&amp;page=2" data-page="2">2</a></li><li><a href="addonmodules.php?module=mainwp&amp;tab=sites&amp;search=site&amp;page=3" data-page="3">3</a></li>', $html, 'a plain link too, for a browser without script');
        self::assertStringContainsString('<div class="text-center"><ul class="pagination"><li class=""><a href="addonmodules.php?module=mainwp&amp;tab=sites&amp;search=site" class="page-selector" data-page="1">« Previous Page</a></li>', $html);
        self::assertStringContainsString('<li class="active"><a href="addonmodules.php?module=mainwp&amp;tab=sites&amp;search=site&amp;page=2" class="page-selector active" data-page="2">2</a></li>', $html);
        self::assertStringContainsString('data-page="3">Next Page »</a></li></ul></div>', $html);
        self::assertStringContainsString('<li class="disabled"><a href="addonmodules.php?module=mainwp&amp;tab=sites&amp;search=site&amp;page=3" class="page-selector" data-page="3">Next Page »</a>', $this->render(1, 'GET', [], ['tab' => 'sites', 'page' => '9', 'search' => 'site']), 'a page past the last is the last');

        $this->repo->recordsPerPage = null;
        $html = $this->render(1);
        self::assertStringNotContainsString('paging-dropdown', $html, 'one page: nothing to jump to');
        self::assertStringNotContainsString('class="pagination"', $html);
    }

    // Connecting a site

    public function testASiteThatCanBeConnectedHasAConnectButtonThatOpensTheForm(): void
    {
        $this->careSite(68, 'connected', 5);
        $this->careSite(69, 'provisioned');
        $this->careSite(70, 'failed');
        $this->careSite(71, 'connecting');
        $this->careSite(72, 'suspended', 7);
        $this->careSite(73, 'provisioned');
        $this->repo->addons[73] = new \MainWpForWhmcs\AddonRow(73, 1173, 540, 16, 'Active', 'Suspended', 'site73.example', 'Demo Client', 'demo-client@example.com', 0);

        $html = $this->render(1, 'GET', [], ['tab' => 'sites', 'search' => 'site']);

        foreach ([69, 70, 71] as $id) {
            self::assertStringContainsString('<a href="addonmodules.php?module=mainwp&amp;tab=sites&amp;search=site&amp;connect=' . $id . '" class="btn btn-default btn-sm">Connect</a>', $html);
        }
        foreach ([68, 72, 73] as $id) {
            self::assertStringNotContainsString('connect=' . $id . '"', $html, 'connected, suspended, or on a hosting service that is not Active');
        }

        $form = $this->render(1, 'GET', [], ['tab' => 'sites', 'search' => 'site', 'connect' => '69']);
        self::assertStringContainsString('<h2 class="top-margin-10">Connect Site</h2>', $form);
        self::assertStringContainsString('<form method="post" action="addonmodules.php?module=mainwp&amp;tab=sites&amp;search=site&amp;connect=69">', $form);
        self::assertStringContainsString('<td class="fieldarea">Demo Client</td>', $form);
        self::assertStringContainsString('<label for="mainwp-connect-address">Site URL</label></td><td class="fieldarea"><input type="text" id="mainwp-connect-address" name="address" value="https://site69.example" class="form-control input-400">', $form, 'MainWP\'s own words for the fields of its Add Site form');
        self::assertStringContainsString('<label for="mainwp-connect-username">Administrator Username</label></td><td class="fieldarea"><input type="text" id="mainwp-connect-username" name="site_username" value="" autocomplete="off" class="form-control input-400"></td></tr>', $form, 'the label stays; no help under the field');
        self::assertStringContainsString('<label for="mainwp-connect-password">Administrator Password</label></td><td class="fieldarea"><input type="password" id="mainwp-connect-password" name="site_password" value="" autocomplete="new-password" class="form-control input-400"></td></tr>', $form);
        self::assertStringNotContainsString('help-block', $form);
        self::assertStringNotContainsString('to reconnect the site', $form);
        self::assertStringContainsString('<div class="btn-container"><button type="submit" class="btn btn-primary">Connect Site</button> <a href="addonmodules.php?module=mainwp&amp;tab=sites&amp;search=site" class="btn btn-default">Cancel</a></div>', $form, 'Cancel goes back to the list as it was');
        self::assertStringNotContainsString('sortabletbl1', $form, 'the form stands in the list\'s place');

        foreach (['68', '72', '73', '999', '0', 'x', ['69']] as $not) {
            self::assertStringContainsString('sortabletbl1', $this->render(1, 'GET', [], ['tab' => 'sites', 'connect' => $not]), 'no form for a site that cannot be connected: the list');
        }
    }

    public function testASiteWhoseAddOnIsNoLongerTickedAsCareHasNoConnectButton(): void
    {
        $this->careSite(69, 'provisioned');
        $this->repo->addons[69] = new \MainWpForWhmcs\AddonRow(69, 1169, 540, 5, 'Active', 'Active', 'site69.example', 'Demo Client', 'demo-client@example.com', 0);

        $html = $this->render(1);

        self::assertStringContainsString('https://site69.example', $html, 'it is still listed, with its client and service');
        self::assertStringContainsString('<a href="clientssummary.php?userid=540">Demo Client</a>', $html);
        self::assertStringNotContainsString('connect=69', $html, 'the form would refuse it, so the list does not offer it');
        self::assertStringContainsString('sortabletbl1', $this->render(1, 'GET', [], ['tab' => 'sites', 'connect' => '69']));
    }

    public function testTheListsOwnButtonsComeBackToTheListAsItWasShown(): void
    {
        $this->careSite(68, 'connected', 5);
        $this->careSite(69, 'provisioned');

        $html = $this->render(1, 'GET', [], ['tab' => 'sites', 'search' => 'site', 'orderby' => 'client', 'order' => 'desc']);
        self::assertStringContainsString('<form method="post" action="addonmodules.php?module=mainwp&amp;tab=sites&amp;search=site&amp;orderby=client&amp;order=desc" class="top-margin-10">', $html, 'Check Now keeps the search and the order');

        $after = $this->render(1, 'POST', ['action' => 'daily_check'], ['tab' => 'sites', 'search' => 'site69']);
        self::assertSame(['https://site69.example'], $this->listed($after), 'and the list it comes back to is the one that was shown');
    }

    public function testConnectingFromTheFormRunsTheStaffConnectAndSaysWhatHappened(): void
    {
        $this->careSite(69, 'provisioned');

        $html = $this->render(1, 'POST', ['action' => 'connect', 'address' => 'https://site69.example', 'site_username' => 'owner', 'site_password' => 's3cret pass'], ['tab' => 'sites', 'connect' => '69']);

        self::assertStringContainsString('<div class="alert alert-success">', $html);
        self::assertStringContainsString('sortabletbl1', $html, 'back on the list');
        self::assertStringNotContainsString('s3cret', $html);
        $row = end($this->repo->log);
        self::assertSame(['admin:1', 'connect', 'add-on 69', 1], [$row['actor'], $row['action'], $row['target'], $row['dry_run']], 'in dry run the connect is logged, not made');
        self::assertStringNotContainsString('s3cret', json_encode($this->repo->log, JSON_THROW_ON_ERROR));
    }

    public function testAConnectFormThatDoesNotGoThroughStaysOpenWithWhatWasTypedButThePassword(): void
    {
        $this->careSite(69, 'provisioned');

        $html = $this->render(1, 'POST', ['action' => 'connect', 'address' => 'https://site69.example', 'site_username' => 'owner <b>', 'site_password' => ''], ['tab' => 'sites', 'connect' => '69']);
        self::assertStringContainsString('<div class="alert alert-danger">Enter the administrator username and password.</div>', $html);
        self::assertStringContainsString('name="site_username" value="owner &lt;b&gt;"', $html);
        self::assertStringContainsString('<h2 class="top-margin-10">Connect Site</h2>', $html);

        $html = $this->render(1, 'POST', ['action' => 'connect', 'address' => 'https://elsewhere.example', 'site_username' => 'owner', 'site_password' => 's3cret pass'], ['tab' => 'sites', 'connect' => '69']);
        self::assertStringContainsString('<div class="alert alert-danger">https://elsewhere.example is not part of this hosting service (site69.example); nothing was sent.</div>', $html);
        self::assertStringContainsString('name="address" value="https://elsewhere.example"', $html);
        self::assertStringContainsString('name="site_password" value=""', $html);
        self::assertStringNotContainsString('s3cret', $html);

        $this->repo->careSites[69]['state'] = 'connected';
        $html = $this->render(1, 'POST', ['action' => 'connect', 'address' => '', 'site_username' => 'owner', 'site_password' => 's3cret pass'], ['tab' => 'sites', 'connect' => '69']);
        self::assertStringContainsString('<div class="alert alert-danger">This site cannot be connected now.</div>', $html);
        self::assertStringContainsString('sortabletbl1', $html);
    }

    // Settings

    public function testTheSettingsAreWhmcssOwnFormTableWithEachFieldsWordsBeneathIt(): void
    {
        $html = $this->render(1, 'GET', [], 'settings');

        self::assertStringContainsString('<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">', $html, 'WHMCS\'s own settings table');
        self::assertStringContainsString('<td class="fieldlabel label-top"><label for="mainwp-dashboard-url">MainWP Dashboard URL</label></td><td class="fieldarea"><input type="url" id="mainwp-dashboard-url" class="form-control input-400" name="dashboard_url" value="https://dash.example.com"', $html);
        self::assertStringContainsString('<option value="dry_run" selected>Dry Run</option><option value="live">Live</option>', $html);
        self::assertSame([], WhmcsCalls::$api, 'the page never decrypts the key');

        // Words after an input start where the input ends, which is a different place on every row: each field's words are a line beneath it.
        preg_match_all('#<td class="fieldarea">(.*?)</td></tr>#s', $html, $cells);
        self::assertCount(6, $cells[1]);
        foreach ($cells[1] as $cell) {
            self::assertDoesNotMatchRegularExpression('#<(input|select)[^>]*>\s*<span class="text-muted">#', $cell);
            self::assertSame(0, substr_count($cell, 'text-muted'), 'help is a help-block line');
        }
        self::assertSame(6, substr_count($html, '<td class="fieldlabel label-top"'), 'every label stands at the top of its row, level with its field');
        self::assertSame(6, preg_match_all('#<td class="fieldlabel label-top"[^>]*><label#', $html), 'and every one is a label');
        self::assertGreaterThanOrEqual(4, substr_count($html, 'class="form-control input-400'), 'the fields share one width');
        foreach (['Access', 'configaddonmods.php', 'Leave this empty', 'is stored', 'Asks the dashboard', 'the dashboard is not called', 'wpcare'] as $gone) {
            self::assertStringNotContainsString($gone, $html, 'the page says what is needed and no more');
        }
        self::assertStringContainsString('<label for="mainwp-care-email">Care Account Email</label></td><td class="fieldarea"><input type="text" id="mainwp-care-email" class="form-control input-400" name="care_email" value="" placeholder="wordpress@example.com"><p class="help-block">The care account on every site gets this address.</p>', $html, 'what to enter, what it is for, and what happens without it');
    }

    public function testASavedKeyIsAGreyedFieldOfAsterisksThatSaysSavedWithAButtonThatOpensIt(): void
    {
        $html = $this->render(1, 'GET', [], 'settings');
        self::assertStringContainsString('<label for="mainwp-api-key">API Key</label></td><td class="fieldarea"><input type="text" value="********************************" id="mainwp-api-key" class="form-control input-400 input-inline" readonly disabled> <a href="addonmodules.php?module=mainwp&amp;tab=settings&amp;key=change" class="btn btn-default">Change</a><p class="help-block">Saved.</p>', $html, 'read-only as well as disabled: the Iris theme greys a read-only field');
        self::assertStringNotContainsString('name="api_key"', $html, 'a closed field sends nothing, so nothing can fill it in by itself');
        self::assertStringNotContainsString(self::KEY, $html);
        self::assertStringNotContainsString($this->repo->settings['api_key'], $html, 'not even encrypted');

        $open = $this->render(1, 'GET', [], ['tab' => 'settings', 'key' => 'change']);
        self::assertStringContainsString('<input type="text" name="api_key" value="" autocomplete="off" spellcheck="false" id="mainwp-api-key" class="form-control input-400 input-inline"> <a href="addonmodules.php?module=mainwp&amp;tab=settings" class="btn btn-default">Cancel</a><p class="help-block">Made in MainWP under API Access, Add API Keys.</p>', $open, 'a plain field: what is pasted can be seen, and a browser fills no password in');
        self::assertStringNotContainsString(self::KEY, $open);
        self::assertStringNotContainsString('********', $open);

        unset($this->repo->settings['api_key']);
        $none = $this->render(1, 'GET', [], 'settings');
        self::assertStringContainsString('<input type="text" name="api_key" value="" autocomplete="off" spellcheck="false" id="mainwp-api-key" class="form-control input-400"><p class="help-block">Made in MainWP under API Access, Add API Keys.</p>', $none, 'no key yet: open, with nothing to cancel');
        self::assertStringNotContainsString('Saved.', $none);
    }

    public function testTheCareAddOnsAreTheOnesWhoseModuleSettingsNameTheModuleListedByNameWithoutTickBoxes(): void
    {
        $this->repo->productAddons = [16 => 'WordPress Care', 4 => 'Extra &amp; Storage', 9 => 'Backups'];
        $this->repo->addonModules = [16 => 'mainwp', 4 => 'mainwp', 9 => ''];
        $this->repo->settings['care_addon_ids'] = '9';
        $html = $this->render(1, 'GET', [], 'settings');
        self::assertStringContainsString('<label>Care Add-ons</label></td><td class="fieldarea"><a href="configaddons.php?action=manage&amp;id=4">Extra &amp; Storage</a>, <a href="configaddons.php?action=manage&amp;id=16">WordPress Care</a><p class="help-block">A site is cared for when its hosting service has one of these product add-ons.</p>', $html, 'by WHMCS\'s name, each a link to its page in WHMCS; the old stored list is not read');
        self::assertStringNotContainsString('care_addon_ids', $html);
        self::assertStringNotContainsString('type="checkbox"', $html);
        self::assertStringNotContainsString('Backups', $html);
    }

    public function testWithNoCareAddOnTheSettingsTabSaysWhereToSetOne(): void
    {
        $this->repo->productAddons = [16 => 'Offsite Backup', 3 => 'Site Care Plan'];
        $this->repo->addonModules = [];
        $html = $this->render(1, 'GET', [], 'settings');
        self::assertStringContainsString('<label>Care Add-ons</label></td><td class="fieldarea">None yet. Choose MainWP for WHMCS as the module on a <a href="configaddons.php">product add-on\'s Module Settings</a>.</td></tr>', $html);
        self::assertStringNotContainsString('Offsite Backup', $html);
        self::assertStringNotContainsString('care_addon_ids', $html);
    }

    public function testASaveSendsNoListAndStoresNone(): void
    {
        unset($this->repo->settings['care_addon_ids']);
        $html = $this->render(1, 'POST', ['action' => 'save', 'dashboard_url' => 'https://dash.example.com', 'mode' => 'dry_run'], 'settings');
        self::assertStringNotContainsString('tick at least one', $html);
        self::assertArrayNotHasKey('care_addon_ids', $this->repo->settings);
        self::assertSame([16], Settings::load($this->repo)->careAddonIds);
    }

    public function testTheSettingsTabHasNoTestClient(): void
    {
        $this->repo->settings['test_client_id'] = '540';
        foreach ([fn (): string => $this->render(1, 'GET', [], 'settings'), function (): string {
            $this->live();
            return $this->render(1, 'GET', [], 'settings');
        }] as $render) {
            $html = $render();
            self::assertStringNotContainsString('test_client_id', $html);
            self::assertStringNotContainsString('Test Client', $html);
        }
        $this->counts();
        $this->render(1, 'POST', ['dashboard_url' => 'https://dash.example.com', 'mode' => 'dry_run', 'test_client_id' => '999'], 'settings');
        self::assertSame('540', $this->repo->settings['test_client_id'], 'a row stored by an earlier version is left where it is');
    }

    public function testLiveIsNotSavedWithoutACareAccountEmail(): void
    {
        $this->counts();
        $html = $this->render(1, 'POST', ['action' => 'save', 'dashboard_url' => 'https://dash.example.com', 'mode' => 'live', 'care_email' => ''], 'settings');
        self::assertStringContainsString('<li>Mode: Live needs a Care Account Email; enter it, or choose Dry Run.</li>', $html);
        self::assertTrue(Settings::load($this->repo)->dryRun);
    }

    public function testTheDailyDigestIsADropDownThatSaysWhoReceivesIt(): void
    {
        $this->repo->systemEmailAdmins = ['Ada Admin', 'Bea'];
        $this->repo->settings['report_by_email'] = 'on';
        $html = $this->render(1, 'GET', [], 'settings');
        self::assertStringContainsString('<label for="mainwp-report">Daily Digest</label></td><td class="fieldarea"><select id="mainwp-report" class="form-control input-400" name="report_by_email"><option value="off">Off</option><option value="new" selected>When something is new</option><option value="daily">Every day</option></select><p class="help-block">Sent to Ada Admin, Bea.</p>', $html, 'the old ticked box reads as When something is new');
        self::assertStringNotContainsString('type="checkbox" id="mainwp-report"', $html);

        $this->repo->systemEmailAdmins = [];
        self::assertStringContainsString('<p class="help-block">No admin role receives WHMCS system emails, so it cannot be sent.</p>', $this->render(1, 'GET', [], 'settings'));

        $this->counts();
        $html = $this->render(1, 'POST', ['dashboard_url' => 'https://dash.example.com', 'mode' => 'dry_run', 'report_by_email' => 'daily'], 'settings');
        self::assertStringContainsString('<option value="daily" selected>Every day</option>', $html);
        self::assertSame(Settings::DIGEST_DAILY, Settings::load($this->repo)->dailyDigest);

        $html = $this->render(1, 'POST', ['dashboard_url' => 'https://dash.example.com', 'mode' => 'dry_run', 'report_by_email' => 'weekly'], 'settings');
        self::assertStringContainsString('Daily Digest: choose Off, When something is new or Every day.', $html);
        self::assertSame(Settings::DIGEST_DAILY, Settings::load($this->repo)->dailyDigest);
    }

    public function testTheLogsRetentionIsSetOnTheLogTabInMonthsAndKeepsEverythingAtNought(): void
    {
        $html = $this->render(1, 'GET', [], 'log');
        self::assertStringContainsString('<label for="mainwp-retention">Log Retention</label> <input type="text" id="mainwp-retention" class="form-control input-100" name="log_retention_months" value="0"> months <button type="submit" name="action" value="save_retention" class="btn btn-default">Save</button>', $html, 'where the log is, as the owner asked');
        self::assertStringContainsString('<p class="help-block">The daily check deletes older entries. 0 keeps every entry.</p>', $html);

        $saved = $this->render(1, 'POST', ['action' => 'save_retention', 'log_retention_months' => ' 12 '], ['tab' => 'log', 'search' => 'provision']);
        self::assertStringContainsString('<div class="alert alert-success">Changes Saved Successfully!</div>', $saved);
        self::assertSame(12, Settings::load($this->repo)->logRetentionMonths);
        self::assertStringContainsString('name="log_retention_months" value="12"', $saved);
        self::assertStringContainsString('<form method="post" action="addonmodules.php?module=mainwp&amp;tab=log&amp;search=provision" class="form-inline top-margin-10">', $saved, 'it comes back to the list as it was shown');
        self::assertTrue(Settings::load($this->repo)->dryRun, 'nothing else of the settings is touched');

        $refused = $this->render(1, 'POST', ['action' => 'save_retention', 'log_retention_months' => 'a year <b>'], 'log');
        self::assertStringContainsString('<div class="alert alert-danger">Log Retention: enter a whole number of months. 0 keeps every entry.</div>', $refused);
        self::assertStringContainsString('name="log_retention_months" value="a year &lt;b&gt;"', $refused, 'what was typed stays to be corrected');
        self::assertSame(12, Settings::load($this->repo)->logRetentionMonths);
    }

    public function testASaveStoresTheSettingsLogsWhoChangedTheModeAndTestsTheConnection(): void
    {
        $http = $this->counts();

        $html = $this->render(1, 'POST', ['action' => 'save', 'dashboard_url' => 'https://dash2.example.com', 'api_key' => self::KEY, 'mode' => 'live', 'report_by_email' => 'new', 'care_email' => 'wordpress@care.example.com', 'log_retention_months' => '0'], 'settings');

        self::assertStringContainsString('<div class="alert alert-success">Changes Saved Successfully!</div>', $html, 'WHMCS\'s own words for a saved form');
        self::assertStringContainsString('<div class="alert alert-success">Connected to https://dash2.example.com: 1 client, 1 site.</div>', $html, 'a saved address and key are proved at once');
        self::assertSame('https://dash2.example.com/wp-json/mainwp/v2/clients/count', $http->sent[0]['url']);
        self::assertSame('Bearer ' . self::KEY, $http->sent[0]['headers']['Authorization']);
        self::assertStringContainsString('<option value="live" selected>Live</option>', $html, 'the form shows the mode now in force');
        self::assertStringContainsString('<li class="active"><a href="addonmodules.php?module=mainwp&amp;tab=settings">Settings</a></li>', $html, 'a save comes back to the Settings tab');
        self::assertFalse(Settings::load($this->repo)->dryRun);
        $rows = array_map(static fn (array $r): array => [$r['actor'], $r['action'], $r['target'], $r['outcome'], $r['dry_run'], $r['detail']], array_slice($this->repo->log, 1));
        self::assertSame(['admin:1', 'settings', 'module settings', 'done', 0, 'saved; mode live'], $rows[0]);
        self::assertSame(['admin:1', 'test connection', 'dashboard', 'done', 0, 'Connected to https://dash2.example.com: 1 client, 1 site.'], end($rows));
        self::assertStringNotContainsString(self::KEY, $html . json_encode($this->repo->log, JSON_THROW_ON_ERROR));
    }

    public function testANewDashboardAddressWithoutTheKeyTypedAgainIsRefusedAndNothingIsSent(): void
    {
        $http = new FixtureHttp();
        Module::$httpFactory = static fn (): FixtureHttp => $http;
        $before = $this->repo->settings;

        $html = $this->render(1, 'POST', ['action' => 'save', 'dashboard_url' => 'https://dash2.example.com', 'mode' => 'live'], 'settings');

        self::assertStringContainsString('<li>Enter the API key again for the new address.</li>', $html);
        self::assertStringNotContainsString('Changes Saved', $html);
        self::assertSame($before, $this->repo->settings);
        self::assertSame([], $http->sent, 'the stored key goes nowhere');
    }

    public function testAKeyTheDashboardRefusesIsSaidTheMomentItIsSavedInDryRunToo(): void
    {
        Module::$httpFactory = static fn (): FixtureHttp => (new FixtureHttp())->on('GET', 'clients/count', 'key-wrong');

        $html = $this->render(1, 'POST', ['dashboard_url' => 'https://dash.example.com', 'api_key' => self::TYPED, 'mode' => 'dry_run', 'test_client_id' => '540'], 'settings');

        self::assertStringContainsString('<div class="alert alert-success">Changes Saved Successfully!</div>', $html);
        self::assertStringContainsString('<div class="alert alert-danger">The dashboard refused the API key (HTTP 401: Invalid OAuth parameter). Make a key on the dashboard under API Access and enter it in the module&#039;s settings. If the key is right, the dashboard&#039;s web server may not be passing the Authorization header on to WordPress.</div>', $html, 'on 11 November 2026 this was learnt only after the mode had been switched to live');
        self::assertTrue(Settings::load($this->repo)->dryRun);
        self::assertStringNotContainsString(self::TYPED, $html);

        $this->repo->settings['dashboard_url'] = '';
        $http = new FixtureHttp();
        Module::$httpFactory = static fn (): FixtureHttp => $http;
        $this->render(1, 'POST', ['dashboard_url' => '', 'mode' => 'dry_run'], 'settings');
        self::assertSame([], $http->sent, 'nothing to test without an address');
    }

    public function testTestConnectionIsABesideSaveChangesAndBothSaveTheFormThenTestWhatWasTyped(): void
    {
        $html = $this->render(1, 'GET', [], 'settings');
        self::assertStringContainsString('<div class="btn-container"><button type="submit" class="btn btn-primary">Save Changes</button> <button type="submit" class="btn btn-default">Test Connection</button></div>' . "\n" . '</form>', $html, 'the test beside the save, after the fields, in the same form');
        self::assertStringNotContainsString('form="', $html, 'no second form that would test the saved settings and drop what was typed');
        self::assertStringNotContainsString('test_connection', $html);
        self::assertSame(1, substr_count($html, '<form '), 'one form');

        // Pressed with a new address and a new key typed, in dry run: both are saved, and they are what is tested.
        $http = $this->counts();
        $html = $this->render(1, 'POST', ['action' => 'save', 'dashboard_url' => 'https://new.example.com', 'api_key' => self::TYPED, 'mode' => 'dry_run', 'test_client_id' => '540'], 'settings');

        self::assertStringContainsString('<div class="alert alert-success">Changes Saved Successfully!</div>', $html);
        self::assertStringContainsString('<div class="alert alert-success">Connected to https://new.example.com: 1 client, 1 site.</div>', $html);
        self::assertSame(['https://new.example.com/wp-json/mainwp/v2/clients/count', 'https://new.example.com/wp-json/mainwp/v2/sites/count'], array_column($http->sent, 'url'), 'it reads two numbers and no more');
        self::assertSame(['GET', 'GET'], array_column($http->sent, 'method'));
        self::assertSame('Bearer ' . self::TYPED, $http->sent[0]['headers']['Authorization'], 'the key typed, not the one saved before');
        self::assertSame(self::TYPED, Settings::load($this->repo)->apiKey(new WhmcsEncrypter()));
        self::assertTrue(Settings::load($this->repo)->dryRun, 'the test runs in dry run too');
        self::assertStringNotContainsString(self::TYPED, $html);
        self::assertStringContainsString('<p class="help-block">Saved.</p>', $html, 'the key field is closed again over the new key');
        $rows = array_map(static fn (array $r): array => [$r['actor'], $r['action'], $r['target'], $r['outcome'], $r['detail']], $this->repo->log);
        self::assertSame([
            ['admin:1', 'dashboard: counts', 'dashboard', 'done', '1 clients, 1 sites'],
            ['admin:1', 'test connection', 'dashboard', 'done', 'Connected to https://new.example.com: 1 client, 1 site.'],
        ], array_slice($rows, -2));

        // A direct post of the action the page no longer offers saves like any other, with what it carries: it cannot test without saving.
        $before = $this->repo->settings;
        $this->render(1, 'POST', ['action' => 'test_connection'], 'settings');
        self::assertSame($before, $this->repo->settings, 'and a form without its fields is refused, so nothing is lost');
    }

    public function testASaveWithoutAnAddressOrAKeySaysWhatIsMissingToConnect(): void
    {
        unset($this->repo->settings['api_key']);
        $http = new FixtureHttp();
        Module::$httpFactory = static fn (): FixtureHttp => $http;

        $html = $this->render(1, 'POST', ['dashboard_url' => 'https://dash.example.com', 'mode' => 'dry_run'], 'settings');

        self::assertStringContainsString('<div class="alert alert-success">Changes Saved Successfully!</div>', $html);
        self::assertStringContainsString('<div class="alert alert-info">Enter the MainWP Dashboard URL and an API key to connect.</div>', $html);
        self::assertSame([], $http->sent);
    }

    public function testAnUnreachableDashboardIsShownInPlainWords(): void
    {
        Module::$httpFactory = static fn (): FixtureHttp => (new FixtureHttp())->on('GET', 'clients/count', new \RuntimeException('Could not resolve host: dash.example.com'));

        $html = $this->render(1, 'POST', ['dashboard_url' => 'https://dash.example.com', 'mode' => 'dry_run'], 'settings');

        self::assertStringContainsString('<div class="alert alert-danger">The dashboard at dash.example.com could not be reached to count the clients: Could not resolve host: dash.example.com.</div>', $html);
        self::assertSame('failed', end($this->repo->log)['outcome']);
    }

    public function testARefusedSaveShowsTheProblemsAndTheTypedValuesAndSavesNothing(): void
    {
        $http = new FixtureHttp();
        Module::$httpFactory = static fn (): FixtureHttp => $http;
        $before = $this->repo->settings;

        $html = $this->render(1, 'POST', ['dashboard_url' => 'http://plain.example.com', 'api_key' => self::TYPED, 'mode' => 'live', 'care_email' => 'not an address'], 'settings');

        self::assertStringContainsString('<div class="alert alert-danger"><p>The settings were not saved:</p><ul><li>MainWP Dashboard URL: enter the dashboard&#039;s https:// address.</li><li>Care Account Email: enter an email address such as wordpress@example.com.</li></ul></div>', $html);
        self::assertStringContainsString('name="dashboard_url" value="http://plain.example.com"', $html);
        self::assertStringContainsString('name="care_email" value="not an address"', $html);
        self::assertStringContainsString('<option value="live" selected>Live</option>', $html);
        self::assertStringContainsString('<a href="configaddons.php?action=manage&amp;id=16">WordPress Care</a>', $html, 'the care add-ons are shown as WHMCS has them');
        self::assertStringNotContainsString(self::TYPED, $html, 'a typed key is never echoed back');
        self::assertStringContainsString('name="api_key" value=""', $html, 'the key field is open again, to be pasted into once more');
        self::assertStringNotContainsString('API Key:', $html, 'the key itself was a key');
        self::assertStringNotContainsString('Changes Saved', $html);
        self::assertSame($before, $this->repo->settings);
        self::assertSame([], $http->sent, 'nothing is tested that was not saved');

        // A form refused while the saved key's field was closed leaves it closed: there is nothing to paste again.
        $closed = $this->render(1, 'POST', ['dashboard_url' => 'http://plain.example.com', 'mode' => 'live'], 'settings');
        self::assertStringContainsString('<p class="help-block">Saved.</p>', $closed);
        self::assertStringNotContainsString('name="api_key"', $closed);
    }

    public function testWhatIsNotAKeyIsRefusedOnThePageAndNotEchoed(): void
    {
        $before = $this->repo->settings;
        $html = $this->render(1, 'POST', ['dashboard_url' => 'https://dash.example.com', 'api_key' => 'https://dash.example.com/wp-admin/admin.php?page=RESTAPI', 'mode' => 'live'], 'settings');

        self::assertStringContainsString('<li>API Key: this is not a MainWP API key. On the dashboard, copy it with the button beside &quot;API key (Bearer token)&quot;.</li>', $html);
        self::assertStringNotContainsString('page=RESTAPI', $html, 'what was typed for the key is never echoed back');
        self::assertStringNotContainsString('Changes Saved', $html);
        self::assertSame($before, $this->repo->settings);
    }

    // Log

    public function testTheLogIsWhmcssOwnListNewestFirstWithNothingAboveIt(): void
    {
        $this->repo->addLog(['created_at' => '2026-11-10 09:00:00', 'actor' => 'cron:daily', 'action' => 'daily check', 'target' => 'all care add-ons', 'outcome' => 'done', 'dry_run' => 0, 'detail' => 'nothing to report']);

        $html = $this->render(1, 'GET', [], 'log');

        self::assertStringContainsString('<table id="sortabletbl1" class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">', $html, 'WHMCS\'s own list table');
        self::assertStringContainsString('<tr><td class="text-nowrap">2026-11-10 09:00:00</td><td class="text-nowrap">cron:daily</td><td class="text-nowrap">Daily check</td><td class="text-nowrap">all care add-ons</td><td class="text-nowrap">Done</td><td>nothing to report</td></tr>' . "\n" . '<tr><td class="text-nowrap">2026-11-09 13:00:00</td><td class="text-nowrap">hook:AddonActivated</td><td class="text-nowrap">Provision</td><td class="text-nowrap">add-on 68</td><td class="text-nowrap">Would run (dry run)</td><td>would build &lt;script&gt;alert(1)&lt;/script&gt;</td></tr>', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('2 Records Found, Page 1 of 1', $html);
        self::assertStringContainsString('<th><a href="addonmodules.php?module=mainwp&amp;tab=log&amp;orderby=time&amp;order=asc">Time</a> <img src="images/desc.gif" class="absmiddle" alt="desc"></th>', $html);
        self::assertStringNotContainsString('most recent entries', $html, 'no note above the list');
        self::assertStringNotContainsString('<p class="text-muted">', $html);
        self::assertSame([], WhmcsCalls::$api);

        $this->repo->log = [];
        $empty = $this->render(1, 'GET', [], 'log');
        self::assertStringContainsString('<tr><td colspan="6">No Records Found</td></tr>', $empty);
        self::assertStringNotContainsString('clear_log', $empty, 'nothing to clear');
    }

    public function testTheLogIsSearchedSortedAndCutIntoPages(): void
    {
        $this->repo->log = [];
        $this->repo->recordsPerPage = '2';
        foreach ([['2026-11-10 09:00:00', 'cron:daily', 'connect', 'add-on 66', 'failed', 'captcha'], ['2026-11-10 10:00:00', 'admin:1', 'connect', 'add-on 66', 'done', 'connected'], ['2026-11-11 00:03:54', 'cron:daily', 'daily check', 'all care add-ons', 'done', 'nothing'], ['2026-11-11 08:30:49', 'admin:1', 'test connection', 'dashboard', 'failed', 'refused <b>'], ['2026-11-11 08:36:26', 'admin:1', 'test connection', 'dashboard', 'done', 'answered']] as [$at, $actor, $action, $target, $outcome, $detail]) {
            $this->repo->addLog(['created_at' => $at, 'actor' => $actor, 'action' => $action, 'target' => $target, 'outcome' => $outcome, 'dry_run' => 0, 'detail' => $detail]);
        }
        $details = static function (string $html): array {
            preg_match_all('#<td>([^<]*)</td></tr>#', $html, $m);
            return $m[1];
        };

        self::assertSame(['answered', 'refused &lt;b&gt;'], $details($this->render(1, 'GET', [], 'log')));
        $second = $this->render(1, 'GET', [], ['tab' => 'log', 'page' => '2']);
        self::assertSame(['nothing', 'connected'], $details($second));
        self::assertStringContainsString('5 Records Found, Page 2 of 3', $second);
        self::assertStringContainsString('<a href="addonmodules.php?module=mainwp&amp;tab=log&amp;page=3" class="page-selector" data-page="3">Next Page »</a>', $second);

        $failed = $this->render(1, 'GET', [], ['tab' => 'log', 'outcome' => 'failed', 'orderby' => 'time', 'order' => 'asc']);
        self::assertSame(['captcha', 'refused &lt;b&gt;'], $details($failed));
        self::assertStringContainsString('<option value="">Any</option><option value="done">Done</option><option value="failed" selected>Failed</option>', $failed, 'the outcomes the log holds');
        self::assertStringContainsString('<div class="tab-pane active" id="mainwp-filter">', $failed);
        self::assertStringContainsString('<input type="hidden" name="module" value="mainwp"><input type="hidden" name="tab" value="log"><input type="hidden" name="orderby" value="time"><input type="hidden" name="order" value="asc"><table class="form"', $failed, 'a search keeps the order');
        self::assertStringContainsString('<a href="addonmodules.php?module=mainwp&amp;tab=log&amp;orderby=time&amp;order=asc" class="btn btn-default">Reset</a>', $failed);

        self::assertSame(['connected', 'captcha'], $details($this->render(1, 'GET', [], ['tab' => 'log', 'search' => 'ADD-ON 66'])));
        self::assertSame(['connected', 'refused &lt;b&gt;'], $details($this->render(1, 'GET', [], ['tab' => 'log', 'orderby' => 'by'])), 'by who did it, A to Z, then as written');
        self::assertStringContainsString('<th><a href="addonmodules.php?module=mainwp&amp;tab=log&amp;orderby=by&amp;order=desc">By</a> <img src="images/asc.gif" class="absmiddle" alt="asc"></th>', $this->render(1, 'GET', [], ['tab' => 'log', 'orderby' => 'by']));
        self::assertSame(['answered', 'refused &lt;b&gt;'], $details($this->render(1, 'GET', [], ['tab' => 'log', 'outcome' => 'exploded', 'orderby' => 'detail'])), 'an outcome the log has not and a heading that does not sort change nothing');
    }

    public function testClearingTheLogAsksFirstAndLeavesOneEntrySayingWhoClearedIt(): void
    {
        $html = $this->render(1, 'GET', [], 'log');
        self::assertStringContainsString('<button type="submit" name="action" value="clear_log" class="btn btn-default pull-right">Clear Log</button>', $html);

        $ask = $this->render(1, 'POST', ['action' => 'clear_log'], ['tab' => 'log', 'outcome' => 'would run']);
        self::assertCount(1, $this->repo->log, 'asking deletes nothing');
        self::assertStringContainsString('<p>Delete the 1 log entry? This cannot be undone.</p>' . "\n" . '<button type="submit" name="action" value="clear_log_confirmed" class="btn btn-danger">Clear Log</button> <a href="addonmodules.php?module=mainwp&amp;tab=log&amp;outcome=would%20run" class="btn btn-default">Cancel</a>', $ask, 'Cancel goes back to the list as it was');
        self::assertStringNotContainsString('value="clear_log" class', $ask, 'one question at a time');

        $this->repo->addLog(['created_at' => '2026-11-10 09:00:00', 'actor' => 'cron:daily', 'action' => 'daily check', 'target' => 'all care add-ons', 'outcome' => 'done', 'dry_run' => 0, 'detail' => 'nothing to report']);
        $ask = $this->render(1, 'POST', ['action' => 'clear_log'], ['tab' => 'log', 'outcome' => 'would run']);
        self::assertStringContainsString('<p>Delete all 2 log entries? This cannot be undone.</p>', $ask, 'every entry goes, not only the ones the filter shows');

        $done = $this->render(1, 'POST', ['action' => 'clear_log_confirmed'], 'log');
        self::assertStringContainsString('<div class="alert alert-success">Log cleared.</div>', $done);
        self::assertSame([['admin:1', 'log', 'log', 'cleared', '2 entries deleted']], array_map(static fn (array $r): array => [$r['actor'], $r['action'], $r['target'], $r['outcome'], $r['detail']], $this->repo->log));
        self::assertStringContainsString('1 Records Found, Page 1 of 1', $done);
    }

    public function testTheModuleOutputFunctionRendersForTheSessionsAdmin(): void
    {
        \WHMCS\Session::$values['adminid'] = 1;
        ob_start();
        \mainwp_output(['modulelink' => self::LINK]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('<div class="mainwp-admin">', $html);
        self::assertStringContainsString('value="test-token"', $html, 'the token comes from WHMCS\'s generate_token');
    }

    public function testTheModuleOutputFunctionOpensTheTabInTheAddress(): void
    {
        \WHMCS\Session::$values['adminid'] = 1;
        $_GET = ['module' => 'mainwp', 'tab' => 'settings'];
        try {
            ob_start();
            \mainwp_output(['modulelink' => self::LINK]);
            $html = (string) ob_get_clean();
        } finally {
            $_GET = [];
        }
        self::assertStringContainsString('<li class="active"><a href="addonmodules.php?module=mainwp&amp;tab=settings">Settings</a></li>', $html);
    }

    public function testWhmcsEncodedInputIsDecodedOnceBeforeSaving(): void
    {
        \WHMCS\Session::$values['adminid'] = 1;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        // WHMCS HTML-encodes request input at init: "&" arrives as "&amp;"
        $_POST = ['dashboard_url' => 'https://dash.example.com/a&amp;b', 'api_key' => self::TYPED, 'mode' => 'dry_run'];
        $this->counts();
        try {
            ob_start();
            \mainwp_output(['modulelink' => self::LINK]);
            $html = (string) ob_get_clean();
        } finally {
            $_POST = [];
            unset($_SERVER['REQUEST_METHOD']);
        }
        self::assertStringContainsString('Changes Saved Successfully!', $html);
        self::assertSame('https://dash.example.com/a&b', Settings::load($this->repo)->dashboardUrl);
        self::assertSame(self::TYPED, Settings::load($this->repo)->apiKey(new WhmcsEncrypter()));
    }

    public function testThePageSaysWhenItCannotBeShownWithoutAFilePath(): void
    {
        \WHMCS\Session::$values['adminid'] = 1;
        Module::$repositoryFactory = static function (): never {
            throw new \RuntimeException('SQLSTATE[HY000] in /var/www/whmcs/vendor/x/Connection.php:12');
        };
        ob_start();
        \mainwp_output(['modulelink' => self::LINK]);
        self::assertSame('<div class="alert alert-danger">MainWP for WHMCS could not show this page: SQLSTATE[HY000] in (path)</div>', ob_get_clean());
    }

    /**
     * Smarty's policy covers plugin tags and modifiers, PHP functions and static classes; its own
     * syntax (assignment, literal blocks, nofilter, object calls) it does not. So every {...} block of
     * the template is checked: a tag must be if/foreach (or their else and closing forms), the only
     * modifier is escape, and no block calls or assigns anything.
     */
    public function testEveryTemplateUsesOnlyTheAllowedTagsAndModifiers(): void
    {
        $files = glob(Template::dir() . '/*.tpl') ?: [];
        self::assertContains(Template::dir() . '/admin.tpl', $files);
        foreach ($files as $file) {
            $this->assertTemplateIsPlain($file);
        }
    }

    private function assertTemplateIsPlain(string $file): void
    {
        $source = (string) preg_replace('/\{\*.*?\*\}/s', '', (string) file_get_contents($file));
        preg_match_all('/\{[^{}]*\}/', $source, $blocks);
        self::assertNotSame([], $blocks[0]);
        $tags = [];
        $modifiers = [];
        foreach ($blocks[0] as $block) {
            if (preg_match('/^\{\/?([a-z_]+)\b/', $block, $m) === 1) {
                $tags[] = $m[1];
            } elseif (preg_match('/^\{\$[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_]+)*\|escape\}$/', $block) !== 1) {
                self::fail('not an escaped variable output: ' . $block);
            }
            preg_match_all('/\|([a-z_]+)/', $block, $m);
            array_push($modifiers, ...$m[1]);
            self::assertDoesNotMatchRegularExpression('/::|->|\(|\bnofilter\b|(?<![=!<>])=(?!=)/', $block, 'a call, assignment or filter switch in ' . $block);
        }
        self::assertSame([], array_diff(array_unique($tags), ['if', 'elseif', 'else', 'foreach', 'foreachelse']));
        self::assertSame(['escape'], array_values(array_unique($modifiers)));
    }

    /** {assign} and {$x=1} are Smarty internals no policy can refuse; the template scan above catches them. */
    public function testThePolicyRefusesWhatWhmcsWould(): void
    {
        $dir = sys_get_temp_dir() . '/mainwp-policy-' . getmypid();
        @mkdir($dir, 0700, true);
        try {
            foreach (['{$x|upper}', '{"a"|strrev}', '{if is_file("/etc/passwd")}x{/if}', '{$x|escape:"htmlall"|nl2br}', '{$smarty.server.PATH}', '{include file="/etc/passwd"}', '{fetch file="https://example.com"}'] as $i => $source) {
                file_put_contents($dir . "/t$i.tpl", $source);
                try {
                    Template::render("t$i.tpl", ['x' => 'a'], $dir);
                    self::fail('rendered under the policy: ' . $source);
                } catch (\SmartyException $e) {
                    self::assertNotSame('', $e->getMessage());
                }
            }
        } finally {
            // The folder goes too: it used to be left behind, one for every run.
            array_map('unlink', glob($dir . '/*.tpl') ?: []);
            @rmdir($dir);
        }
    }
}
