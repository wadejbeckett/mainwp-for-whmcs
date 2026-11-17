<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\Engine\HttpResponse;
use MainWpForWhmcs\Hooks;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Tests\Support\FixtureHttp;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

/**
 * The provisioning decision as WHMCS's hooks reach it, with the parameters the trial of 6 and
 * 9 November recorded: the add-on events carry id (the add-on row), userid, serviceid and addonid
 * (the catalogue id); InvoicePaid carries invoiceid.
 */
final class ProvisionHooksTest extends TestCase
{
    private MemoryRepository $repo;
    private FixtureHttp $http;

    /** AddonActivated after AcceptOrder of order 2060 on service 1176 (trial run log, 6 November). */
    private const ACTIVATED = ['id' => 67, 'userid' => 540, 'serviceid' => 1176, 'addonid' => 16];

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        $this->repo->settings = ['dry_run' => '', 'dashboard_url' => 'https://dash.example.com', 'api_key' => (new WhmcsEncrypter())->encrypt('bench-key-0123456789')];
        $this->repo->addonModules = [16 => 'mainwp'];
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo-client@example.com', 0);
        $this->http = new FixtureHttp();
        $repo = $this->repo;
        $http = $this->http;
        Module::$repositoryFactory = static fn (): MemoryRepository => $repo;
        Module::$httpFactory = static fn (): FixtureHttp => $http;
    }

    protected function tearDown(): void
    {
        Module::$repositoryFactory = null;
        Module::$httpFactory = null;
    }

    private function dashboardMakesTheClient(): void
    {
        $this->http->on('GET', 'clients?page=1&per_page=100', 'clients-list-empty')->on('POST', 'clients/add', 'clients-add');
    }

    /** @return list<array{0: mixed, 1: mixed}> action and outcome of each log row */
    private function logged(): array
    {
        return array_map(static fn (array $r): array => [$r['action'], $r['outcome']], $this->repo->log);
    }

    public function testTheHooksFileRegistersTheThreeProvisioningEvents(): void
    {
        require __DIR__ . '/../modules/addons/mainwp/hooks.php';
        $names = array_column(WhmcsCalls::$hooks, 'hook');
        foreach (['AddonActivated', 'AddonActivation', 'InvoicePaid'] as $hook) {
            self::assertContains($hook, $names);
        }
    }

    public function testAddonActivatedOnAPaidAddonProvisionsTheClientOnTheDashboard(): void
    {
        $this->dashboardMakesTheClient();
        Hooks::addonEvent('AddonActivated', self::ACTIVATED);

        self::assertSame(['name' => 'Demo Client', 'client_email' => 'demo-client@example.com', 'note' => 'WHMCS client 540'], $this->http->sent[1]['form']);
        $row = $this->repo->careSites[67];
        self::assertSame('provisioned', $row['state']);
        self::assertSame(1, $row['dashboard_client_id']);
        self::assertSame(1176, $row['service_id']);
        self::assertSame(540, $row['client_id']);
        self::assertSame('https://clientsite.example', $row['site_url'], 'the address defaults to the service\'s domain');
        self::assertSame('whmcs:AddonActivated', $this->repo->log[2]['actor']);
    }

    public function testAddonActivationWithItsDocumentedParametersProvisionsAlike(): void
    {
        $this->dashboardMakesTheClient();
        Hooks::addonEvent('AddonActivation', ['id' => 67, 'userid' => 540, 'clientid' => 540, 'serviceid' => 1176, 'addonid' => 16]);
        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
    }

    public function testAnExistingDashboardClientIsFoundNotMadeAgain(): void
    {
        $this->http->on('GET', 'clients?page=1&per_page=100', 'clients-list');
        Hooks::addonEvent('AddonActivated', self::ACTIVATED);
        self::assertSame(['clients?page=1&per_page=100', 'clients?page=2&per_page=100'], array_map(static fn (array $sent): string => (string) preg_replace('~^.*/wp-json/mainwp/v2/~', '', $sent['url']), $this->http->sent), 'only the list, read until a page brings nothing new; no clients/add');
        self::assertSame(1, $this->repo->careSites[67]['dashboard_client_id']);
    }

    /** Version 0.6.0: the dashboard refuses the client because a client made by hand there has the same email. */
    public function testACustomerTheDashboardAlreadyHoldsByEmailIsFiledUnderThatClient(): void
    {
        $byHand = new HttpResponse(200, '{"success":1,"total":1,"data":[{"id":"12","name":"Demo by hand","note":"","client_email":"Demo-Client@Example.com"}]}');
        $this->http->on('GET', 'clients?page=1&per_page=100', $byHand, $byHand)->on('POST', 'clients/add', 'clients-add-duplicate-email');

        Hooks::addonEvent('AddonActivated', self::ACTIVATED);

        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
        self::assertSame(12, $this->repo->careSites[67]['dashboard_client_id']);
        self::assertSame(['GET', 'GET', 'POST', 'GET', 'GET'], array_map(static fn (array $sent): string => $sent['method'], $this->http->sent), 'nothing is written to the dashboard client');
        $done = array_values(array_filter($this->repo->log, static fn (array $r): bool => $r['action'] === 'provision'));
        self::assertSame(['done', 'dashboard client 12 (found by its email; its note was not changed), https://clientsite.example Provisioned'], [$done[0]['outcome'], $done[0]['detail']]);
        foreach ($this->repo->log as $row) {
            self::assertStringNotContainsStringIgnoringCase('demo-client@example.com', (string) $row['detail'], 'no email address in the log');
        }
    }

    /** Version 0.6.0: no site can be connected at no address. */
    public function testInvoicePaidForAnAddonWhoseServiceHasNoDomainWaits(): void
    {
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', '', 'Demo Client', 'demo-client@example.com', 0);
        $this->repo->invoiceAddons[60500] = [67];

        Hooks::invoicePaid(['invoiceid' => 60500, 'invoice' => new \stdClass()]);

        self::assertSame([[], []], [$this->http->sent, $this->repo->careSites]);
        self::assertSame([['provision', 'waiting']], $this->logged());
        self::assertSame('not provisioned: its hosting service has no domain, so no site can be connected', $this->repo->log[0]['detail']);
    }

    public function testASecondEventForTheSameAddonDoesNothing(): void
    {
        $this->dashboardMakesTheClient();
        Hooks::addonEvent('AddonActivated', self::ACTIVATED);
        $sent = count($this->http->sent);
        $log = count($this->repo->log);

        Hooks::addonEvent('AddonActivation', self::ACTIVATED);
        Hooks::invoicePaid(['invoiceid' => 60500]);
        $this->repo->invoiceAddons[60500] = [67];
        Hooks::invoicePaid(['invoiceid' => 60500]);

        self::assertCount($sent, $this->http->sent, 'no second call to the dashboard');
        self::assertCount($log, $this->repo->log, 'nothing logged for the duplicate');
    }

    public function testAnAcceptedOrderWithAnUnpaidInvoiceWaitsForPayment(): void
    {
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo-client@example.com', 1);
        Hooks::addonEvent('AddonActivated', self::ACTIVATED);

        self::assertSame([], $this->http->sent);
        self::assertSame([], $this->repo->careSites);
        self::assertSame([['provision', 'waiting']], $this->logged());
        self::assertStringContainsString('unpaid invoice', (string) $this->repo->log[0]['detail']);
    }

    public function testInvoicePaidProvisionsTheAddonsOnThatInvoiceOnceTheInvoiceIsPaid(): void
    {
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo-client@example.com', 1);
        Hooks::addonEvent('AddonActivated', self::ACTIVATED);

        // The client pays invoice 60500, which itemised add-on row 67.
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo-client@example.com', 0);
        $this->repo->invoiceAddons[60500] = [67];
        $this->dashboardMakesTheClient();
        Hooks::invoicePaid(['invoiceid' => 60500, 'invoice' => new \stdClass()]);

        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
        self::assertSame('whmcs:InvoicePaid', $this->repo->log[count($this->repo->log) - 1]['actor']);
    }

    public function testAnInvoiceWithNoCareAddonDoesNothing(): void
    {
        Hooks::invoicePaid(['invoiceid' => 60249]);
        self::assertSame([], $this->http->sent);
        self::assertSame([], $this->repo->log);
    }

    public function testAnAddonThatIsNotActiveIsNotProvisioned(): void
    {
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Pending', 'Active', 'clientsite.example', 'Demo Client', 'demo-client@example.com', 0);
        Hooks::addonEvent('AddonActivation', self::ACTIVATED);
        self::assertSame([], $this->http->sent);
        self::assertSame([], $this->repo->careSites);
        self::assertSame([['provision', 'waiting']], $this->logged());
    }

    public function testAnAddonOnAServiceThatIsNotActiveIsNotProvisioned(): void
    {
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Suspended', 'clientsite.example', 'Demo Client', 'demo-client@example.com', 0);
        Hooks::addonEvent('AddonActivated', self::ACTIVATED);
        self::assertSame([], $this->http->sent);
        self::assertSame([], $this->repo->careSites);
    }

    public function testOnlyAddonsWhoseModuleSettingsNameTheModuleCount(): void
    {
        $this->repo->addons[70] = new AddonRow(70, 1176, 540, 5, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo-client@example.com', 0);
        Hooks::addonEvent('AddonActivated', ['id' => 70, 'userid' => 540, 'serviceid' => 1176, 'addonid' => 5]);
        self::assertSame([], $this->http->sent);
        self::assertSame([], $this->repo->log, 'another add-on is not the module\'s business');

        $this->repo->addonModules[5] = 'mainwp';
        $this->dashboardMakesTheClient();
        Hooks::addonEvent('AddonActivated', ['id' => 70, 'userid' => 540, 'serviceid' => 1176, 'addonid' => 5]);
        self::assertSame('provisioned', $this->repo->careSites[70]['state']);
    }

    public function testTheCatalogueIdIsReadFromWhmcsNotTakenFromTheEvent(): void
    {
        Hooks::addonEvent('AddonActivated', ['id' => 70, 'userid' => 540, 'serviceid' => 1176, 'addonid' => 16]);
        self::assertSame([], $this->http->sent, 'an add-on row WHMCS does not hold is skipped');
        self::assertSame([], $this->repo->log);
    }

    public function testInDryRunTheDecisionIsLoggedAndNothingIsSentOrStored(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        Hooks::addonEvent('AddonActivated', self::ACTIVATED);
        self::assertSame([], $this->http->sent);
        self::assertSame([], $this->repo->careSites);
        self::assertSame([['provision', 'would run']], $this->logged());
        self::assertSame(1, $this->repo->log[0]['dry_run']);
    }

    public function testADashboardFailureIsLoggedAndNeverReachesWhmcs(): void
    {
        $this->http->on('GET', 'clients?page=1&per_page=100', 'key-wrong');
        Hooks::addonEvent('AddonActivated', self::ACTIVATED);
        self::assertSame([], $this->repo->careSites);
        self::assertSame('failed', $this->repo->log[count($this->repo->log) - 1]['outcome']);
    }

    public function testAProblemInsideTheModuleGoesToTheActivityLogAndNeverStopsWhmcs(): void
    {
        Module::$repositoryFactory = static fn () => throw new \RuntimeException('Table mod_mainwp_sites doesn\'t exist in /var/www/whmcs/vendor/x.php');
        Hooks::addonEvent('AddonActivated', self::ACTIVATED);
        Hooks::invoicePaid(['invoiceid' => 1]);
        self::assertCount(2, WhmcsCalls::$activity);
        self::assertStringStartsWith('MainWP for WHMCS: AddonActivated for add-on 67 failed: ', WhmcsCalls::$activity[0]);
        self::assertStringNotContainsString('/var/www', WhmcsCalls::$activity[0]);
    }

    public function testParametersThatAreNotIdsAreIgnored(): void
    {
        Hooks::addonEvent('AddonActivated', ['id' => 'x']);
        Hooks::addonEvent('AddonActivated', []);
        Hooks::invoicePaid(['invoiceid' => null]);
        self::assertSame([], $this->repo->log);
        self::assertSame([], WhmcsCalls::$activity);
    }

    /** CreateAccount provisions at order acceptance; the payment's InvoicePaid that follows finds the care site and does nothing more. */
    public function testInvoicePaidAfterCreateAccountProvisionsNothingMore(): void
    {
        require_once __DIR__ . '/../modules/servers/mainwp/mainwp.php';
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Pending', 'Active', 'clientsite.example', 'Demo Client', 'demo-client@example.com', 0);
        $this->dashboardMakesTheClient();
        self::assertSame('success', \mainwp_CreateAccount(['addonId' => 67, 'serviceid' => 1176, 'userid' => 540, 'pid' => 16, 'serverid' => 0, 'status' => 'Pending']));
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo-client@example.com', 0);
        $sent = count($this->http->sent);
        $log = count($this->repo->log);

        $this->repo->invoiceAddons[60500] = [67];
        Hooks::invoicePaid(['invoiceid' => 60500]);

        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
        self::assertCount($sent, $this->http->sent, 'no second call to the dashboard');
        self::assertCount($log, $this->repo->log, 'nothing logged for the payment');
        self::assertSame('whmcs:CreateAccount', $this->repo->log[$log - 1]['actor']);
    }
}
