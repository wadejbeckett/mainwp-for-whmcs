<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Authentication\CurrentUser;
use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\Hooks;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Tests\Support\ClientSidebar;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;

/**
 * The client's panel in the primary sidebar of the service's product details page (ClientAreaPrimarySidebar,
 * given the sidebar, with the service in Menu::context('service')) and the staff panel on the admin service page (AdminClientServicesTabFields, given
 * the service id in 'id'; seen with id 1176 in the trial of 9 November).
 */
final class PanelsTest extends TestCase
{
    private MemoryRepository $repo;

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        $this->repo->settings = ['dry_run' => ''];
        $this->repo->addonModules = [16 => 'mainwp'];
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[67] = ['addon_row_id' => 67, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example', 'dashboard_client_id' => 1, 'dashboard_site_id' => null, 'state' => 'provisioned', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null];
        $repo = $this->repo;
        Module::$repositoryFactory = static fn (): MemoryRepository => $repo;
        \WHMCS\Session::$values['uid'] = 540;
    }

    protected function tearDown(): void
    {
        Module::$repositoryFactory = null;
    }

    private function clientPanel(int $serviceId = 1176): ?string
    {
        return ClientSidebar::panel($serviceId);
    }

    public function testTheOwnerSeesTheStateAndTheConnectPrompt(): void
    {
        $sidebar = ClientSidebar::of(1176);
        Hooks::clientSidebar($sidebar);
        self::assertSame('WordPress Care', $sidebar->getChild('Website Care')?->getLabel());
        $html = $this->clientPanel();
        self::assertStringContainsString('https://clientsite.example', $html);
        self::assertStringContainsString('Care is set up. Next, connect your site so that we can start looking after it.', $html);
        self::assertSame([], WhmcsCalls::$activity);
    }

    public function testThePanelIsOneSidebarPanelBetweenTheServicesOverviewAndItsActions(): void
    {
        $sidebar = ClientSidebar::of(1176);
        Hooks::clientSidebar($sidebar);

        self::assertSame(['Service Details Overview', 'Service Details Actions', 'Website Care'], array_keys($sidebar->getChildren()), 'one panel added, nothing else touched');
        $panel = $sidebar->getChild('Website Care');
        self::assertNotNull($panel);
        self::assertSame('WordPress Care', $panel->getLabel());
        self::assertFalse($panel->hasChildren());
        self::assertTrue($panel->hasBodyHtml());
        self::assertGreaterThan(ClientSidebar::OVERVIEW_ORDER, $panel->getOrder());
        self::assertLessThan(ClientSidebar::ACTIONS_ORDER, $panel->getOrder());
        self::assertStringNotContainsString('<h3', $panel->getBodyHtml(), 'the sidebar draws the frame and the heading');
        self::assertStringNotContainsString('card-header', $panel->getBodyHtml());
        self::assertSame('fas fa-shield-alt', $panel->getIcon(), 'an icon, as WHMCS\'s own panels carry one');
    }

    public function testAWhmcsThatRefusesTheIconStillShowsThePanel(): void
    {
        \WHMCS\View\Menu\Item::$refusesIcons = true;
        $sidebar = ClientSidebar::of(1176);
        Hooks::clientSidebar($sidebar);

        $panel = $sidebar->getChild('Website Care');
        self::assertNotNull($panel);
        self::assertFalse($panel->hasIcon());
        self::assertStringContainsString('Connect your site', $panel->getBodyHtml());
        self::assertSame([], WhmcsCalls::$activity, 'an icon is decoration: its refusal is not a failure to report');
    }

    public function testWithoutTheStockPanelsThePanelIsStillAddedWhereWhmcsPutsIt(): void
    {
        \Menu::$contexts['service'] = (object) ['id' => 1176];
        $sidebar = new \WHMCS\View\Menu\Item('Primary Sidebar');
        Hooks::clientSidebar($sidebar);
        self::assertSame(['Website Care'], array_keys($sidebar->getChildren()));
        self::assertSame(0, $sidebar->getChild('Website Care')?->getOrder(), 'no order set');
    }

    public function testWithoutAServiceInTheMenuContextTheSidebarIsLeftAlone(): void
    {
        foreach ([null, 'service', ['id' => 1176], (object) [], (object) ['id' => 0], (object) ['id' => 'abc']] as $context) {
            \Menu::$contexts = $context === null ? [] : ['service' => $context];
            $sidebar = ClientSidebar::of(null);
            Hooks::clientSidebar($sidebar);
            self::assertSame(['Service Details Overview', 'Service Details Actions'], array_keys($sidebar->getChildren()), var_export($context, true));
        }
        self::assertSame([], WhmcsCalls::$activity);

        \Menu::$contexts['service'] = (object) ['id' => '1176'];
        $sidebar = ClientSidebar::of(null);
        Hooks::clientSidebar($sidebar);
        self::assertNotNull($sidebar->getChild('Website Care'), 'an id given as digits is read');

        Hooks::clientSidebar('not a sidebar');
        self::assertSame([], WhmcsCalls::$activity);
    }

    public function testASecondCareAddonOnTheServiceIsInTheSamePanel(): void
    {
        $this->repo->addons[68] = new AddonRow(68, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[68] = ['addon_row_id' => 68, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example/shop', 'state' => 'connected', 'last_sync' => '2026-11-12 06:00:00', 'last_error' => null];
        $sidebar = ClientSidebar::of(1176);
        Hooks::clientSidebar($sidebar);

        self::assertSame(['Service Details Overview', 'Service Details Actions', 'Website Care'], array_keys($sidebar->getChildren()));
        $html = (string) $sidebar->getChild('Website Care')?->getBodyHtml();
        self::assertStringContainsString('https://clientsite.example</strong>', $html);
        self::assertStringContainsString('index.php?m=mainwp&amp;addon=67', $html);
        self::assertStringContainsString('https://clientsite.example/shop</strong>', $html);
        self::assertStringContainsString('Your site is connected and cared for.', $html);
        self::assertStringContainsString('Last synchronised: 2026-11-12 06:00:00', $html);
        self::assertStringNotContainsString('addon=68', $html, 'a connected site is not offered a connect');
        self::assertSame(1, substr_count($html, 'Connect your site'));
    }

    public function testThePanelTakesTheAddOnsWhoseModuleSettingsNameTheModuleAndNotTheOldStoredList(): void
    {
        $this->repo->settings['care_addon_ids'] = '16,7';
        $this->repo->addons[68] = new AddonRow(68, 1176, 540, 7, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[68] = ['addon_row_id' => 68, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example/shop', 'state' => 'connected', 'last_sync' => null, 'last_error' => null];

        $html = (string) $this->clientPanel();
        self::assertStringContainsString('https://clientsite.example</strong>', $html, 'add-on 16 names the module');
        self::assertStringNotContainsString('/shop', $html, 'add-on 7 is on the old list only');

        $this->repo->addonModules[7] = 'mainwp';
        self::assertStringContainsString('https://clientsite.example/shop</strong>', (string) $this->clientPanel());
    }

    public function testAUserWithoutThePermissionToManageTheProductsSeesNothing(): void
    {
        CurrentUser::$owner = false;
        CurrentUser::$permissions = ['products', 'productsso', 'invoices', 'tickets'];
        self::assertNull($this->clientPanel());

        CurrentUser::$permissions = ['products', 'manageproducts'];
        self::assertStringContainsString('clientsite.example', $this->clientPanel());

        CurrentUser::$permissions = [];
        CurrentUser::$otherClients = [600 => ['manageproducts']];
        self::assertNull($this->clientPanel(), 'the permission on another account does not open this one');
        self::assertSame([], WhmcsCalls::$activity, 'a denial is not a failure');
    }

    public function testAnAdminLoggedInAsTheClientSeesThePanel(): void
    {
        CurrentUser::$owner = false;
        CurrentUser::$masquerading = true;
        self::assertStringContainsString('clientsite.example', $this->clientPanel());
    }

    public function testNobodyLoggedInOrAnotherClientsServiceShowsNothing(): void
    {
        \WHMCS\Session::$values['uid'] = 600;
        self::assertNull($this->clientPanel(), 'the service belongs to client 540');

        \WHMCS\Session::$values['uid'] = 540;
        CurrentUser::$clientId = 600;
        self::assertNull($this->clientPanel(), 'WHMCS\'s authenticated client is not the session\'s');

        CurrentUser::reset();
        CurrentUser::$loggedOut = true;
        self::assertNull($this->clientPanel());
        \WHMCS\Session::$values['uid'] = null;
        self::assertNull($this->clientPanel());
    }

    public function testInDryRunNoClientSeesThePanel(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        self::assertNull($this->clientPanel());
        $this->repo->settings['test_client_id'] = '540'; // as an earlier version stored it: no longer read
        self::assertNull($this->clientPanel());
    }

    public function testAServiceWithoutACareAddonShowsNothing(): void
    {
        $this->repo->addons = [70 => new AddonRow(70, 1176, 540, 5, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0)];
        self::assertNull($this->clientPanel());
        self::assertNull($this->clientPanel(999));
    }

    public function testACancelledCareAddonIsNotShownToTheClient(): void
    {
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Cancelled', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        self::assertNull($this->clientPanel());
    }

    public function testASuspendedAddonReadsAsSuspendedAndIsNotToldToConnect(): void
    {
        // Care was suspended before the site was ever connected: there is nothing on the dashboard, and nothing to connect now.
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Suspended', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 1);

        $html = $this->clientPanel();

        self::assertStringContainsString('Care is suspended until its invoice is paid.', $html, 'the unpaid invoice is why');
        self::assertStringNotContainsString('connect your site', $html);
        self::assertStringNotContainsString('Connect your site', $html);

        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Suspended', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $html = (string) $this->clientPanel();
        self::assertStringContainsString('Care is suspended.', $html, 'suspended by staff, with no invoice open: no reason is made up');
        self::assertStringNotContainsString('invoice', $html);
    }

    public function testCareOnASuspendedHostingServiceIsNotSaidToWaitForItsOwnInvoice(): void
    {
        // Paying the care add-on's invoice does not end the hosting service's suspension (second review of 0.2.1).
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Suspended', 'clientsite.example', 'Demo Client', 'demo@example.com', 1);

        $html = (string) $this->clientPanel();

        self::assertStringContainsString('Care is suspended.', $html);
        self::assertStringNotContainsString('until its invoice is paid', $html);
    }

    public function testWhileTheHostingServiceIsSuspendedCareReadsAsSuspendedAndNothingCanBeConnected(): void
    {
        // The service's module suspended it; WHMCS leaves the add-on Active (trial of 6 November 2026).
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Suspended', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->settings['access'] = '1';
        $this->repo->adminRoles = [1 => 1];
        \WHMCS\Session::$values['adminid'] = 1;

        $html = $this->clientPanel();
        self::assertStringContainsString('Care is suspended.', $html);
        self::assertStringNotContainsString('Connect your site', $html);

        $staff = implode('', Hooks::adminServiceFields(['id' => 1176]));
        self::assertStringNotContainsString('connect=67"', $staff, 'staff are not offered a connect that would put an unsuspended site on the dashboard');

        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        self::assertStringContainsString('Connect your site', $this->clientPanel());
        self::assertStringContainsString('connect=67"', implode('', Hooks::adminServiceFields(['id' => 1176])));
    }

    public function testCareThatIsNotActiveForAnotherReasonIsNotToldToConnectEither(): void
    {
        // A service cancelled or terminated without its module fires nothing the module hears; the add-on is still Active.
        foreach (['Terminated', 'Cancelled', 'Pending'] as $serviceStatus) {
            $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', $serviceStatus, 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
            $html = $this->clientPanel();
            self::assertStringContainsString('Care is not active at the moment.', $html, $serviceStatus);
            self::assertStringNotContainsString('connect your site', $html, $serviceStatus);
            self::assertStringNotContainsString('Connect your site', $html, $serviceStatus);
        }
        // A failed connect keeps none of its "connect again" meaning either.
        $this->repo->careSites[67]['state'] = 'failed';
        $this->repo->careSites[67]['last_error'] = 'The login was refused. Check it and connect again.';
        self::assertStringNotContainsString('connect again', $this->clientPanel());
    }

    public function testAReasonStoredByAnEarlierVersionThatNamesMainWpIsNotShownToTheClient(): void
    {
        // Version 0.4.2 stored these words. Staff still read them; the client does not.
        $this->repo->careSites[67]['state'] = 'failed';
        $this->repo->careSites[67]['last_error'] = 'MainWP\'s plugin could not be installed on the site: Destination folder already exists. Contact support.';

        $html = (string) $this->clientPanel();

        self::assertStringContainsString('Your site could not be connected.', $html);
        self::assertStringNotContainsString('Connecting your site failed', $html, 'not said twice');
        self::assertStringNotContainsString('contact support', $html, 'the client can try again from the page; staff read the reason in the daily digest');
        self::assertStringNotContainsStringIgnoringCase('mainwp', str_replace('index.php?m=mainwp', '', $html), 'only the page\'s own address, which WHMCS gives the module, holds the word');

        $this->repo->settings['access'] = '1';
        $this->repo->adminRoles = [1 => 1];
        \WHMCS\Session::$values['adminid'] = 1;
        $staff = implode('', Hooks::adminServiceFields(['id' => 1176]));
        self::assertStringContainsString('plugin could not be installed on the site: Destination folder already exists', $staff, 'staff read the stored words');
        self::assertStringContainsString('MainWP', $staff);
    }

    public function testAStoredReasonIsShownWhenOnlyTheSitesOwnAddressHoldsTheWord(): void
    {
        $this->repo->careSites[67]['site_url'] = 'https://mainwp-fans.example';
        $this->repo->careSites[67]['state'] = 'failed';
        $this->repo->careSites[67]['last_error'] = 'The site could not be reached at mainwp-fans.example (timed out). Check the address and try again.';

        self::assertStringContainsString('Connecting your site failed: The site could not be reached at mainwp-fans.example', (string) $this->clientPanel());
    }

    public function testStaffReadOnTheServicePageWhyAnAddOnWithNoDomainIsNotProvisioned(): void
    {
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', '', 'Demo Client', 'demo@example.com', 0);
        unset($this->repo->careSites[67]);
        $this->repo->settings['access'] = '1';
        $this->repo->adminRoles = [1 => 1];
        \WHMCS\Session::$values['adminid'] = 1;

        self::assertStringContainsString('its hosting service has no domain, so no site can be connected', implode('', Hooks::adminServiceFields(['id' => 1176])));
    }

    public function testStaffAreToldWhyTheConnectFormIsNotThere(): void
    {
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Suspended', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->settings['access'] = '1';
        $this->repo->adminRoles = [1 => 1];
        \WHMCS\Session::$values['adminid'] = 1;

        $staff = implode('', Hooks::adminServiceFields(['id' => 1176]));
        self::assertStringContainsString('Connecting is not available: its hosting service is Suspended, not Active.', $staff);

        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        self::assertStringNotContainsString('Connecting is not available', implode('', Hooks::adminServiceFields(['id' => 1176])));
        $this->repo->careSites[67]['state'] = 'connected';
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Suspended', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        self::assertStringNotContainsString('Connecting is not available', implode('', Hooks::adminServiceFields(['id' => 1176])), 'only said where a connect form would otherwise be');
    }

    public function testASiteSuspendedOnTheDashboardKeepsItsFullerSentence(): void
    {
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Suspended', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 1);
        $this->repo->careSites[67]['state'] = 'suspended';
        $this->repo->careSites[67]['dashboard_site_id'] = 1;

        $html = (string) $this->clientPanel();
        self::assertStringContainsString('Care is suspended: updates and health checks have stopped.', $html, 'what the service sells, and no uptime checks it does not');
        self::assertStringNotContainsString('uptime', $html);
    }

    public function testEachStateReadsInPlainWords(): void
    {
        $this->repo->addons[68] = new AddonRow(68, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 1);
        $this->repo->addons[69] = new AddonRow(69, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->addons[71] = new AddonRow(71, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[71] = ['addon_row_id' => 71, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example/shop', 'state' => 'failed', 'last_error' => 'the login page shows a captcha <b>', 'last_sync' => null];
        $html = $this->clientPanel();
        self::assertStringContainsString('We can start looking after your site once the invoice for WordPress Care is paid.', $html);
        self::assertStringContainsString('Care is being set up.', $html);
        self::assertStringContainsString('Connecting your site failed: the login page shows a captcha &lt;b&gt;', $html);
        self::assertStringNotContainsString('<b>', $html);
    }

    public function testASiteThatWasConnectedAndBrokeIsNotSaidToHaveFailedToConnect(): void
    {
        // The client did not just try to connect: the connection broke, or the dashboard lost the site.
        self::assertSame('Your site is no longer connected to care. Connect it again.', \MainWpForWhmcs\Panels::stateMessage(\MainWpForWhmcs\Engine\State::Failed, 'Your site is no longer connected to care. Connect it again.'));
        self::assertSame('Your site is no longer connected to care. It is tried again by itself.', \MainWpForWhmcs\Panels::stateMessage(\MainWpForWhmcs\Engine\State::Failed, 'Your site is no longer connected to care. It is tried again by itself.'));
        self::assertSame('Connecting your site failed: The login was refused.', \MainWpForWhmcs\Panels::stateMessage(\MainWpForWhmcs\Engine\State::Failed, 'The login was refused.'));
    }

    public function testAProblemReadingTheDataShowsNothingAndIsReported(): void
    {
        Module::$repositoryFactory = static fn () => throw new \RuntimeException('Table missing in /var/www/x.php');
        $sidebar = ClientSidebar::of(1176);
        Hooks::clientSidebar($sidebar);
        self::assertSame(['Service Details Overview', 'Service Details Actions'], array_keys($sidebar->getChildren()));
        self::assertSame([], Hooks::adminServiceFields(['id' => 1176]));
        self::assertCount(2, WhmcsCalls::$activity);
        self::assertStringStartsWith('MainWP for WHMCS: the client panel for service 1176 failed: Table missing', WhmcsCalls::$activity[0]);
        self::assertStringNotContainsString('/var/www', WhmcsCalls::$activity[0]);
    }

    public function testStaffSeeStateAddressLastSyncAndLastError(): void
    {
        $this->repo->careSites[67]['last_sync'] = '2026-11-10 06:00:00';
        $this->repo->careSites[67]['last_error'] = 'the dashboard <refused> the key';
        $fields = Hooks::adminServiceFields(['id' => 1176]);
        self::assertSame(['WordPress Care (add-on 67)'], array_keys($fields));
        $html = $fields['WordPress Care (add-on 67)'];
        self::assertStringContainsString('Provisioned', $html);
        self::assertStringContainsString('https://clientsite.example', $html);
        self::assertStringContainsString('2026-11-10 06:00:00', $html);
        self::assertStringContainsString('the dashboard &lt;refused&gt; the key', $html);
    }

    /** A connected site the last check found disconnected and could not mend. */
    private function foundDisconnected(): void
    {
        $this->repo->careSites[67]['state'] = 'connected';
        $this->repo->careSites[67]['dashboard_site_id'] = 5;
        $this->repo->careSites[67]['last_sync'] = '2026-11-09 15:55:13';
        $this->repo->saveSettings(['last_check' => json_encode(['at' => '2026-11-11 00:03:54', 'by' => 'cron:daily', 'dry' => false, 'fault' => null, 'total' => 2, 'new' => 0, 'email' => '', 'lines' => [
            ['k' => 'disconnected:add-on 67', 'a' => 67, 'c' => 'Demo Client (client 540)', 's' => 'https://clientsite.example', 't' => 'Connected', 'p' => 'disconnected on the dashboard and could not be mended (the site <could not> be reached)', 'n' => false],
            ['k' => 'disconnected:add-on 68', 'a' => 68, 'c' => 'Demo Client (client 540)', 's' => 'https://other.example', 't' => 'Connected', 'p' => 'another add-on\'s finding', 'n' => false],
        ]], JSON_THROW_ON_ERROR)]);
    }

    public function testASiteTheLastCheckFoundDisconnectedIsNotCalledConnected(): void
    {
        $this->foundDisconnected();

        $html = Hooks::adminServiceFields(['id' => 1176])['WordPress Care (add-on 67)'];
        self::assertStringContainsString('<strong>Status:</strong> <span class="label terminated">Disconnected</span>', $html);
        self::assertStringContainsString('<strong>Issue:</strong> Disconnected on the dashboard and could not be mended (the site &lt;could not&gt; be reached).', $html, 'that add-on\'s lines of the last check');
        self::assertStringNotContainsString('another add-on', $html);
        self::assertStringContainsString('<strong>Last Sync:</strong> 2026-11-09 15:55:13', $html, 'the panel keeps its own rows');

        $client = (string) $this->clientPanel();
        self::assertStringContainsString('We cannot reach your site at the moment.', $client);
        self::assertStringNotContainsString('connected and cared for', $client);
    }

    public function testAConnectedSiteTheLastCheckFoundWellReadsAsConnected(): void
    {
        $this->foundDisconnected();
        $this->repo->careSites[67]['last_error'] = 'an older note';
        $this->repo->saveSettings(['last_check' => json_encode(['at' => '2026-11-11 00:03:54', 'by' => 'cron:daily', 'dry' => false, 'fault' => null, 'total' => 0, 'new' => 0, 'email' => '', 'lines' => []], JSON_THROW_ON_ERROR)]);

        $html = Hooks::adminServiceFields(['id' => 1176])['WordPress Care (add-on 67)'];
        self::assertStringContainsString('<span class="label active">Connected</span>', $html);
        self::assertStringContainsString('<strong>Issue:</strong> an older note', $html);
        self::assertStringContainsString('Your site is connected and cared for.', (string) $this->clientPanel());
    }

    public function testAfterTheRetriesEndedStaffStillSeeWhyTheLastAttemptFailed(): void
    {
        // The client's reason for a fault on our side names no cause; the row that ended the retries does not either.
        $this->repo->careSites[67]['state'] = 'failed';
        $this->repo->careSites[67]['last_error'] = 'We could not finish connecting your site because of a fault on our side.';
        $this->repo->addLog(['created_at' => '2026-11-09 00:00:10', 'actor' => 'cron:daily', 'action' => 'connect', 'target' => 'add-on 67', 'outcome' => 'failed', 'dry_run' => 0, 'detail' => 'The dashboard refused the API key (HTTP 401).']);
        $this->repo->addLog(['created_at' => '2026-11-10 00:00:10', 'actor' => 'cron:daily', 'action' => 'connect', 'target' => 'add-on 67', 'outcome' => 'ended', 'dry_run' => 0, 'detail' => 'no more retries for https://clientsite.example; the stored login and the key are deleted']);

        $html = Hooks::adminServiceFields(['id' => 1176])['WordPress Care (add-on 67)'];

        self::assertStringContainsString('2026-11-10 00:00:10 by cron:daily: ended, no more retries for https://clientsite.example; the stored login and the key are deleted', $html);
        self::assertStringContainsString('The attempt before it failed (2026-11-09 00:00:10): The dashboard refused the API key (HTTP 401).', $html);
    }

    public function testInsideWhmcsTheLastConnectAttemptIsDatedInTheAdminsOwnFormat(): void
    {
        // WHMCS's fromMySQLDate() exists only inside WHMCS: a process of its own stands in for it here.
        $code = 'function fromMySQLDate($d, $time = false) { return date($time ? "d/m/Y H:i" : "d/m/Y", strtotime((string) $d)); }'
            . ' require ' . var_export(__DIR__ . '/bootstrap.php', true) . ';'
            . ' use MainWpForWhmcs\\{AddonRow, Hooks, Module, Schema}; use MainWpForWhmcs\\Tests\\Support\\MemoryRepository;'
            . ' $repo = new MemoryRepository(); Schema::install($repo); $repo->settings = ["dry_run" => ""]; $repo->addonModules = [16 => "mainwp"];'
            . ' $repo->addons[67] = new AddonRow(67, 1176, 540, 16, "Active", "Active", "clientsite.example", "Demo Client", "demo@example.com", 0);'
            . ' $repo->careSites[67] = ["addon_row_id" => 67, "service_id" => 1176, "client_id" => 540, "site_url" => "https://clientsite.example", "dashboard_client_id" => 1, "dashboard_site_id" => null, "state" => "failed", "retry_count" => 0, "last_sync" => null, "last_error" => "x"];'
            . ' $repo->addLog(["created_at" => "2026-11-09 00:00:10", "actor" => "cron:daily", "action" => "connect", "target" => "add-on 67", "outcome" => "failed", "dry_run" => 0, "detail" => "refused"]);'
            . ' $repo->addLog(["created_at" => "2026-11-10 13:14:37", "actor" => "cron:daily", "action" => "connect", "target" => "add-on 67", "outcome" => "ended", "dry_run" => 0, "detail" => "no more retries"]);'
            . ' Module::$repositoryFactory = static fn () => $repo;'
            . ' echo Hooks::adminServiceFields(["id" => 1176])["WordPress Care (add-on 67)"];';
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');

        self::assertStringContainsString('10/11/2026 13:14 by cron:daily: ended, no more retries', $out, $out);
        self::assertStringContainsString('The attempt before it failed (09/11/2026 00:00): refused', $out);
        self::assertStringNotContainsString('2026-11-10 13:14:37', $out);
    }

    public function testInsideWhmcsTheClientsLastSynchronisedIsInWhmcssOwnDateFormat(): void
    {
        // WHMCS's fromMySQLDate() exists only inside WHMCS: a process of its own stands in for it here.
        $code = 'function fromMySQLDate($d, $time = false) { return date($time ? "d/m/Y H:i" : "d/m/Y", strtotime((string) $d)); }'
            . ' require ' . var_export(__DIR__ . '/bootstrap.php', true) . ';'
            . ' use MainWpForWhmcs\\{AddonRow, Panels, Module, Schema}; use MainWpForWhmcs\\Tests\\Support\\MemoryRepository;'
            . ' $repo = new MemoryRepository(); Schema::install($repo); $repo->settings = ["dry_run" => ""]; $repo->addonModules = [16 => "mainwp"];'
            . ' $repo->addons[67] = new AddonRow(67, 1176, 540, 16, "Active", "Active", "clientsite.example", "Demo Client", "demo@example.com", 0);'
            . ' $repo->careSites[67] = ["addon_row_id" => 67, "service_id" => 1176, "client_id" => 540, "site_url" => "https://clientsite.example", "dashboard_client_id" => 1, "dashboard_site_id" => 5, "state" => "connected", "retry_count" => 0, "last_sync" => "2026-11-10 13:14:37", "last_error" => null];'
            . ' Module::$repositoryFactory = static fn () => $repo;'
            . ' echo Panels::client($repo, 1176, ["clientId" => 540, "permitted" => true]);';
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');

        self::assertStringContainsString('Last synchronised: 10/11/2026 13:14', $out, $out);
        self::assertStringNotContainsString('2026-11-10 13:14:37', $out);
    }

    public function testStaffSeeEveryCareAddonWithWhyItIsNotProvisioned(): void
    {
        $this->repo->careSites = [];
        $this->repo->addons[68] = new AddonRow(68, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 1);
        $this->repo->addons[70] = new AddonRow(70, 1176, 540, 5, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->addons[72] = new AddonRow(72, 1176, 540, 16, 'Cancelled', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $fields = Hooks::adminServiceFields(['id' => '1176']);
        self::assertSame(['WordPress Care (add-on 67)', 'WordPress Care (add-on 68)', 'WordPress Care (add-on 72)'], array_keys($fields));
        self::assertStringContainsString('Not provisioned', $fields['WordPress Care (add-on 67)']);
        self::assertStringContainsString('<strong>Last Sync:</strong> Never', $fields['WordPress Care (add-on 67)']);
        self::assertStringContainsString('<strong>Status:</strong> <span class="label pending">Not provisioned</span><br>', $fields['WordPress Care (add-on 67)'], 'the words and the label of the module\'s Sites list');
        self::assertStringContainsString('<span class="label pending">Not provisioned</span> (it has an unpaid invoice)<br>', $fields['WordPress Care (add-on 68)'], 'the reason beside the label, not in it');
        self::assertStringNotContainsString('Issue:', $fields['WordPress Care (add-on 67)'], 'no issue, no line for one');
        self::assertStringContainsString('it has an unpaid invoice', $fields['WordPress Care (add-on 68)']);
        self::assertStringContainsString('the add-on is Cancelled, not Active', $fields['WordPress Care (add-on 72)']);
    }

    public function testStaffSeeThePanelInDryRunWithTheModeNamed(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $fields = Hooks::adminServiceFields(['id' => 1176]);
        self::assertStringContainsString('Dry run', $fields['WordPress Care (add-on 67)']);
    }

    public function testAServiceWithoutCareHasNoStaffPanel(): void
    {
        self::assertSame([], Hooks::adminServiceFields(['id' => 999]));
        self::assertSame([], Hooks::adminServiceFields([]));
    }

    public function testTheHooksFileRegistersBothPanels(): void
    {
        require __DIR__ . '/../modules/addons/mainwp/hooks.php';
        $names = array_column(WhmcsCalls::$hooks, 'hook');
        self::assertContains('ClientAreaPrimarySidebar', $names);
        self::assertContains('AdminClientServicesTabFields', $names);
        self::assertNotContains('ClientAreaProductDetailsOutput', $names, 'one place for the client\'s panel: the sidebar');

        $sidebar = ClientSidebar::of(1176);
        foreach (WhmcsCalls::$hooks as $hook) {
            if ($hook['hook'] === 'ClientAreaPrimarySidebar') {
                self::assertNull(($hook['callable'])($sidebar), 'WHMCS uses nothing the hook returns');
            }
        }
        self::assertStringContainsString('clientsite.example', (string) $sidebar->getChild('Website Care')?->getBodyHtml());
    }
}
