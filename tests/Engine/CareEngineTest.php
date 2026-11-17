<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\CareEngine;
use MainWpForWhmcs\Engine\CareSite;
use MainWpForWhmcs\Engine\Client;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\State;

require_once __DIR__ . '/Fakes.php';

final class CareEngineTest extends TestCase
{
    private RecordingDashboard $dashboard;
    private RecordingSite $site;
    private MemoryStorage $storage;

    protected function setUp(): void
    {
        $this->dashboard = new RecordingDashboard();
        $this->site = new RecordingSite();
        $this->storage = new MemoryStorage();
    }

    private function engine(bool $dryRun): CareEngine
    {
        return new CareEngine($this->dashboard, $this->site, $this->storage, new FixedClock(), $dryRun, new FixedSecrets(), 'wordpress@care.example.com');
    }

    public function testDryRunProvisionLogsWhatItWouldDoAndSendsNothing(): void
    {
        $outcome = $this->engine(true)->provision(68, 1176, new Client(540, 'Demo Client', 'demo@example.com'), 'https://clientsite.example', 'hook:AddonActivated');

        self::assertTrue($outcome->ok);
        self::assertSame([], $this->dashboard->calls);
        self::assertSame([], $this->site->calls);
        self::assertSame([], $this->storage->sites, 'a dry run records no care site');
        self::assertCount(1, $this->storage->log);
        $entry = $this->storage->log[0];
        self::assertSame(['2026-11-09 14:00:00', 'hook:AddonActivated', 'provision', 'add-on 68', 'would run', true], [$entry->time, $entry->actor, $entry->action, $entry->target, $entry->outcome, $entry->dryRun]);
        self::assertSame('would build WHMCS client 540 on the dashboard and record https://clientsite.example as Provisioned', $entry->detail);
    }

    public function testLiveProvisionBuildsTheClientOnceAndRecordsTheSiteAsProvisioned(): void
    {
        $engine = $this->engine(false);
        $client = new Client(540, 'Demo Client', 'demo@example.com');

        self::assertTrue($engine->provision(68, 1176, $client, 'https://clientsite.example', 'hook:AddonActivated')->ok);
        self::assertSame(['findClient 540', 'createClient 540 Demo Client demo@example.com'], $this->dashboard->calls);
        $site = $this->storage->site(68);
        self::assertNotNull($site);
        self::assertSame([1176, 540, 'https://clientsite.example', State::Provisioned, 100, null], [$site->serviceId, $site->clientId, $site->siteUrl, $site->state, $site->dashboardClientId, $site->dashboardSiteId]);
        self::assertSame(['provision', 'add-on 68', 'done', false], [$this->storage->log[0]->action, $this->storage->log[0]->target, $this->storage->log[0]->outcome, $this->storage->log[0]->dryRun]);

        // a second event for the same add-on does nothing
        $again = $engine->provision(68, 1176, $client, 'https://clientsite.example', 'hook:InvoicePaid');
        self::assertTrue($again->ok);
        self::assertSame('Add-on 68 is already provisioned.', $again->message);
        self::assertCount(2, $this->dashboard->calls);

        // a second care add-on of the same client reuses the dashboard client: one per WHMCS client
        $engine->provision(69, 1176, $client, 'https://clientsite.example/shop', 'hook:AddonActivated');
        self::assertSame(['findClient 540'], array_slice($this->dashboard->calls, 2));
        self::assertSame(100, $this->storage->site(69)?->dashboardClientId);
    }

    /**
     * Code review, 16 November 2026: two requests for one new client at the same moment could each
     * create the client on the dashboard. The find-or-create runs under a lock of the WHMCS client.
     */
    public function testProvisionBuildsTheDashboardClientUnderALockOfTheWhmcsClient(): void
    {
        self::assertTrue($this->engine(false)->provision(68, 1176, new Client(540, 'Demo Client', 'demo@example.com'), 'https://clientsite.example', 'hook:AddonActivated')->ok);

        self::assertSame(['lock client 540', 'unlock client 540'], $this->storage->clientLocks);
        self::assertGreaterThan(0, $this->storage->clientWaits[0], 'it waits a short while for the other request');
        self::assertSame([], $this->storage->lockedClients, 'released');
    }

    public function testAProvisionThatWaitedItsTurnReadsTheCareSiteAgainAndBuildsNothingTwice(): void
    {
        $client = new Client(540, 'Demo Client', 'demo@example.com');
        // another request provisioned add-on 68 while this one waited for the client
        $this->storage->whileWaitingForClient = function (): void {
            $this->storage->saveSite(new CareSite(68, 1176, 540, 'https://clientsite.example', State::Provisioned, 100));
        };

        $outcome = $this->engine(false)->provision(68, 1176, $client, 'https://clientsite.example', 'hook:InvoicePaid');

        self::assertTrue($outcome->ok);
        self::assertSame('Add-on 68 is already provisioned.', $outcome->message);
        self::assertSame([], $this->dashboard->calls, 'no second client on the dashboard');
        self::assertSame(['lock client 540', 'unlock client 540'], $this->storage->clientLocks);
    }

    public function testWhileAnotherRequestProvisionsForTheClientProvisionFailsAndIsTriedAgainAtTheNextDailyCheck(): void
    {
        $this->storage->lockedClients = [540];

        $outcome = $this->engine(false)->provision(68, 1176, new Client(540, 'Demo Client', 'demo@example.com'), 'https://clientsite.example', 'hook:InvoicePaid');

        self::assertFalse($outcome->ok);
        self::assertSame('Add-on 68 could not be provisioned: another request is setting up the same client. It is tried again at the next daily check.', $outcome->message);
        self::assertSame([], $this->dashboard->calls);
        self::assertSame([], $this->storage->sites);
        $entry = $this->storage->log[0];
        self::assertSame(['provision', 'add-on 68', 'failed'], [$entry->action, $entry->target, $entry->outcome]);
        self::assertSame('another request was setting up WHMCS client 540 on the dashboard; nothing was done; it is tried again at the next daily check', $entry->detail);
    }

    public function testADryRunProvisionTakesNoLock(): void
    {
        $this->engine(true)->provision(68, 1176, new Client(540, 'Demo Client', 'demo@example.com'), 'https://clientsite.example', 'hook:AddonActivated');
        self::assertSame([], $this->storage->clientLocks);
    }

    public function testAFailingDashboardIsLoggedWithoutFilePathsAndRecordsNothing(): void
    {
        $this->dashboard->offline = new \RuntimeException('cURL error 7: could not connect in /var/www/whmcs/modules/addons/mainwp/lib/Http.php:42');

        $outcome = $this->engine(false)->provision(68, 1176, new Client(540, 'Demo Client', 'demo@example.com'), 'https://clientsite.example', 'cron:daily');

        self::assertFalse($outcome->ok);
        self::assertSame([], $this->storage->sites);
        $entry = $this->storage->log[0];
        self::assertSame(['provision', 'failed'], [$entry->action, $entry->outcome]);
        self::assertSame('cURL error 7: could not connect in (path)', $entry->detail);
        self::assertSame('Add-on 68 could not be provisioned: cURL error 7: could not connect in (path)', $outcome->message);
    }

    private function provisioned(): void
    {
        $this->engine(false)->provision(68, 1176, new Client(540, 'Demo Client', 'demo@example.com'), 'https://clientsite.example', 'hook:AddonActivated');
        $this->dashboard->calls = [];
        $this->storage->log = [];
    }

    public function testLiveConnectInstallsThePluginAddsTheSiteAndKeepsTheCareAccountsLoginInPlaceOfTheOneTyped(): void
    {
        $this->provisioned();

        $outcome = $this->engine(false)->connect(68, new Login('siteadmin', 'Pa$$\\word'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['installPlugin https://clientsite.example siteadmin'], $this->site->calls);
        self::assertSame('addSite 100 https://clientsite.example siteadmin', $this->dashboard->calls[0]);
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, 101, '2026-11-09 14:00:00', null], [$site?->state, $site?->dashboardSiteId, $site?->lastSync, $site?->lastError]);
        self::assertSame(FixedSecrets::CARE_LOGIN, $this->storage->logins[68]->username, 'the care account is kept for mends; the login typed is not');
        self::assertSame(['connect', 'done'], [$this->storage->log[0]->action, $this->storage->log[0]->outcome]);
        self::assertNoSecretLogged('Pa$$\\word');
    }

    public function testAFailedConnectIsFailedWithTheReasonAndKeepsTheLoginForTheRetry(): void
    {
        $this->provisioned();
        $this->dashboard->offline = new \MainWpForWhmcs\Engine\DashboardFailure(\MainWpForWhmcs\Engine\DashboardFailure::REFUSED, 'The site said: verify your username and password (Pa$$word) in /srv/x/y.php');

        $outcome = $this->engine(false)->connect(68, new Login('siteadmin', 'Pa$$word'), 'admin:1');

        self::assertFalse($outcome->ok);
        $site = $this->storage->site(68);
        self::assertSame(State::Failed, $site?->state);
        self::assertSame('The site said: verify your username and password ((hidden)) in (path)', $site?->lastError);
        self::assertArrayHasKey(68, $this->storage->logins);
        self::assertNoSecretLogged('Pa$$word');
    }

    public function testConnectNeedsAProvisionedCareSite(): void
    {
        $outcome = $this->engine(false)->connect(99, new Login('a', 'b'), 'client:540');
        self::assertFalse($outcome->ok);
        self::assertSame('Add-on 99 has no care site to connect: it is not provisioned.', $outcome->message);
        self::assertSame([[], []], [$this->site->calls, $this->dashboard->calls]);
    }

    public function testDryRunConnectSendsNothingAndStoresNoLogin(): void
    {
        $this->provisioned();

        $outcome = $this->engine(true)->connect(68, new Login('siteadmin', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok);
        self::assertSame([[], [], []], [$this->site->calls, $this->dashboard->calls, $this->storage->logins]);
        self::assertSame(State::Provisioned, $this->storage->site(68)?->state);
        self::assertSame('would install MainWP\'s plugin on https://clientsite.example as siteadmin and connect it to the dashboard, then make the care account', $this->storage->log[0]->detail);
        self::assertNoSecretLogged('Secret1');
    }

    private function assertNoSecretLogged(string $secret): void
    {
        foreach ($this->storage->log as $entry) {
            self::assertStringNotContainsString($secret, implode(' ', (array) $entry));
        }
        foreach ($this->storage->sites as $site) {
            self::assertStringNotContainsString($secret, (string) $site->lastError);
        }
    }

    private function connected(): void
    {
        $this->provisioned();
        $this->engine(false)->connect(68, new Login('siteadmin', 'Secret1'), 'client:540');
        $this->dashboard->calls = [];
        $this->site->calls = [];
        $this->storage->log = [];
    }

    public function testSuspendResumeAndRemoveAConnectedSite(): void
    {
        $this->connected();
        $engine = $this->engine(false);

        self::assertTrue($engine->suspend(68, 'hook:AddonSuspended')->ok);
        self::assertSame(State::Suspended, $this->storage->site(68)?->state);
        self::assertTrue($engine->resume(68, 'hook:AddonActivated')->ok);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertTrue($engine->remove(68, 'hook:AddonCancelled')->ok);
        self::assertSame(State::Removed, $this->storage->site(68)?->state);

        self::assertSame(['suspendSite 101', 'resumeSite 101', 'removeSite 101'], $this->dashboard->calls);
        self::assertSame([], $this->site->calls, 'nothing changes on the client\'s site');
        self::assertSame([], $this->storage->logins, 'the login is deleted when the care site is removed');
        self::assertSame([['suspend', 'done'], ['resume', 'done'], ['remove', 'done']], array_map(static fn ($e): array => [$e->action, $e->outcome], $this->storage->log));
        self::assertSame('dashboard site 101 (https://clientsite.example) removed', end($this->storage->log)->detail);
    }

    public function testAnEventThatChangesNothingCallsNothing(): void
    {
        $this->connected();
        $engine = $this->engine(false);

        // AddonActivated fires at every return to Active, and when an order is accepted: a site that is not suspended is left alone.
        $resumed = $engine->resume(68, 'whmcs:AddonActivated');
        self::assertSame([true, 'Add-on 68 is not suspended.'], [$resumed->ok, $resumed->message]);

        $engine->suspend(68, 'whmcs:AfterModuleSuspend');
        $this->dashboard->calls = [];
        $this->storage->log = [];
        // The add-on is suspended after its hosting service already was.
        $again = $engine->suspend(68, 'whmcs:AddonSuspended');
        self::assertSame([true, 'Add-on 68 is already suspended.'], [$again->ok, $again->message]);

        self::assertSame([[], []], [$this->dashboard->calls, $this->storage->log]);
        self::assertSame(State::Suspended, $this->storage->site(68)?->state);
    }

    public function testASiteMainWpRemovesWithoutSwitchingItsPluginOffIsSaidSoInTheLog(): void
    {
        $this->connected();
        // MainWP's answer for a site it has flagged as staging (bench, sixth run): it drops the row and leaves the site as it is.
        $this->dashboard->removeSays = 'The site has been removed. Please make sure that the MainWP Child plugin has been deactivated properly';

        self::assertTrue($this->engine(false)->remove(68, 'whmcs:AddonCancelled')->ok);

        self::assertSame(['removeSite 101'], $this->dashboard->calls);
        self::assertSame(State::Removed, $this->storage->site(68)?->state);
        self::assertSame('dashboard site 101 (https://clientsite.example) removed; MainWP did not switch its plugin off on the site, as for a site it has flagged as staging, and says: "The site has been removed. Please make sure that the MainWP Child plugin has been deactivated properly"', end($this->storage->log)->detail);
    }

    public function testASiteTheDashboardNoLongerHoldsIsRemovedAllTheSame(): void
    {
        $this->connected();
        $this->dashboard->unknown = [101];

        $outcome = $this->engine(false)->remove(68, 'whmcs:AddonTerminated');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['removeSite 101'], $this->dashboard->calls);
        self::assertSame(State::Removed, $this->storage->site(68)?->state);
        self::assertSame([], $this->storage->logins);
        $entry = end($this->storage->log);
        self::assertSame(['remove', 'done', 'dashboard site 101 (https://clientsite.example) was no longer on the dashboard'], [$entry->action, $entry->outcome, $entry->detail]);
    }

    public function testARemovalMainWpAnswersWithAnErrorButCarriesOutIsARemoval(): void
    {
        $this->connected();
        // The connecting administrator is gone from the site: MainWP cannot switch its plugin off there, says so, and drops the row all the same (bench, sixth run).
        $this->dashboard->removeRefuses = new \MainWpForWhmcs\Engine\DashboardFailure(\MainWpForWhmcs\Engine\DashboardFailure::REFUSED, 'The dashboard refused to remove site 101: Unexisting administrator user. Please verify that it is an existing administrator.');
        $this->dashboard->dropsWhenItRefuses = true;

        $outcome = $this->engine(false)->remove(68, 'whmcs:AddonCancelled');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['removeSite 101', 'site 101'], $this->dashboard->calls, 'the refusal is not taken at its word: the dashboard is asked whether it still holds the site');
        self::assertSame(State::Removed, $this->storage->site(68)?->state);
        self::assertSame([], $this->storage->logins);
        self::assertSame('dashboard site 101 (https://clientsite.example) removed from the dashboard; MainWP could not switch its plugin off on the site: The dashboard refused to remove site 101: Unexisting administrator user. Please verify that it is an existing administrator.', end($this->storage->log)->detail);
    }

    public function testARemovalMainWpRefusesAndDoesNotCarryOutFails(): void
    {
        $this->connected();
        $before = $this->storage->site(68);
        $this->dashboard->removeRefuses = new \MainWpForWhmcs\Engine\DashboardFailure(\MainWpForWhmcs\Engine\DashboardFailure::REFUSED, 'The dashboard refused to remove site 101: it gave no reason.');

        $outcome = $this->engine(false)->remove(68, 'whmcs:AddonCancelled');

        self::assertFalse($outcome->ok);
        self::assertSame(['removeSite 101', 'site 101'], $this->dashboard->calls);
        self::assertEquals($before, $this->storage->site(68));
        self::assertArrayHasKey(68, $this->storage->logins);
        $entry = end($this->storage->log);
        self::assertSame(['remove', 'failed'], [$entry->action, $entry->outcome]);
    }

    public function testARemovalTheDashboardRefusesLeavesTheSiteAndItsLoginAsTheyWere(): void
    {
        $this->connected();
        $before = $this->storage->site(68);
        $this->dashboard->offline = new \MainWpForWhmcs\Engine\DashboardFailure(\MainWpForWhmcs\Engine\DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');

        $outcome = $this->engine(false)->remove(68, 'whmcs:AddonCancelled');

        self::assertSame([false, 'Add-on 68 could not be removed: The dashboard could not be reached.'], [$outcome->ok, $outcome->message]);
        self::assertEquals($before, $this->storage->site(68));
        self::assertArrayHasKey(68, $this->storage->logins, 'the login goes only when the site has gone');
        $entry = end($this->storage->log);
        self::assertSame(['remove', 'failed'], [$entry->action, $entry->outcome]);
    }

    public function testARemovalTheDashboardWaitedOnTheSiteForSaysTheSiteDidNotAnswerAndIsTriedAgain(): void
    {
        $this->connected();
        $before = $this->storage->site(68);
        $this->dashboard->removeRefuses = new \MainWpForWhmcs\Engine\DashboardFailure(\MainWpForWhmcs\Engine\DashboardFailure::TIMED_OUT, 'The dashboard at dash.example.com did not answer in time to remove site 101: Operation timed out.');
        $this->dashboard->dropsWhenItRefuses = true; // MainWP finished the removal a moment later (bench, 16 November 2026)

        $failed = $this->engine(false)->remove(68, 'whmcs:AddonCancelled');

        self::assertSame([false, 'Add-on 68 could not be removed: The site did not answer in time while the dashboard removed dashboard site 101.'], [$failed->ok, $failed->message]);
        self::assertEquals($before, $this->storage->site(68), 'nothing is marked Removed until the dashboard says so');

        $this->dashboard->removeRefuses = null;
        $again = $this->engine(false)->remove(68, 'whmcs:AddonCancelled');

        self::assertTrue($again->ok, 'WHMCS\'s retry of the command finds the site gone');
        self::assertSame(State::Removed, $this->storage->site(68)?->state);
    }

    public function testARefusedRemovalIsNotTakenAsDoneWhenTheDashboardCannotSayWhetherItStillHoldsTheSite(): void
    {
        $this->connected();
        $this->dashboard->removeRefuses = new \MainWpForWhmcs\Engine\DashboardFailure(\MainWpForWhmcs\Engine\DashboardFailure::REFUSED, 'The dashboard refused to remove site 101: Unexisting administrator user.');
        $this->dashboard->dropsWhenItRefuses = true;
        $this->dashboard->readRefuses = new \MainWpForWhmcs\Engine\DashboardFailure(\MainWpForWhmcs\Engine\DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');

        $outcome = $this->engine(false)->remove(68, 'whmcs:AddonCancelled');

        self::assertFalse($outcome->ok, 'nothing is marked Removed on a guess');
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertArrayHasKey(68, $this->storage->logins);
    }

    public function testARemovedSiteKeepsNoRetryNoReasonAndNoDashboardSite(): void
    {
        $this->provisioned();
        $this->site->refuses = new \MainWpForWhmcs\Engine\SiteFailure(\MainWpForWhmcs\Engine\SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->engine(false)->connect(68, new Login('siteadmin', 'Secret1'), 'client:540');
        self::assertNotNull($this->storage->site(68)?->retryAfter, 'on the retry list');

        self::assertTrue($this->engine(false)->remove(68, 'whmcs:AddonCancelled')->ok);

        $site = $this->storage->site(68);
        self::assertSame([State::Removed, null, null, 0, null], [$site?->state, $site?->retryAfter, $site?->lastError, $site?->retryCount, $site?->dashboardSiteId], 'the open retry entry goes with the site');
        self::assertSame([], $this->storage->logins);

        $this->connected();
        $this->engine(false)->remove(68, 'whmcs:AddonCancelled');
        self::assertNull($this->storage->site(68)?->dashboardSiteId, 'the dashboard site is gone, so its id is not kept');
    }

    public function testAnEventThatFindsTheAddonBusyWaitsAndThenSaysSoInTheLog(): void
    {
        $this->connected();
        $engine = $this->engine(false);
        $this->storage->locked = [68];
        $this->storage->waits = [];

        $suspend = $engine->suspend(68, 'whmcs:AddonSuspended');
        $remove = $engine->remove(68, 'whmcs:AddonCancelled');
        $this->storage->sites[68] = $this->storage->sites[68]->withState(State::Suspended);
        $resume = $engine->resume(68, 'whmcs:AddonActivated');

        self::assertSame([false, false, false], [$suspend->ok, $remove->ok, $resume->ok]);
        self::assertSame([20, 20, 20], $this->storage->waits);
        self::assertSame([], $this->dashboard->calls);
        self::assertSame(
            [['suspend', 'failed'], ['remove', 'failed'], ['resume', 'failed']],
            array_map(static fn ($e): array => [$e->action, $e->outcome], $this->storage->log),
            'nothing else would say that the event was not acted on',
        );
        self::assertStringContainsString('another request was working on the add-on', $this->storage->log[0]->detail);
    }

    public function testASiteThatWasNeverConnectedIsRemovedWithoutCallingTheDashboard(): void
    {
        $this->provisioned();
        $this->storage->saveLogin(68, new Login('siteadmin', 'Secret1'));

        self::assertTrue($this->engine(false)->remove(68, 'hook:AddonTerminated')->ok);
        self::assertSame([], $this->dashboard->calls);
        self::assertSame(State::Removed, $this->storage->site(68)?->state);
        self::assertSame([], $this->storage->logins);
    }

    public function testSuspendingASiteThatIsNotOnTheDashboardChangesNothing(): void
    {
        $this->provisioned();
        $outcome = $this->engine(false)->suspend(68, 'hook:AddonSuspended');
        self::assertTrue($outcome->ok);
        self::assertSame('Add-on 68 has no site on the dashboard to suspend.', $outcome->message);
        self::assertSame(State::Provisioned, $this->storage->site(68)?->state);
        self::assertSame([[], []], [$this->dashboard->calls, $this->storage->log]);
        self::assertSame('Add-on 7 has no care site.', $this->engine(false)->resume(7, 'x')->message);
    }

    public function testDryRunSuspendResumeAndRemoveSendNothing(): void
    {
        $this->connected();
        $engine = $this->engine(true);
        $engine->suspend(68, 'hook:AddonSuspended');
        $this->storage->sites[68] = $this->storage->sites[68]->withState(State::Suspended);
        $engine->resume(68, 'hook:AddonActivated');
        $this->storage->sites[68] = $this->storage->sites[68]->withState(State::Connected);
        $engine->remove(68, 'hook:AddonCancelled');

        self::assertSame([], $this->dashboard->calls);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertArrayHasKey(68, $this->storage->logins);
        self::assertSame([
            'would suspend dashboard site 101 (https://clientsite.example)',
            'would resume dashboard site 101 (https://clientsite.example)',
            'would remove dashboard site 101 (https://clientsite.example) and delete the stored login',
        ], array_map(static fn ($e): string => $e->detail, $this->storage->log));
    }

    public function testAFailedSuspendKeepsTheStateAndSaysWhy(): void
    {
        $this->connected();
        $this->dashboard->offline = new \RuntimeException('HTTP 401: key refused');
        $outcome = $this->engine(false)->suspend(68, 'hook:AddonSuspended');
        self::assertFalse($outcome->ok);
        self::assertSame('Add-on 68 could not be suspended: HTTP 401: key refused', $outcome->message);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
    }
}
