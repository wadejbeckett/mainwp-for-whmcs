<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use MainWpForWhmcs\Engine\CareEngine;
use MainWpForWhmcs\Engine\Client;
use MainWpForWhmcs\Engine\DashboardClient;
use MainWpForWhmcs\Engine\DashboardFailure;
use MainWpForWhmcs\Engine\State;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Fakes.php';

/**
 * Provisioning for a customer the dashboard already holds as a client made by hand (same email),
 * and for an add-on whose hosting service has no domain (version 0.6.0).
 */
final class ProvisionTest extends TestCase
{
    private const REFUSAL = 'The dashboard refused to create the client: Client email exists.';

    private RecordingDashboard $dashboard;
    private MemoryStorage $storage;

    protected function setUp(): void
    {
        $this->dashboard = new RecordingDashboard();
        $this->storage = new MemoryStorage();
    }

    private function engine(bool $dryRun = false): CareEngine
    {
        return new CareEngine($this->dashboard, new RecordingSite(), $this->storage, new FixedClock(), $dryRun, new FixedSecrets(), 'wordpress@care.example.com');
    }

    private function emailTaken(DashboardClient ...$clients): void
    {
        $this->dashboard->clientRefuses = new DashboardFailure(DashboardFailure::REFUSED, self::REFUSAL);
        $this->dashboard->clientsList = array_values($clients);
    }

    private static function client(): Client
    {
        return new Client(540, 'Demo Client', 'Demo@Example.com');
    }

    public function testACustomerWhoIsAlreadyADashboardClientByEmailIsFiledUnderThatClientAndItsNoteIsLeftAlone(): void
    {
        $this->emailTaken(new DashboardClient(11, 'Someone Else', 'other@example.com', null), new DashboardClient(12, 'Demo by hand', '  demo@EXAMPLE.com ', null));

        $outcome = $this->engine()->provision(68, 1176, self::client(), 'https://clientsite.example', 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['findClient 540', 'createClient 540 Demo Client Demo@Example.com', 'clients'], $this->dashboard->calls, 'nothing is written to the dashboard client: no call changes it');
        $site = $this->storage->site(68);
        self::assertNotNull($site);
        self::assertSame([State::Provisioned, 12], [$site->state, $site->dashboardClientId]);
        $entry = end($this->storage->log);
        self::assertSame(['provision', 'add-on 68', 'done'], [$entry->action, $entry->target, $entry->outcome]);
        self::assertSame('dashboard client 12 (found by its email; its note was not changed), https://clientsite.example Provisioned', $entry->detail);
        self::assertStringNotContainsStringIgnoringCase('demo@example.com', $entry->detail);
    }

    public function testAClientWhoseNoteNamesThisSameWhmcsClientIsTakenToo(): void
    {
        $this->emailTaken(new DashboardClient(12, 'Demo', 'demo@example.com', 540));

        self::assertTrue($this->engine()->provision(68, 1176, self::client(), 'https://clientsite.example', 'cron:daily')->ok);
        self::assertSame(12, $this->storage->site(68)?->dashboardClientId);
    }

    public function testASecondCareAddOnOfTheSameCustomerFindsTheSameDashboardClientTheSameWay(): void
    {
        $this->emailTaken(new DashboardClient(12, 'Demo by hand', 'demo@example.com', null));
        $engine = $this->engine();

        self::assertTrue($engine->provision(68, 1176, self::client(), 'https://clientsite.example', 'cron:daily')->ok);
        self::assertTrue($engine->provision(69, 1177, self::client(), 'https://shop.example.com', 'cron:daily')->ok);

        self::assertSame(12, $this->storage->site(69)?->dashboardClientId);
        self::assertSame('dashboard client 12 (found by its email; its note was not changed), https://shop.example.com Provisioned', end($this->storage->log)->detail);
    }

    public function testTwoDashboardClientsWithTheEmailKeepTheFailureAndSayWhatStaffCanDo(): void
    {
        $this->emailTaken(new DashboardClient(12, 'Demo', 'demo@example.com', null), new DashboardClient(15, 'Demo too', 'DEMO@example.com', null));

        $outcome = $this->engine()->provision(68, 1176, self::client(), 'https://clientsite.example', 'cron:daily');

        self::assertFalse($outcome->ok);
        self::assertSame([], $this->storage->sites);
        $why = 'The dashboard already has 2 clients with this customer\'s email (dashboard clients 12 and 15). Write "WHMCS client 540" in the note of the right dashboard client, or change the email of the others there; it is tried again at the next daily check.';
        self::assertSame('Add-on 68 could not be provisioned: ' . $why, $outcome->message);
        $entry = end($this->storage->log);
        self::assertSame(['provision', 'failed', $why], [$entry->action, $entry->outcome, $entry->detail]);
        self::assertStringNotContainsStringIgnoringCase('demo@example.com', $outcome->message);
    }

    public function testADashboardClientWhoseNoteNamesAnotherWhmcsClientIsNotTaken(): void
    {
        $this->emailTaken(new DashboardClient(12, 'Demo', 'demo@example.com', 77));

        $outcome = $this->engine()->provision(68, 1176, self::client(), 'https://clientsite.example', 'cron:daily');

        self::assertFalse($outcome->ok);
        self::assertSame([], $this->storage->sites);
        self::assertSame('Add-on 68 could not be provisioned: The dashboard already has a client with this customer\'s email (dashboard client 12, whose note names WHMCS client 77). Write "WHMCS client 540" in the note of the right dashboard client, or change its email there; it is tried again at the next daily check.', $outcome->message);
    }

    public function testWithNoDashboardClientOfThatEmailTheRefusalStandsAsItIs(): void
    {
        $this->emailTaken(new DashboardClient(11, 'Someone Else', 'other@example.com', null));

        $outcome = $this->engine()->provision(68, 1176, self::client(), 'https://clientsite.example', 'cron:daily');

        self::assertFalse($outcome->ok);
        self::assertSame('Add-on 68 could not be provisioned: ' . self::REFUSAL, $outcome->message);
        self::assertSame(self::REFUSAL, end($this->storage->log)->detail);
    }

    public function testAnotherWhmcsClientWithTheSameEmailIsNotFiledUnderTheSameDashboardClient(): void
    {
        // The note is never written, so the dashboard client taken for client 540 would pass again for client 541.
        $this->emailTaken(new DashboardClient(12, 'Demo by hand', 'shared@example.com', null));
        self::assertTrue($this->engine()->provision(68, 1176, new Client(540, 'Demo Client', 'shared@example.com'), 'https://clientsite.example', 'cron:daily')->ok);

        $outcome = $this->engine()->provision(69, 1177, new Client(541, 'Another Client', 'Shared@Example.com'), 'https://other.example', 'cron:daily');

        self::assertFalse($outcome->ok);
        self::assertNull($this->storage->site(69));
        self::assertSame('Add-on 69 could not be provisioned: The dashboard already has a client with this customer\'s email (dashboard client 12), and care add-on 68 of WHMCS client 540 is filed under it. Write "WHMCS client 541" in the note of the right dashboard client, or change its email there; it is tried again at the next daily check.', $outcome->message);
        self::assertStringNotContainsStringIgnoringCase('shared@example.com', $outcome->message);
    }

    public function testACustomerWithNoEmailTakesNoDashboardClient(): void
    {
        $this->emailTaken(new DashboardClient(12, 'No email', '', null));

        $outcome = $this->engine()->provision(68, 1176, new Client(540, 'Demo Client', '  '), 'https://clientsite.example', 'cron:daily');

        self::assertFalse($outcome->ok);
        self::assertSame('Add-on 68 could not be provisioned: ' . self::REFUSAL, $outcome->message);
        self::assertNotContains('clients', $this->dashboard->calls);
        self::assertNull($this->storage->site(68));
    }

    public function testWhenTheDashboardsClientsCannotBeReadTheRefusalStandsAndNothingIsTaken(): void
    {
        $this->emailTaken(new DashboardClient(12, 'Demo by hand', 'demo@example.com', null));
        $this->dashboard->clientsFails = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached to list the clients.');

        $outcome = $this->engine()->provision(68, 1176, self::client(), 'https://clientsite.example', 'cron:daily');

        self::assertFalse($outcome->ok);
        self::assertSame('Add-on 68 could not be provisioned: ' . self::REFUSAL, $outcome->message);
        self::assertNull($this->storage->site(68));
    }

    public function testOnlyARefusalLeadsToTheLookUp(): void
    {
        $this->dashboard->clientRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard at dash.example.com could not be reached to create the client: timeout.');
        $this->dashboard->clientsList = [new DashboardClient(12, 'Demo', 'demo@example.com', null)];

        self::assertFalse($this->engine()->provision(68, 1176, self::client(), 'https://clientsite.example', 'cron:daily')->ok);
        self::assertNotContains('clients', $this->dashboard->calls);
    }

    public function testAnAddOnWhoseHostingServiceHasNoDomainIsNotProvisionedAndWaits(): void
    {
        $outcome = $this->engine()->provision(68, 1176, self::client(), '', 'whmcs:CreateAccount');

        self::assertFalse($outcome->ok);
        self::assertSame('Add-on 68 is not provisioned: its hosting service has no domain, so no site can be connected.', $outcome->message);
        self::assertSame([], $this->dashboard->calls);
        self::assertSame([], $this->storage->sites);
        self::assertCount(1, $this->storage->log);
        $entry = $this->storage->log[0];
        self::assertSame(['whmcs:CreateAccount', 'provision', 'add-on 68', 'waiting', false], [$entry->actor, $entry->action, $entry->target, $entry->outcome, $entry->dryRun]);
        self::assertSame('not provisioned: its hosting service has no domain, so no site can be connected', $entry->detail);
    }

    public function testInDryRunAnAddOnWithNoDomainIsLoggedAsWaitingToo(): void
    {
        $this->engine(true)->provision(68, 1176, self::client(), '', 'cron:daily');

        self::assertCount(1, $this->storage->log);
        self::assertSame(['waiting', true], [$this->storage->log[0]->outcome, $this->storage->log[0]->dryRun]);
    }
}
