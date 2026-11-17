<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\Engine\SiteFailure;
use MainWpForWhmcs\Engine\SiteIndex;
use MainWpForWhmcs\Hooks;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Tests\Engine\RecordingSite;
use MainWpForWhmcs\Tests\Support\FixtureHttp;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

require_once __DIR__ . '/Engine/Fakes.php';

/**
 * The staff connect: the login typed into the Connect Site form of the module's Sites list
 * (StaffConnect::attempt, behind WHMCS's token and the module's access check), and the care panel
 * on the admin service page, which shows the site and links to that form. One place connects a site.
 */
final class StaffConnectTest extends TestCase
{
    private MemoryRepository $repo;
    private RecordingSite $site;
    private FixtureHttp $http;

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        $this->repo->settings = ['dry_run' => '', 'access' => '1,2', 'dashboard_url' => 'https://dash.example.com', 'api_key' => (new WhmcsEncrypter())->encrypt('bench-key-0123456789')];
        $this->repo->addonModules = [16 => 'mainwp'];
        $this->repo->adminRoles = [1 => 1, 3 => 3];
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
        \WHMCS\Session::$values['adminid'] = 1;
    }

    protected function tearDown(): void
    {
        Module::$repositoryFactory = null;
        Module::$siteFactory = null;
        Module::$httpFactory = null;
        $_POST = [];
    }

    /** @param array<string, string> $fields */
    private function save(array $fields, int $addonRowId = 67, int $serviceId = 1176): void
    {
        // As the module's page hands it over: WHMCS's HTML-encoding of the POST taken off once.
        $admin = \WHMCS\Session::$values['adminid'] ?? null;
        \MainWpForWhmcs\StaffConnect::attempt($this->repo, $serviceId, is_int($admin) ? $admin : null, $addonRowId, \MainWpForWhmcs\Admin\Page::decodeInput($fields));
    }

    private function panel(): string
    {
        return Hooks::adminServiceFields(['id' => 1176])['WordPress Care (add-on 67)'] ?? '';
    }

    public function testThePanelLinksToTheConnectSiteFormAndHoldsNoFormOfItsOwn(): void
    {
        $html = $this->panel();
        self::assertStringContainsString('<a href="addonmodules.php?module=mainwp&amp;tab=sites&amp;connect=67" class="btn btn-default btn-sm">Connect Site</a>', $html);
        self::assertStringNotContainsString('<input', $html, 'one place connects a site: the module\'s Sites list');
        self::assertStringNotContainsString('password', $html);
    }

    public function testSavingTheServiceWithALoginConnectsTheSiteAsTheAdmin(): void
    {
        $this->save(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'O&#039;Brien\\1']);

        self::assertSame(['index https://clientsite.example', 'installPlugin https://clientsite.example owner'], $this->site->calls, 'its public index first: the address must be the site\'s own');
        self::assertSame('O%5C%27Brien%5C%5C1', $this->http->sent[0]['form']['adminpassword']);
        self::assertSame('connected', $this->repo->careSites[67]['state']);
        self::assertSame('admin:1', $this->repo->log[array_key_last($this->repo->log)]['actor']);
        self::assertStringNotContainsString('Brien', json_encode($this->repo->log, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Brien', $this->panel());
    }

    public function testAFailureShowsInThePanelWithTheRetryEntry(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::INSTALL_FORBIDDEN, 'The site does not allow plugins to be installed from its dashboard.');

        $this->save(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);

        $html = $this->panel();
        self::assertStringContainsString('Failed', $html);
        self::assertStringContainsString('The site does not allow plugins to be installed from its dashboard.', $html);
        self::assertStringContainsString('<strong>Issue:</strong> ', $html);
        self::assertStringContainsString('Next Retry:</strong> 20', $html);
        self::assertStringContainsString('connect=67"', $html, 'the way to the form is offered again');
    }

    public function testSavingTheServiceWithoutALoginDoesNothing(): void
    {
        $this->save(['address' => 'clientsite.example', 'username' => '', 'password' => '']);
        $this->save(['address' => 'clientsite.example', 'username' => 'owner', 'password' => '']);

        self::assertSame([], $this->site->calls);
        self::assertSame([], $this->repo->log);
    }

    public function testAnAddressOutsideTheServiceIsRefusedAndTheRefusalShowsInThePanel(): void
    {
        $this->save(['address' => 'example.com', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertSame([], $this->site->calls);
        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
        self::assertStringContainsString('example.com is not part of this hosting service', $this->panel());
    }

    public function testAServiceWithNoDomainIsSaidInPlainWords(): void
    {
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', '', 'Demo Client', 'demo@example.com', 0);

        $outcome = \MainWpForWhmcs\StaffConnect::attempt($this->repo, 1176, 1, 67, ['address' => '', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertNotNull($outcome);
        self::assertSame([false, 'This hosting service has no domain, so no site can be connected to it.'], [$outcome->ok, $outcome->message]);
        self::assertSame([], $this->site->calls);
        self::assertSame('refused', $this->repo->log[array_key_last($this->repo->log)]['outcome']);
        self::assertStringNotContainsString('()', json_encode($this->repo->log, JSON_THROW_ON_ERROR));
    }

    public function testASiteAnotherCareAddOnOfTheClientCoversIsRefusedBeforeAnythingIsSentOrStored(): void
    {
        $this->repo->addons[68] = new AddonRow(68, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[68] = ['addon_row_id' => 68, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example', 'dashboard_client_id' => 1, 'dashboard_site_id' => 5, 'state' => 'connected', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null];

        $outcome = \MainWpForWhmcs\StaffConnect::attempt($this->repo, 1176, 1, 67, ['address' => 'www.clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertNotNull($outcome);
        self::assertSame([false, 'This site is already covered by add-on 68.'], [$outcome->ok, $outcome->message]);
        self::assertSame([[], []], [$this->site->calls, $this->http->sent]);
        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
        self::assertArrayNotHasKey('login_encrypted', $this->repo->careSites[67]);
        self::assertSame('refused', $this->repo->log[array_key_last($this->repo->log)]['outcome']);
        self::assertStringNotContainsString('Secret1', json_encode($this->repo->log, JSON_THROW_ON_ERROR));

        $outcome = \MainWpForWhmcs\StaffConnect::attempt($this->repo, 1176, 1, 67, ['address' => '', 'username' => 'owner', 'password' => 'Secret1']);
        self::assertSame('This site is already covered by add-on 68.', $outcome?->message, 'the default address too');

        $this->repo->careSites[68]['state'] = 'removed';
        $outcome = \MainWpForWhmcs\StaffConnect::attempt($this->repo, 1176, 1, 67, ['address' => '', 'username' => 'owner', 'password' => 'Secret1']);
        self::assertTrue($outcome?->ok, 'a Removed care site covers nothing');
    }

    public function testAnAddressThatIsNotTheSitesOwnIsRefusedAsOnTheClientsPageAndTheRefusalShowsInThePanel(): void
    {
        $this->site->index = new SiteIndex('https://clientsite.example/wp-json/', null, false, 'https://clientsite.example', 'https://clientsite.example');

        $outcome = \MainWpForWhmcs\StaffConnect::attempt($this->repo, 1176, 1, 67, ['address' => 'clientsite.example/nothing-here', 'username' => 'owner', 'password' => 'Secret1']);

        $sentence = 'The WordPress site at https://clientsite.example/nothing-here answers at https://clientsite.example, so please enter that address instead.';
        self::assertSame([false, $sentence], [$outcome?->ok, $outcome?->message]);
        self::assertSame(['index https://clientsite.example/nothing-here'], $this->site->calls, 'the login was sent nowhere');
        self::assertSame(['https://clientsite.example', 'provisioned'], [$this->repo->careSites[67]['site_url'], $this->repo->careSites[67]['state']]);
        self::assertArrayNotHasKey('login_encrypted', $this->repo->careSites[67]);
        $row = $this->repo->log[array_key_last($this->repo->log)];
        self::assertSame(['connect', 'refused', 'admin:1', $sentence . ' The login was not used and is not kept.'], [$row['action'], $row['outcome'], $row['actor'], $row['detail']]);
        self::assertStringContainsString('answers at https://clientsite.example', $this->panel());

        $this->site->calls = [];
        $this->save(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);
        self::assertSame(['index https://clientsite.example', 'installPlugin https://clientsite.example owner'], $this->site->calls, 'the address the site reports is connected');
    }

    public function testAFolderBehindARedirectToTheWwwFormIsRefusedBeforeAnythingIsStored(): void
    {
        $site = new \MainWpForWhmcs\Tests\Engine\WwwMainSite();
        \MainWpForWhmcs\Module::$siteFactory = static fn (): \MainWpForWhmcs\Engine\Site => $site;

        $outcome = \MainWpForWhmcs\StaffConnect::attempt($this->repo, 1176, 1, 67, ['address' => 'clientsite.example/nothing-here', 'username' => 'owner', 'password' => 'Secret1']);

        $sentence = 'The WordPress site at https://clientsite.example/nothing-here answers at https://www.clientsite.example, so please enter that address instead.';
        self::assertSame([false, $sentence], [$outcome?->ok, $outcome?->message]);
        self::assertSame([], array_values(array_filter($site->calls, static fn (string $c): bool => !str_starts_with($c, 'index '))), 'the login was sent nowhere');
        self::assertSame(['https://clientsite.example', 'provisioned'], [$this->repo->careSites[67]['site_url'], $this->repo->careSites[67]['state']]);
        self::assertArrayNotHasKey('login_encrypted', $this->repo->careSites[67]);
        self::assertSame([['connect', 'refused']], array_map(static fn (array $r): array => [$r['action'], $r['outcome']], $this->repo->log));
    }

    public function testAnAdminWhoseRoleHasNoAccessToTheModuleCannotConnect(): void
    {
        \WHMCS\Session::$values['adminid'] = 3;
        $this->save(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);
        \WHMCS\Session::$values['adminid'] = null;
        $this->save(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertSame([], $this->site->calls);
        self::assertStringNotContainsString('connect=67"', $this->panel(), 'nobody logged in: no form');
        \WHMCS\Session::$values['adminid'] = 3;
        self::assertStringNotContainsString('connect=67"', $this->panel());
    }

    public function testAnAddonOfAnotherServiceCannotBeConnectedFromThisOne(): void
    {
        $this->repo->addons[80] = new AddonRow(80, 2000, 600, 16, 'Active', 'Active', 'other.example.com', 'Other', 'o@example.com', 0);
        $this->repo->careSites[80] = ['addon_row_id' => 80, 'service_id' => 2000, 'client_id' => 600, 'site_url' => 'https://other.example.com', 'dashboard_client_id' => 2, 'state' => 'provisioned'];

        $this->save(['address' => 'other.example.com', 'username' => 'owner', 'password' => 'Secret1'], 80, 1176);

        self::assertSame([], $this->site->calls);
    }

    public function testStaffConnectTakesTheAddOnsWhoseModuleSettingsNameTheModuleAndNotTheOldStoredList(): void
    {
        $this->repo->settings['care_addon_ids'] = '16,7';
        $this->repo->productAddons[7] = 'Site Care Plus';
        $this->repo->addons[68] = new AddonRow(68, 1176, 540, 7, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[68] = ['addon_row_id' => 68, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example/shop', 'dashboard_client_id' => 1, 'dashboard_site_id' => null, 'state' => 'provisioned', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null];

        self::assertArrayNotHasKey('Site Care Plus (add-on 68)', Hooks::adminServiceFields(['id' => 1176]), 'add-on 7 is on the old list only');
        $this->save(['address' => 'clientsite.example/shop', 'username' => 'owner', 'password' => 'Secret1'], 68);
        self::assertSame([], $this->site->calls);
        self::assertSame('provisioned', $this->repo->careSites[68]['state']);

        $this->repo->addonModules[7] = 'mainwp';
        self::assertStringContainsString('connect=68"', Hooks::adminServiceFields(['id' => 1176])['Site Care Plus (add-on 68)'] ?? '');
    }

    public function testAConnectedSiteHasNoFormAndIsNotConnectedAgain(): void
    {
        $this->repo->careSites[67]['state'] = 'connected';

        self::assertStringNotContainsString('connect=67"', $this->panel());
        $this->save(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);
        self::assertSame([], $this->site->calls);
    }

    public function testInDryRunStaffCanUseTheFormAndNothingIsSentOrStored(): void
    {
        $this->repo->settings['dry_run'] = 'on';

        self::assertStringContainsString('connect=67"', $this->panel());
        $this->save(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertSame([[], []], [$this->site->calls, $this->http->sent]);
        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
        self::assertArrayNotHasKey('login_encrypted', $this->repo->careSites[67]);
        self::assertStringContainsString('would install MainWP', $this->panel(), 'the panel shows what the dry run would have done');
    }

    public function testStaffAreNotTakenToASiteAnotherCareAddOnCoversByARedirectEither(): void
    {
        $this->repo->addons[68] = new AddonRow(68, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[68] = ['addon_row_id' => 68, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example/shop', 'dashboard_client_id' => 1, 'dashboard_site_id' => 5, 'state' => 'connected', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null, 'retry_after' => null, 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00'];
        $site = new \MainWpForWhmcs\Tests\Engine\MovedSite('https://clientsite.example/shop');
        Module::$siteFactory = static fn (): \MainWpForWhmcs\Engine\Site => $site;

        $outcome = \MainWpForWhmcs\StaffConnect::attempt($this->repo, 1176, 1, 67, ['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertSame([false, 'This site is already covered by add-on 68.'], [$outcome?->ok, $outcome?->message]);
        self::assertNotContains('installPlugin https://clientsite.example/shop owner', $site->calls);
        self::assertSame('https://clientsite.example', $this->repo->careSites[67]['site_url']);
    }

    public function testARefusalInDryRunIsLoggedAsADryRun(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', '', 'Demo Client', 'demo@example.com', 0);

        $outcome = \MainWpForWhmcs\StaffConnect::attempt($this->repo, 1176, 1, 67, ['address' => '', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertSame('This hosting service has no domain, so no site can be connected to it.', $outcome?->message);
        self::assertSame(1, (int) $this->repo->log[array_key_last($this->repo->log)]['dry_run']);
    }

    public function testStaffConnectingAnAddressThatMovedToItsWwwFormConnectsItThere(): void
    {
        $site = new \MainWpForWhmcs\Tests\Engine\MovedSite('https://www.clientsite.example');
        Module::$siteFactory = static fn (): \MainWpForWhmcs\Engine\Site => $site;

        $this->save(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertSame(['index https://clientsite.example', 'index https://www.clientsite.example', 'installPlugin https://clientsite.example owner', 'index https://www.clientsite.example', 'installPlugin https://www.clientsite.example owner'], $site->calls);
        self::assertSame(['connected', 'https://www.clientsite.example'], [$this->repo->careSites[67]['state'], $this->repo->careSites[67]['site_url']]);
    }

    public function testALoginThatWasNotUsedBecauseTheSiteWasBusyIsSaidInTheLogStaffSee(): void
    {
        $this->repo->lockedElsewhere = ['addon:67'];

        $this->save(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);

        $row = $this->repo->log[array_key_last($this->repo->log)];
        self::assertSame(['connect', 'add-on 67', 'refused', 'admin:1'], [$row['action'], $row['target'], $row['outcome'], $row['actor']]);
        self::assertStringContainsString('being connected right now', (string) $row['detail']);
        self::assertStringEndsWith('The login was not used and is not kept.', (string) $row['detail']);
        self::assertStringNotContainsString('Nothing was tried', (string) $row['detail'], 'an attempt at an address that only redirects may have run just before');
        self::assertArrayNotHasKey('login_encrypted', $this->repo->careSites[67]);
        self::assertStringNotContainsString('Secret1', json_encode($this->repo->log, JSON_THROW_ON_ERROR));
        self::assertSame(['index https://clientsite.example'], $this->site->calls, 'only the site\'s public index was read');
    }

    public function testALoginSavedWhileTheHostingServiceIsSuspendedIsNotUsed(): void
    {
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Suspended', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);

        $this->save(['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertSame([[], []], [$this->site->calls, $this->http->sent]);
        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
        self::assertArrayNotHasKey('login_encrypted', $this->repo->careSites[67]);
        $row = $this->repo->log[array_key_last($this->repo->log)];
        self::assertSame(['connect', 'add-on 67', 'refused', 'admin:1'], [$row['action'], $row['target'], $row['outcome'], $row['actor']], 'a login typed into a page loaded before the suspension does not vanish without a word');
        self::assertSame('Its hosting service is Suspended, not Active. The login was not used and is not kept.', $row['detail']);
        self::assertStringNotContainsString('Secret1', json_encode($this->repo->log, JSON_THROW_ON_ERROR));
    }

    public function testTheServicePageSavesNothingForTheModule(): void
    {
        require __DIR__ . '/../modules/addons/mainwp/hooks.php';
        self::assertContains('AdminClientServicesTabFields', array_column(WhmcsCalls::$hooks, 'hook'), 'the panel is drawn there');
        self::assertNotContains('AdminClientServicesTabFieldsSave', array_column(WhmcsCalls::$hooks, 'hook'), 'and nothing is posted from it: the panel links to the one form');
    }
}
