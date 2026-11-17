<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Authentication\CurrentUser;
use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\Admin\Template;
use MainWpForWhmcs\Engine\SiteFailure;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Tests\Engine\RecordingSite;
use MainWpForWhmcs\Tests\Support\FixtureHttp;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

require_once __DIR__ . '/Engine/Fakes.php';

/**
 * The module's client page (index.php?m=mainwp&addon=<row>): the password form a client fills in
 * to connect their site, with the access checks and the dry-run gate of the panel.
 */
final class ClientConnectPageTest extends TestCase
{
    private MemoryRepository $repo;
    private RecordingSite $site;
    private FixtureHttp $http;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../modules/addons/mainwp/mainwp.php';
    }

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        $this->repo->settings = ['dry_run' => '', 'dashboard_url' => 'https://dash.example.com', 'api_key' => (new WhmcsEncrypter())->encrypt('bench-key-0123456789')];
        $this->repo->addonModules = [16 => 'mainwp'];
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[67] = ['addon_row_id' => 67, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example', 'dashboard_client_id' => 1, 'dashboard_site_id' => null, 'state' => 'provisioned', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null];
        $repo = $this->repo;
        Module::$repositoryFactory = static fn (): MemoryRepository => $repo;
        $this->site = new RecordingSite();
        $site = $this->site;
        Module::$siteFactory = static fn (): RecordingSite => $site;
        $this->http = (new FixtureHttp())->on('POST', 'sites/add', 'sites-add-with-client');
        $http = $this->http;
        Module::$httpFactory = static fn (): FixtureHttp => $http;
        \WHMCS\Session::$values['uid'] = 540;
        $_GET = ['m' => 'mainwp', 'addon' => '67'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        Module::$repositoryFactory = null;
        Module::$siteFactory = null;
        Module::$httpFactory = null;
        $_GET = $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /** @return array{page: array<string, mixed>, html: string} */
    private function open(): array
    {
        $page = \mainwp_clientarea([]);
        $html = Template::render($page['templatefile'] . '.tpl', $page['vars']);
        return ['page' => $page, 'html' => $html];
    }

    /** @param array<string, string> $post */
    private function submit(array $post): string
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $post + ['token' => 'test-token', 'action' => 'connect'];
        $html = $this->open()['html'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        return $html;
    }

    public function testAReasonStoredByAnEarlierVersionThatNamesMainWpIsNotShownOnTheConnectPage(): void
    {
        $this->repo->careSites[67]['state'] = 'failed';
        $this->repo->careSites[67]['last_error'] = 'The dashboard refused to add the site: No MainWP Child plugin detected. First, install and activate the plugin and add your site to your MainWP Dashboard afterward.';

        $html = $this->open()['html'];

        self::assertStringContainsString('Your site could not be connected.', $html);
        self::assertStringNotContainsString('Connecting your site failed', $html, 'not said twice');
        self::assertStringNotContainsStringIgnoringCase('mainwp', str_replace('index.php?m=mainwp', '', $html), 'not in a sentence, a field, a class or a link of the page; its own address is WHMCS\'s');
    }

    public function testTheOwnerSeesTheFormWithTheAddressFilledInAndWhatHappensToTheirLogin(): void
    {
        $this->repo->settings['care_email'] = 'wordpress@care.example.com';
        ['page' => $page, 'html' => $html] = $this->open();

        self::assertTrue($page['requirelogin']);
        self::assertSame('client-connect', $page['templatefile']);
        self::assertGreaterThan(0, substr_count($html, '<form'));
        self::assertSame(substr_count($html, '<form'), substr_count($html, '<form method="post" action="index.php?m=mainwp&amp;addon=67"'), 'every form posts to the add-on\'s own page');
        self::assertStringContainsString('value="https://clientsite.example"', $html);
        self::assertStringContainsString('name="username"', $html);
        self::assertStringContainsString('type="password" name="password"', $html);
        self::assertStringContainsString('name="token" value="test-token"', $html);
        self::assertStringContainsString('<p class="small">We log in to your site once with this login, install a plugin that lets us look after your site, and connect it. We then create one administrator account for care, whose name begins with "wpcare", and delete the login you gave us. That account stays on your site when care ends; you can delete it then under Users in your WordPress dashboard. A login captcha or two-factor login on your site stops this, and a login limiter may lock us out for a while; if your site has one of these, please contact support.</p>', $html);
        self::assertStringNotContainsString('kept encrypted', $html);
        self::assertStringNotContainsString('MainWP', $html, 'a client never reads the name of the tool behind the service');
    }

    public function testWithoutACareAccountEmailTheClientIsNotPromisedACareAccount(): void
    {
        $html = $this->open()['html'];

        self::assertStringContainsString('<p class="small">We log in to your site once with this login, install a plugin that lets us look after your site, and connect it; we then delete the login you gave us. A login captcha or two-factor login on your site stops this, and a login limiter may lock us out for a while; if your site has one of these, please contact support.</p>', $html);
        self::assertStringNotContainsString('administrator account for care', $html);
    }

    public function testAPostedLoginConnectsTheSiteAndThePasswordIsNeverShownOrLogged(): void
    {
        $html = $this->submit(['address' => 'https://clientsite.example', 'username' => 'owner', 'password' => 'it&#039;s &quot;Secret&quot;\\1']);

        self::assertStringContainsString('Your site is connected', $html);
        self::assertSame(['index https://clientsite.example', 'installPlugin https://clientsite.example owner'], $this->site->calls, 'its public index first: the address must be the site\'s own');
        self::assertSame('it%5C%27s%20%5C%22Secret%5C%22%5C%5C1', $this->http->sent[0]['form']['adminpassword'], 'WHMCS\'s HTML-encoded input is decoded once, then slashed and encoded as MainWP expects');
        self::assertSame('connected', $this->repo->careSites[67]['state']);
        self::assertStringNotContainsString('Secret', $html);
        self::assertStringNotContainsString('Secret', json_encode($this->repo->log, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Secret', (string) $this->repo->careSites[67]['login_encrypted'], 'stored encrypted with WHMCS\'s encryption');
        self::assertSame('client:540', $this->repo->log[array_key_last($this->repo->log)]['actor']);
    }

    public function testAFailureShowsTheReasonAndThatItWillBeRetried(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::CAPTCHA, 'The login page has a captcha, so the module cannot log in.');

        $html = $this->submit(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('The login page has a captcha, so the module cannot log in.', $html);
        self::assertStringContainsString('We will try again once a day for a week', $html);
        self::assertStringContainsString('name="username"', $html, 'the form is offered again');
        self::assertStringNotContainsString('Secret1', $html, 'the password field is never filled in');
        self::assertSame('failed', $this->repo->careSites[67]['state']);
    }

    public function testTwoCareAddOnsThatBothWaitDoNotKeepEachOtherFromTheSameAddress(): void
    {
        // Two care add-ons on one service start at the same default address. Neither covers it until one is
        // connected (or is being connected); then the other is told so.
        $this->repo->addons[68] = new AddonRow(68, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        foreach (['provisioned', 'failed'] as $waiting) {
            $this->repo->careSites[68] = ['addon_row_id' => 68, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example', 'dashboard_client_id' => 1, 'dashboard_site_id' => null, 'state' => $waiting, 'retry_count' => 0, 'last_sync' => null, 'last_error' => null, 'retry_after' => null, 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00'];
            self::assertNull(\MainWpForWhmcs\ClientPage::coveredBy($this->repo, $this->repo->addons[67], 'https://clientsite.example'), $waiting);
        }
        foreach (['connecting', 'connected', 'suspended'] as $holding) {
            $this->repo->careSites[68]['state'] = $holding;
            self::assertSame(68, \MainWpForWhmcs\ClientPage::coveredBy($this->repo, $this->repo->addons[67], 'https://clientsite.example'), $holding);
        }
        $this->repo->careSites[68]['state'] = 'removed';
        self::assertNull(\MainWpForWhmcs\ClientPage::coveredBy($this->repo, $this->repo->addons[67], 'https://clientsite.example'));
    }

    public function testAnActiveAddOnThatIsNotProvisionedYetSaysOnItsPageWhatItsPanelSays(): void
    {
        unset($this->repo->careSites[67]);

        $html = $this->open()['html'];

        self::assertStringContainsString('Care is being set up.', $html);
        self::assertStringNotContainsString('This page is not available', $html);
        self::assertStringNotContainsString('<form', $html);
    }

    public function testConnectingASiteAnotherCareAddOnOfTheClientCoversIsRefusedBeforeAnythingIsSentOrStored(): void
    {
        // Another service of the same client, on the same domain; the add-on of this page has its own row besides.
        $this->repo->addons[68] = new AddonRow(68, 1177, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[68] = ['addon_row_id' => 68, 'service_id' => 1177, 'client_id' => 540, 'site_url' => 'https://clientsite.example/shop', 'dashboard_client_id' => 1, 'dashboard_site_id' => 5, 'state' => 'connected', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null];

        $html = $this->submit(['address' => 'HTTP://WWW.clientsite.example/shop/', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('This site is already covered by another care add-on of yours. Enter the address of another site on this service, or contact support.', $html);
        self::assertSame([[], [], []], [$this->site->calls, $this->http->sent, $this->repo->log]);
        self::assertSame(['https://clientsite.example', 'provisioned'], [$this->repo->careSites[67]['site_url'], $this->repo->careSites[67]['state']]);
        self::assertArrayNotHasKey('login_encrypted', $this->repo->careSites[67]);
        self::assertStringNotContainsString('Secret1', $html);

        $this->submit(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);
        self::assertSame('connected', $this->repo->careSites[67]['state'], 'another address on the service is connected');
    }

    public function testTheDefaultAddressIsRefusedTooWhenAnotherCareAddOnCoversIt(): void
    {
        $this->repo->addons[68] = new AddonRow(68, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[68] = ['addon_row_id' => 68, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example', 'dashboard_client_id' => 1, 'dashboard_site_id' => 5, 'state' => 'connected', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null];

        $html = $this->submit(['address' => '', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('This site is already covered by another care add-on of yours.', $html);
        self::assertSame([[], []], [$this->site->calls, $this->http->sent]);
    }

    public function testAnotherClientsCareSiteAtTheSameAddressIsNotThisClientsConcern(): void
    {
        $this->repo->careSites[90] = ['addon_row_id' => 90, 'service_id' => 1190, 'client_id' => 541, 'site_url' => 'https://clientsite.example', 'dashboard_client_id' => 2, 'dashboard_site_id' => 6, 'state' => 'connected', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null];

        $html = $this->submit(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringNotContainsString('already covered', $html, 'and nothing of another client is said');
    }

    public function testAServiceWithNoDomainSaysSoWithNoFormOnThePageOrInThePanel(): void
    {
        $sentence = 'This service has no domain, so a site cannot be connected here. Please contact support.';
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', '', 'Demo Client', 'demo@example.com', 0);
        unset($this->repo->careSites[67]); // a care add-on whose service has no domain is not provisioned

        $html = $this->open()['html'];
        self::assertStringContainsString($sentence, $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('<strong></strong>', $html);
        $panel = (string) \MainWpForWhmcs\Tests\Support\ClientSidebar::panel(1176);
        self::assertStringContainsString($sentence, $panel);
        self::assertStringNotContainsString('btn', $panel);
        self::assertStringNotContainsString('<strong></strong>', $panel);

        // A care site kept from before the domain was taken off the service.
        $this->repo->careSites[67] = ['addon_row_id' => 67, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example', 'dashboard_client_id' => 1, 'dashboard_site_id' => null, 'state' => 'provisioned', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null];
        $html = $this->open()['html'];
        self::assertStringContainsString($sentence, $html);
        self::assertStringNotContainsString('<form', $html);
        $panel = (string) \MainWpForWhmcs\Tests\Support\ClientSidebar::panel(1176);
        self::assertStringContainsString($sentence, $panel);
        self::assertStringNotContainsString('btn', $panel);
        $this->submit(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);
        self::assertSame([[], []], [$this->site->calls, $this->http->sent], 'a POST changes nothing');
    }

    public function testAnAddressOutsideTheServiceIsRefusedWithAMessageToContactSupport(): void
    {
        $html = $this->submit(['address' => 'https://example.com', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('not part of this hosting service', $html);
        self::assertStringContainsString('contact support', $html);
        self::assertSame([], $this->site->calls);
        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
    }

    public function testASubdomainOrPathUnderTheServiceIsAccepted(): void
    {
        $this->submit(['address' => 'shop.clientsite.example/store', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertSame(['index https://shop.clientsite.example/store', 'installPlugin https://shop.clientsite.example/store owner'], $this->site->calls);
    }

    public function testAMissingUsernameOrPasswordIsAskedForAgain(): void
    {
        $html = $this->submit(['address' => 'clientsite.example', 'username' => 'owner', 'password' => '']);

        self::assertStringContainsString('Enter the administrator username and password', $html);
        self::assertSame([], $this->site->calls);
    }

    public function testAnotherClientOrAUserWithoutThePermissionGetsNoForm(): void
    {
        \WHMCS\Session::$values['uid'] = 600;
        self::assertStringContainsString('not available', $this->open()['html']);

        \WHMCS\Session::$values['uid'] = 540;
        CurrentUser::$owner = false;
        CurrentUser::$permissions = ['products'];
        self::assertStringContainsString('not available', $this->open()['html']);
        self::assertStringContainsString('not available', $this->submit(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']));
        self::assertSame([], $this->site->calls);

        CurrentUser::$permissions = ['manageproducts'];
        self::assertStringContainsString('name="username"', $this->open()['html']);
    }

    public function testTheOwnerOfAnUnpaidAddonIsToldItWaitsForTheInvoiceAndWhereTheirInvoicesAre(): void
    {
        // The client's own unpaid add-on must not get "This page is not available...".
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Pending', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 1);
        unset($this->repo->careSites[67]);

        $html = $this->open()['html'];

        self::assertStringContainsString('<h2>Your WordPress site</h2>', $html);
        self::assertStringContainsString('<strong>https://clientsite.example</strong>', $html);
        self::assertStringContainsString('We can start looking after your site once the invoice for WordPress Care is paid.', $html);
        self::assertStringContainsString('<a class="btn btn-primary" href="clientarea.php?action=invoices">View your invoices</a>', $html);
        self::assertStringNotContainsString('not available', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertStringNotContainsString('Connect your WordPress site', $html);
    }

    public function testTheOwnerOfASuspendedAddonIsToldWhyAndNothingCanBeConnected(): void
    {
        // The client's own suspended add-on must be told why, not that the page is not available.
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Suspended', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 1);

        $html = $this->open()['html'];
        self::assertStringContainsString('Care is suspended until its invoice is paid.', $html);
        self::assertStringContainsString('View your invoices', $html);
        self::assertStringNotContainsString('<form', $html);

        $html = $this->submit(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);
        self::assertStringContainsString('Care is suspended until its invoice is paid.', $html);
        self::assertSame([[], [], 'provisioned'], [$this->site->calls, $this->http->sent, $this->repo->careSites[67]['state']], 'a POST changes nothing');

        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Suspended', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $html = $this->open()['html'];
        self::assertStringContainsString('Care is suspended.', $html);
        self::assertStringNotContainsString('View your invoices', $html, 'no invoice is unpaid');
    }

    public function testAConnectedSiteTheLastCheckFoundDisconnectedReadsAsOneWeCannotReach(): void
    {
        $this->repo->careSites[67]['state'] = 'connected';
        $this->repo->careSites[67]['dashboard_site_id'] = 5;
        $this->repo->saveSettings(['last_check' => json_encode(['at' => '2026-11-11 00:03:54', 'by' => 'cron:daily', 'dry' => false, 'fault' => null, 'total' => 1, 'new' => 0, 'email' => '', 'lines' => [
            ['k' => 'disconnected:add-on 67', 'a' => 67, 'c' => 'Demo Client (client 540)', 's' => 'https://clientsite.example', 't' => 'Connected', 'p' => 'disconnected on the dashboard and could not be mended', 'n' => false],
        ]], JSON_THROW_ON_ERROR)]);

        $html = $this->open()['html'];
        self::assertStringContainsString('We cannot reach your site at the moment.', $html, 'as the client\'s panel says');
        self::assertStringNotContainsString('connected and cared for', $html);

        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Pending', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $html = $this->open()['html'];
        self::assertStringContainsString('We cannot reach your site at the moment.', $html, 'the page of an add-on that is not active');
        self::assertStringNotContainsString('connected and cared for', $html);
    }

    public function testAnOwnAddonThatHasEndedIsNotShownAsTheClientsPanelDoesNotShowIt(): void
    {
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Cancelled', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 1);

        self::assertStringContainsString('This page is not available.', $this->open()['html']);
    }

    public function testAnotherClientsUnpaidOrSuspendedAddonStillShowsNothing(): void
    {
        $this->repo->addons[71] = new AddonRow(71, 1180, 600, 16, 'Pending', 'Active', 'someone-else.co.za', 'Other Client', 'other@example.com', 1);
        $this->repo->addons[72] = new AddonRow(72, 1181, 600, 16, 'Suspended', 'Active', 'someone-else.co.za', 'Other Client', 'other@example.com', 1);
        foreach (['71', '72'] as $id) {
            $_GET['addon'] = $id;
            $html = $this->open()['html'];
            self::assertStringContainsString('This page is not available. If you expected to connect a WordPress site here, please contact support.', $html, 'add-on ' . $id);
            self::assertStringNotContainsString('someone-else', $html, 'add-on ' . $id);
            self::assertStringNotContainsString('invoice', $html, 'add-on ' . $id);
        }
    }

    public function testAnAddonThatIsNotCareOrNotThereGetsNoForm(): void
    {
        $this->repo->addons[70] = new AddonRow(70, 1176, 540, 5, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        foreach (['70', '999', 'x', ''] as $id) {
            $_GET['addon'] = $id;
            self::assertStringContainsString('not available', $this->open()['html'], 'add-on ' . $id);
        }
    }

    public function testTheConnectPageTakesTheAddOnsWhoseModuleSettingsNameTheModuleAndNotTheOldStoredList(): void
    {
        $this->repo->settings['care_addon_ids'] = '16,7';
        $this->repo->addons[68] = new AddonRow(68, 1176, 540, 7, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[68] = ['addon_row_id' => 68, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example/shop', 'dashboard_client_id' => 1, 'dashboard_site_id' => null, 'state' => 'provisioned', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null];
        $_GET['addon'] = '68';

        self::assertStringContainsString('not available', $this->open()['html'], 'add-on 7 is on the old list only');
        self::assertStringContainsString('not available', $this->submit(['address' => 'clientsite.example/shop', 'username' => 'owner', 'password' => 'Secret1']));
        self::assertSame([], $this->site->calls);

        $this->repo->addonModules[7] = 'mainwp';
        self::assertStringContainsString('name="username"', $this->open()['html']);
    }

    public function testAConnectedSiteShowsItsStateAndNoForm(): void
    {
        $this->repo->careSites[67]['state'] = 'connected';
        $this->repo->careSites[67]['last_sync'] = '2026-11-10 09:00:00';

        $html = $this->open()['html'];

        self::assertStringContainsString('Your site is connected', $html);
        self::assertStringContainsString('2026-11-10 09:00:00', $html);
        self::assertStringNotContainsString('name="username"', $html);
        $this->submit(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);
        self::assertSame([], $this->site->calls, 'a POST changes nothing');
        self::assertSame('connected', $this->repo->careSites[67]['state']);
    }

    public function testInsideWhmcsLastSynchronisedIsInWhmcssOwnDateFormat(): void
    {
        // WHMCS's fromMySQLDate() exists only inside WHMCS: a process of its own stands in for it here.
        $code = 'function fromMySQLDate($d, $time = false) { return date($time ? "d/m/Y H:i" : "d/m/Y", strtotime((string) $d)); }'
            . ' require ' . var_export(__DIR__ . '/bootstrap.php', true) . ';'
            . ' use MainWpForWhmcs\\{AddonRow, ClientPage, Module, Schema}; use MainWpForWhmcs\\Tests\\Support\\MemoryRepository;'
            . ' $repo = new MemoryRepository(); Schema::install($repo); $repo->settings = ["dry_run" => ""]; $repo->addonModules = [16 => "mainwp"];'
            . ' $repo->addons[67] = new AddonRow(67, 1176, 540, 16, "Active", "Active", "clientsite.example", "Demo Client", "demo@example.com", 0);'
            . ' $repo->addons[68] = new AddonRow(68, 1176, 540, 16, "Suspended", "Active", "clientsite.example", "Demo Client", "demo@example.com", 0);'
            . ' foreach ([67 => "connected", 68 => "suspended"] as $id => $state) { $repo->careSites[$id] = ["addon_row_id" => $id, "service_id" => 1176, "client_id" => 540, "site_url" => "https://clientsite.example", "dashboard_client_id" => 1, "dashboard_site_id" => 5, "state" => $state, "retry_count" => 0, "last_sync" => "2026-11-10 13:14:37", "last_error" => null]; }'
            . ' Module::$repositoryFactory = static fn () => $repo;'
            . ' foreach ([67, 68] as $id) { echo ClientPage::vars($repo, ["clientId" => 540, "permitted" => true], "GET", ["addon" => (string) $id], [], "t")["lastSync"], "|"; }';
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');

        self::assertSame('10/11/2026 13:14|10/11/2026 13:14|', $out, 'on the page of an Active add-on and of one that is not');
    }

    public function testInDryRunNoClientGetsThePageAndNothingIsSent(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->repo->settings['test_client_id'] = '540'; // as an earlier version stored it: no longer read
        $html = $this->open()['html'];
        self::assertStringContainsString('This page is not available.', $html);
        self::assertStringNotContainsString('name="username"', $html);

        $html = $this->submit(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('This page is not available.', $html);
        self::assertStringNotContainsString('Dry run', $html);
        self::assertSame([[], []], [$this->site->calls, $this->http->sent]);
        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
        self::assertArrayNotHasKey('login_encrypted', $this->repo->careSites[67]);
    }

    public function testThePanelLinksToTheFormWhileTheSiteWaitsToBeConnected(): void
    {
        self::assertStringContainsString('index.php?m=mainwp&amp;addon=67', \MainWpForWhmcs\Tests\Support\ClientSidebar::panel(1176));

        $this->repo->careSites[67]['state'] = 'connected';
        self::assertStringNotContainsString('m=mainwp', \MainWpForWhmcs\Tests\Support\ClientSidebar::panel(1176));
    }

    /**
     * PHP function names ignore case, so when the add-on module's file is loaded in the same request
     * WHMCS may take mainwp_clientarea for the provisioning module's mainwp_ClientArea and call it with
     * an add-on's module parameters (no modulelink). It answers '' at once.
     */
    public function testWhmcsCallingClientAreaAsTheProvisioningModuleGetsNothing(): void
    {
        $redirects = [];
        Module::$redirector = static function (string $url) use (&$redirects): void {
            $redirects[] = $url;
        };
        try {
            $answer = \mainwp_clientarea(['addonId' => 67, 'serviceid' => 1176, 'userid' => 540, 'pid' => 16, 'serverid' => 0, 'status' => 'Active']);
            self::assertSame('', $answer);
            self::assertSame('', \mainwp_clientarea(['model' => new \stdClass(), 'serviceid' => 1176]), 'the model alone is enough');
        } finally {
            Module::$redirector = null;
        }
        self::assertSame([], $redirects);
        self::assertSame([], WhmcsCalls::$activity);
        self::assertSame([], $this->repo->log);
        self::assertIsArray(\mainwp_clientarea(['modulelink' => 'index.php?m=mainwp', 'addonId' => 67]), 'an add-on module call always has its modulelink');
    }
}
