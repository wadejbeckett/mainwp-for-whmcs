<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\AddonState;
use MainWpForWhmcs\Engine\CareEngine;
use MainWpForWhmcs\Engine\CareSite;
use MainWpForWhmcs\Engine\Client;
use MainWpForWhmcs\Engine\DailyCheck;
use MainWpForWhmcs\Engine\DashboardClient;
use MainWpForWhmcs\Engine\DashboardFailure;
use MainWpForWhmcs\Engine\DashboardSite;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\Report;
use MainWpForWhmcs\Engine\SiteFailure;
use MainWpForWhmcs\Engine\State;

require_once __DIR__ . '/Fakes.php';

/**
 * The daily check: every care add-on set against the module's
 * record and the dashboard. It provisions what is billed, works the retry list, mends what broke,
 * takes over what staff connected by hand, and reports every other mismatch without correcting it.
 */
final class DailyCheckTest extends TestCase
{
    private RecordingDashboard $dashboard;
    private RecordingSite $site;
    private MemoryStorage $storage;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->dashboard = new RecordingDashboard();
        $this->dashboard->clientsList = [new DashboardClient(100, 'Demo Client', 'demo@example.com', 540)];
        $this->dashboard->clients[540] = 100;
        $this->site = new RecordingSite();
        $this->storage = new MemoryStorage();
        $this->clock = new FixedClock(new \DateTimeImmutable('2026-11-10 00:00:10'));
    }

    private function check(bool $dryRun = false): DailyCheck
    {
        $engine = new CareEngine($this->dashboard, $this->site, $this->storage, $this->clock, $dryRun, new FixedSecrets(), 'wordpress@care.example.com');
        return new DailyCheck($engine, $this->dashboard, $this->storage, $this->clock, $dryRun);
    }

    private function addon(int $id, string $standing = AddonState::ACTIVE, bool $billed = true, string $why = ''): AddonState
    {
        return new AddonState($id, 1100 + $id, new Client(540, 'Demo Client', 'demo@example.com'), 'https://site' . $id . '.example', $standing, $billed && $standing === AddonState::ACTIVE, $why);
    }

    /** A care site in the module's records. */
    private function careSite(int $id, State $state, ?int $dashboardSiteId = null, ?string $lastError = null, ?string $retryAfter = null, int $retryCount = 0): void
    {
        $this->storage->saveSite(new CareSite($id, 1100 + $id, 540, 'https://site' . $id . '.example', $state, 100, $dashboardSiteId, $retryCount, null, $lastError, $retryAfter));
    }

    /** A site as the dashboard holds it. */
    private function onDashboard(int $dashboardSiteId, string $url, string $status = 'connected', ?int $clientId = 100): void
    {
        $this->dashboard->sitesList[] = new DashboardSite($dashboardSiteId, $url, $clientId, $status, $status === 'disconnected' ? null : '2026-11-09T22:00:00', $status === 'disconnected' ? 'MainWP Child plugin not detected or could not be reached' : '');
        $this->dashboard->status[$dashboardSiteId] = $status;
    }

    /** @return list<string> the report's lines as text */
    private function lines(Report $report): array
    {
        return array_map(static fn ($line): string => $line->text(), $report->lines());
    }

    public function testADashboardThatCannotBeReadIsReportedAsAFaultAndNothingIsActedOn(): void
    {
        $this->careSite(71, State::Failed, null, 'The site could not be reached.', '2026-11-09 23:00:00');
        $this->storage->saveLogin(71, new Login('owner', 'Secret1'));
        $this->careSite(72, State::Connected, 201);
        $before = $this->storage->sites;
        $this->dashboard->offline = new DashboardFailure(DashboardFailure::KEY_REFUSED, 'The dashboard refused the API key (HTTP 401).');

        $report = $this->check()->run([$this->addon(70), $this->addon(71), $this->addon(72, AddonState::SUSPENDED, false, 'the add-on is Suspended, not Active')], 'cron:daily');

        self::assertSame('The dashboard could not be read, so nothing was changed: The dashboard refused the API key (HTTP 401).', $report->fault);
        self::assertSame(['sites'], $this->dashboard->calls, 'nothing is provisioned, retried or mended');
        self::assertSame([], $this->site->calls);
        self::assertEquals($before, $this->storage->sites, 'no record is changed either');
        // What the module's own records show is still said: a suspension the dashboard missed while it was down must not stay hidden.
        self::assertSame([
            'Demo Client (client 540), https://site70.example (Not provisioned): not provisioned yet',
            'Demo Client (client 540), https://site71.example (Failed): The site could not be reached.',
            'Demo Client (client 540), https://site72.example (Connected): suspended in WHMCS (the add-on is Suspended, not Active) but connected on record',
        ], $this->lines($report));
        $entry = end($this->storage->log);
        self::assertSame(['daily check', 'failed'], [$entry->action, $entry->outcome]);
    }

    public function testABilledAddonWithoutACareSiteIsProvisionedAndAnUnbilledOneIsLeftAlone(): void
    {
        $unpaid = new AddonState(81, 1181, new Client(540, 'Demo Client', 'demo@example.com'), 'https://site81.example', AddonState::ACTIVE, false);

        $report = $this->check()->run([$this->addon(80), $unpaid], 'cron:daily');

        self::assertSame(State::Provisioned, $this->storage->site(80)?->state);
        self::assertNull($this->storage->site(81), 'care that is not paid for yet is not set up');
        self::assertSame(['Demo Client (client 540), https://site80.example (Provisioned): waiting for the client to connect the site'], $this->lines($report));
        self::assertNull($report->fault);
    }

    public function testABilledAddonThatCouldNotBeProvisionedSaysSoWithItsStateInCapitals(): void
    {
        $this->storage->lockedClients[] = 540; // another request is setting up the client

        $report = $this->check()->run([$this->addon(70)], 'cron:daily');

        self::assertSame(['Demo Client (client 540), https://site70.example (Not provisioned): could not be provisioned: Add-on 70 could not be provisioned: another request is setting up the same client. It is tried again at the next daily check.'], $this->lines($report));
    }

    public function testEveryPairOfWhmcsAndDashboardIsFineOrALine(): void
    {
        $this->careSite(60, State::Connected, 160);
        $this->onDashboard(160, 'https://site60.example/');
        $this->careSite(61, State::Provisioned);
        $this->careSite(62, State::Connected, 162);
        $this->onDashboard(162, 'https://site62.example/', 'suspended');
        $this->careSite(63, State::Connected, 163);
        $this->onDashboard(163, 'https://site63.example/');
        $this->careSite(64, State::Suspended, 164);
        $this->onDashboard(164, 'https://site64.example/', 'suspended');
        $this->careSite(65, State::Connected, 165);
        $this->onDashboard(165, 'https://site65.example/');
        $this->careSite(66, State::Provisioned);
        $this->careSite(67, State::Connected, 167);
        $this->onDashboard(167, 'https://site67.example/');
        $this->careSite(68, State::Connected, 168);
        $this->onDashboard(168, 'https://site68.example/');
        $this->careSite(69, State::Removed);

        $report = $this->check()->run([
            $this->addon(60),
            $this->addon(61),
            $this->addon(62),
            $this->addon(63, AddonState::SUSPENDED, false, 'its hosting service is Suspended, not Active'),
            $this->addon(64, AddonState::SUSPENDED, false, 'the add-on is Suspended, not Active'),
            $this->addon(65, AddonState::ENDED, false, 'the add-on is Cancelled, not Active'),
            $this->addon(66, AddonState::ENDED, false, 'the add-on is Terminated, not Active'),
            $this->addon(67, AddonState::PENDING, false, 'its hosting service is Pending, not Active'),
            $this->addon(69, AddonState::ENDED, false, 'the add-on is Cancelled, not Active'),
        ], 'cron:daily');

        self::assertSame([
            'Demo Client (client 540), https://site61.example (Provisioned): waiting for the client to connect the site',
            'Demo Client (client 540), https://site62.example (Connected): Active in WHMCS but suspended on the dashboard',
            'Demo Client (client 540), https://site63.example (Connected): suspended in WHMCS (its hosting service is Suspended, not Active) but connected on the dashboard',
            'Demo Client (client 540), https://site65.example (Connected): ended in WHMCS (the add-on is Cancelled, not Active) but still on the dashboard (connected)',
            'Demo Client (client 540), https://site66.example (Provisioned): ended in WHMCS (the add-on is Terminated, not Active) but its care site is not marked removed',
            'Demo Client (client 540), https://site67.example (Connected): not active in WHMCS (its hosting service is Pending, not Active) but connected on the dashboard',
            'client 540, https://site68.example (Connected): its add-on is no longer in the billing system, or its Module Settings no longer name MainWP for WHMCS, but its site is still on the dashboard (connected)',
        ], $this->lines($report), 'mismatches are reported, each with the client, the site, the state and what is wrong; nothing is corrected');
        self::assertNull($report->fault);
        self::assertSame([], array_values(array_filter($this->dashboard->calls, static fn (string $c): bool => preg_match('/^(suspend|resume|remove|add)Site/', $c) === 1)), 'mismatches are reported, not corrected');
        self::assertSame('2026-11-09 22:00:00', (new \DateTimeImmutable((string) $this->storage->site(60)?->lastSync))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'a site that is fine has the dashboard\'s last sync on record');
    }

    public function testASuspensionIsJudgedByTheDashboardsOwnRowNotByTheModulesRecord(): void
    {
        // The module's record says Suspended, but someone lifted the suspension on the dashboard by hand.
        $this->careSite(63, State::Suspended, 163);
        $this->onDashboard(163, 'https://site63.example/');
        // The record says Suspended, WHMCS says active, and the dashboard holds it connected: the record is stale, nothing is wrong.
        $this->careSite(64, State::Suspended, 164);
        $this->onDashboard(164, 'https://site64.example/');

        $report = $this->check()->run([$this->addon(63, AddonState::SUSPENDED, false, 'the add-on is Suspended, not Active'), $this->addon(64)], 'cron:daily');

        self::assertSame(['Demo Client (client 540), https://site63.example (Suspended): suspended in WHMCS (the add-on is Suspended, not Active) but connected on the dashboard'], $this->lines($report));
        self::assertSame(State::Connected, $this->storage->site(64)?->state);
    }

    public function testADueRetryIsMadeOnceAndConnectsOrIsReportedWithItsCount(): void
    {
        $this->careSite(71, State::Failed, null, 'The site could not be reached.', '2026-11-10 14:00:00', 2);
        $this->storage->saveLogin(71, new Login('owner', 'Secret1'));
        $this->careSite(72, State::Failed, null, 'The login was refused.', '2026-11-10 09:00:00', 0);
        $this->storage->saveLogin(72, new Login('owner', 'Secret1'));
        $this->careSite(73, State::Failed, null, 'The site could not be reached.', '2026-11-11 00:00:05', 1);
        $this->storage->saveLogin(73, new Login('owner', 'Secret1'));
        $site = $this->site;
        $this->site->during = static function () use ($site): void {
            // The second site still refuses the login.
            $site->refuses = count($site->calls) === 2 ? new SiteFailure(SiteFailure::LOGIN_REFUSED, 'The login was refused.') : null;
        };

        $report = $this->check()->run([$this->addon(71), $this->addon(72), $this->addon(73)], 'cron:daily');

        self::assertSame(['installPlugin https://site71.example owner', 'installPlugin https://site72.example owner'], $this->site->calls, 'due by the date: 14:00 today counts at ten past midnight; tomorrow does not');
        self::assertSame(State::Connected, $this->storage->site(71)?->state);
        self::assertSame([
            'Demo Client (client 540), https://site72.example (Failed): The login was refused. Retry 1 of 7.',
            'Demo Client (client 540), https://site73.example (Failed): The site could not be reached. Next retry on 2026-11-11.',
        ], $this->lines($report));
        self::assertSame(1, $this->storage->site(72)?->retryCount);
        // The same finding on a later day keeps its key although its words change (the retry count, the date): it is not news.
        self::assertSame(['not connected:add-on 72', 'not connected:add-on 73'], array_map(static fn ($line): string => $line->key(), $report->lines()));
    }

    public function testTheSeventhFailedRetryEndsTheRetriesInTheSameRun(): void
    {
        $this->careSite(71, State::Failed, null, 'The login was refused.', '2026-11-09 00:00:10', 6);
        $this->storage->saveLogin(71, new Login('owner', 'Secret1'));
        $this->careSite(72, State::Failed, null, 'The login was refused.', '2026-11-09 00:00:10', 7);
        $this->storage->saveLogin(72, new Login('owner', 'Secret1'));
        $this->site->refuses = new SiteFailure(SiteFailure::LOGIN_REFUSED, 'The login was refused.');

        $report = $this->check()->run([$this->addon(71), $this->addon(72)], 'cron:daily');

        self::assertSame(['installPlugin https://site71.example owner'], $this->site->calls, 'the seventh retry is made; an eighth never is');
        foreach ([71, 72] as $id) {
            $site = $this->storage->site($id);
            self::assertSame([State::Failed, null], [$site?->state, $site?->retryAfter], 'off the retry list');
            self::assertArrayNotHasKey($id, $this->storage->logins, 'and the stored login is deleted');
        }
        self::assertSame([
            'Demo Client (client 540), https://site71.example (Failed): not connected after seven retries: The login was refused. The client is asked to connect again.',
            'Demo Client (client 540), https://site72.example (Failed): not connected after seven retries: The login was refused. The client is asked to connect again.',
        ], $this->lines($report));
    }

    public function testARetryWithNothingLeftToRetryWithEndsUnderTheKeyItHasTheDayAfter(): void
    {
        // On the retry list, but the stored login is gone and no key is held.
        $this->careSite(74, State::Failed, null, 'The site could not be reached. It is tried again by itself.', '2026-11-10 00:00:00', 2);

        $ended = $this->check()->run([$this->addon(74)], 'cron:daily');
        $this->clock->now = new \DateTimeImmutable('2026-11-11 00:00:10');
        $after = $this->check()->run([$this->addon(74)], 'cron:daily');

        self::assertNull($this->storage->site(74)?->retryAfter);
        self::assertSame([], $this->site->calls);
        self::assertCount(1, $ended->lines());
        self::assertSame('not connected:add-on 74', $ended->lines()[0]->key());
        self::assertSame($ended->lines()[0]->key(), $after->lines()[0]->key());
    }

    public function testTheDayAfterTheRetriesEndedTheFindingIsTheSameFinding(): void
    {
        $this->careSite(71, State::Failed, null, 'The site could not be reached. It is tried again by itself.', '2026-11-10 00:00:00', 7);
        $this->storage->saveLogin(71, new Login('owner', 'Secret1'));

        $ended = $this->check()->run([$this->addon(71)], 'cron:daily');
        $this->clock->now = new \DateTimeImmutable('2026-11-11 00:00:10');
        $after = $this->check()->run([$this->addon(71)], 'cron:daily');

        self::assertSame(['Demo Client (client 540), https://site71.example (Failed): not connected after seven retries: The site could not be reached. The client is asked to connect again.'], $this->lines($ended));
        self::assertSame(['Demo Client (client 540), https://site71.example (Failed): The site could not be reached.'], $this->lines($after));
        self::assertSame($ended->lines()[0]->key(), $after->lines()[0]->key(), 'else the same finding is mailed as new two days running');
    }

    public function testADashboardFaultDuringARetryIsNotCountedAndStopsTheRunsRetriesAndMends(): void
    {
        $this->careSite(71, State::Failed, null, 'The site could not be reached.', '2026-11-09 00:00:10', 6);
        $this->storage->saveLogin(71, new Login('owner', 'Secret1'));
        $this->careSite(72, State::Failed, null, 'The site could not be reached.', '2026-11-09 00:00:10', 1);
        $this->storage->saveLogin(72, new Login('owner', 'Secret1'));
        $this->careSite(73, State::Connected, 173);
        $this->onDashboard(173, 'https://site73.example/', 'disconnected');
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard at dash.example.com could not be reached to connect the site: Operation timed out.');

        $report = $this->check()->run([$this->addon(71), $this->addon(72), $this->addon(73)], 'cron:daily');

        self::assertSame(['installPlugin https://site71.example owner'], $this->site->calls, 'no second site is tried once the dashboard has failed');
        $first = $this->storage->site(71);
        self::assertSame([State::Failed, 6], [$first?->state, $first?->retryCount], 'the client\'s last retry is not spent on our fault, and the retries are not ended');
        self::assertArrayHasKey(71, $this->storage->logins);
        self::assertSame(1, $this->storage->site(72)?->retryCount);
        self::assertStringStartsWith('The dashboard failed during the run, so no further retries or mends were made:', (string) $report->fault);
        self::assertNotContains('syncSite 173', $this->dashboard->calls);
        self::assertSame([
            'Demo Client (client 540), https://site71.example (Failed): the retry was stopped by the dashboard fault and is not counted',
            'Demo Client (client 540), https://site72.example (Failed): The site could not be reached. The retry was not made: the dashboard fault.',
            'Demo Client (client 540), https://site73.example (Connected): disconnected on the dashboard (MainWP Child plugin not detected or could not be reached); not mended in this run because of the dashboard fault',
        ], $this->lines($report));
    }

    /** Three connected sites, all disconnected on the dashboard; the dashboard's sync of the middle one runs out of time. */
    private function oneSiteHangs(): void
    {
        foreach ([73, 74, 75] as $id) {
            $this->careSite($id, State::Connected, 100 + $id);
            $this->onDashboard(100 + $id, 'https://site' . $id . '.example/', 'disconnected');
            $this->dashboard->mendedBy[100 + $id] = ['sync'];
        }
        $this->dashboard->syncFails[174] = new DashboardFailure(DashboardFailure::TIMED_OUT, 'The dashboard at dash.example.com did not answer in time to sync site 174: Operation timed out after 30001 milliseconds with 0 bytes received.');
    }

    public function testASiteTheDashboardWaitsForInVainIsThatSitesFailureAndTheRunGoesOn(): void
    {
        $this->oneSiteHangs();

        $report = $this->check()->run([$this->addon(73), $this->addon(74), $this->addon(75)], 'cron:daily');

        self::assertNull($report->fault, 'the dashboard answered when asked again: it was the site that did not');
        self::assertContains('syncSite 175', $this->dashboard->calls, 'the next add-on is mended');
        self::assertSame([State::Connected, State::Connected, State::Connected], [$this->storage->site(73)?->state, $this->storage->site(74)?->state, $this->storage->site(75)?->state]);
        self::assertSame(['Demo Client (client 540), https://site74.example (Connected): disconnected on the dashboard and could not be mended (the site did not answer in time).'], $this->lines($report));
        self::assertSame('connected', $this->dashboard->status[175]);
    }

    public function testATimeoutAfterWhichTheDashboardDoesNotAnswerEitherIsADashboardFaultAsBefore(): void
    {
        $this->oneSiteHangs();
        $this->dashboard->countsFail = new DashboardFailure(DashboardFailure::TIMED_OUT, 'The dashboard at dash.example.com did not answer in time to count the clients: Operation timed out.');

        $report = $this->check()->run([$this->addon(73), $this->addon(74), $this->addon(75)], 'cron:daily');

        self::assertStringStartsWith('The dashboard failed during the run, so no further retries or mends were made:', (string) $report->fault);
        self::assertNotContains('syncSite 175', $this->dashboard->calls);
        self::assertContains('Demo Client (client 540), https://site75.example (Connected): disconnected on the dashboard (MainWP Child plugin not detected or could not be reached); not mended in this run because of the dashboard fault', $this->lines($report));
    }

    public function testARetryTheDashboardWaitedOnTheSiteForIsCountedAndTheRunGoesOn(): void
    {
        $this->careSite(71, State::Failed, null, 'The site could not be reached.', '2026-11-09 00:00:10', 1);
        $this->storage->saveLogin(71, new Login('owner', 'Secret1'));
        $this->careSite(72, State::Failed, null, 'The site could not be reached.', '2026-11-09 00:00:10', 1);
        $this->storage->saveLogin(72, new Login('owner', 'Secret1'));
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::TIMED_OUT, 'The dashboard at dash.example.com did not answer in time to connect https://site71.example: Operation timed out.');

        $report = $this->check()->run([$this->addon(71), $this->addon(72)], 'cron:daily');

        self::assertNull($report->fault);
        self::assertSame(['installPlugin https://site71.example owner', 'installPlugin https://site72.example owner'], $this->site->calls, 'the next retry is made');
        self::assertSame([2, 2], [$this->storage->site(71)?->retryCount, $this->storage->site(72)?->retryCount], 'each counts: the dashboard answered');
        self::assertSame('Your site did not answer in time.' . CareEngine::RETRY_PROMISE, $this->storage->site(71)?->lastError);
    }

    public function testNoRetryIsMadeForCareThatIsNotActiveAndABusyAddonIsNoLine(): void
    {
        $this->careSite(71, State::Failed, null, 'The site could not be reached.', '2026-11-09 00:00:10', 1);
        $this->storage->saveLogin(71, new Login('owner', 'Secret1'));
        $this->careSite(72, State::Failed, null, 'The site could not be reached.', '2026-11-09 00:00:10', 1);
        $this->storage->saveLogin(72, new Login('owner', 'Secret1'));
        $this->storage->locked = [72];

        $report = $this->check()->run([$this->addon(71, AddonState::SUSPENDED, false, 'the add-on is Suspended, not Active'), $this->addon(72)], 'cron:daily');

        self::assertSame([], $this->site->calls);
        self::assertSame([], $this->lines($report), 'a suspended add-on\'s failed connect is nobody\'s problem now; a busy add-on is being dealt with');
        self::assertSame([1, 1], [$this->storage->site(71)?->retryCount, $this->storage->site(72)?->retryCount]);
    }

    public function testADisconnectedSiteIsMendedOrReported(): void
    {
        $this->careSite(71, State::Connected, 171);
        $this->onDashboard(171, 'https://site71.example/', 'disconnected');
        $this->dashboard->mendedBy[171] = ['sync'];
        $this->careSite(72, State::Connected, 172);
        $this->onDashboard(172, 'https://site72.example/', 'disconnected');
        $this->careSite(73, State::Failed, 173, 'Your site is no longer connected to care. Connect it again.');
        $this->onDashboard(173, 'https://site73.example/', 'disconnected');
        // Suspended on record for an add-on that is active, and disconnected on the dashboard: said, not mended.
        $this->careSite(74, State::Suspended, 174);
        $this->onDashboard(174, 'https://site74.example/', 'disconnected');

        $report = $this->check()->run([$this->addon(71), $this->addon(72), $this->addon(73), $this->addon(74)], 'cron:daily');

        self::assertSame(State::Connected, $this->storage->site(71)?->state);
        self::assertSame(State::Failed, $this->storage->site(72)?->state, 'no login is stored, so the client is asked');
        // A site that ends Failed is reported as it will be the day after, so that it is not news twice.
        self::assertSame([
            'Demo Client (client 540), https://site72.example (Failed): Your site is no longer connected to care. Connect it again.',
            'Demo Client (client 540), https://site73.example (Failed): Your site is no longer connected to care. Connect it again.',
            'Demo Client (client 540), https://site74.example (Suspended): disconnected on the dashboard (MainWP Child plugin not detected or could not be reached)',
        ], $this->lines($report));
        self::assertSame(['not connected:add-on 72', 'not connected:add-on 73', 'disconnected:add-on 74'], array_map(static fn ($line): string => $line->key(), $report->lines()));
        $mend = array_values(array_filter($this->storage->log, static fn ($entry): bool => $entry->action === 'mend' && $entry->target === 'add-on 72'));
        self::assertSame('dashboard site 172 (https://site72.example) is disconnected and could not be mended (no login is stored); the client is asked to connect again. The dashboard says: MainWP Child plugin not detected or could not be reached.', $mend[0]->detail ?? null, 'the log keeps why, for staff');
    }

    public function testAFailedSiteThatKeepsItsBrokenDashboardSiteIsStillOnTheRetryList(): void
    {
        // The mend gave up on these sites and the client tried again; the dashboard still holds their dead sites.
        $retry = 'The site could not be reached. It is tried again by itself.';
        $this->careSite(73, State::Failed, 173, $retry, '2026-11-10 00:00:00');     // due: retried, on the dashboard's own site
        $this->storage->saveLogin(73, new Login('owner', 'Secret1'));
        $this->dashboard->mendedBy[173] = ['admin', 'sync'];
        $this->careSite(74, State::Failed, 174, $retry, '2026-11-11 00:00:00');     // not due yet
        $this->storage->saveLogin(74, new Login('owner', 'Secret1'));
        $this->careSite(75, State::Failed, 175, 'Your site still holds an earlier care connection, which has to be cleared before it can be connected again. Please contact support.');
        $this->careSite(76, State::Connecting, 176);                                // an approval is out
        $this->storage->saveApproval(str_repeat('a', 64), 76, '2026-11-10 12:00:00');
        $this->careSite(77, State::Failed, 177, $retry, '2026-11-10 00:00:00', 7);  // the seventh retry has been made
        $this->storage->saveLogin(77, new Login('owner', 'Secret1'));
        foreach ([173, 174, 175, 176, 177] as $id) {
            $this->onDashboard($id, 'https://site' . ($id - 100) . '.example/', 'disconnected');
        }

        $report = $this->check()->run([$this->addon(73), $this->addon(74), $this->addon(75), $this->addon(76), $this->addon(77)], 'cron:daily');

        $again = $this->storage->site(73);
        self::assertSame([State::Connected, 173], [$again?->state, $again?->dashboardSiteId], 'the retry is made, and uses the dashboard\'s own site');
        self::assertSame([], array_values(array_filter($this->dashboard->calls, static fn (string $call): bool => str_starts_with($call, 'addSite'))), 'no second site');
        self::assertSame(['installPlugin https://site73.example owner'], $this->site->calls, 'and only the one that was due');
        self::assertSame([
            'Demo Client (client 540), https://site74.example (Failed): The site could not be reached. It is tried again by itself. Next retry on 2026-11-11.',
            'Demo Client (client 540), https://site75.example (Failed): Your site still holds an earlier care connection, which has to be cleared before it can be connected again. Please contact support.',
            'Demo Client (client 540), https://site76.example (Connecting): waiting for the client to approve the connection on their site',
            'Demo Client (client 540), https://site77.example (Failed): not connected after seven retries: The site could not be reached. The client is asked to connect again.',
        ], $this->lines($report));
        self::assertNull($this->storage->site(77)?->retryAfter, 'the retries end for a site with a dead dashboard site too');
    }

    public function testASiteNotConnectedOnRecordOnlyLetsGoOfADashboardSiteThatIsGone(): void
    {
        // The mend found the stored login refused; then staff removed the dead site on the dashboard.
        $this->careSite(72, State::Failed, 172, 'Your site is no longer connected to care. Connect it again.', null, 1);
        $this->storage->saveLogin(72, new Login('owner', 'Refused1'));
        $this->dashboard->unknown = [172];

        $report = $this->check()->run([$this->addon(72)], 'cron:daily');

        $site = $this->storage->site(72);
        self::assertSame([State::Failed, null, null, 'Your site is no longer connected to care. Connect it again.'], [$site?->state, $site?->dashboardSiteId, $site?->retryAfter, $site?->lastError], 'the client is still asked: no retry is promised');
        self::assertSame([], $this->site->calls, 'a login the site refused is not sent again');
        self::assertSame(['Demo Client (client 540), https://site72.example (Failed): Your site is no longer connected to care. Connect it again.'], $this->lines($report));
        $entry = end($this->storage->log);
        self::assertSame(['daily check', 'done'], [$entry->action, $entry->outcome]);
        $let = array_values(array_filter($this->storage->log, static fn ($entry): bool => $entry->action === 'connect'));
        self::assertSame(['done', 'the dashboard no longer holds dashboard site 172 (https://site72.example); the record lets go of it'], [$let[0]->outcome ?? null, $let[0]->detail ?? null]);

        // An approval is out for a site whose dead dashboard site was then removed: the approval can still be given.
        $this->careSite(73, State::Connecting, 173);
        $this->storage->saveApproval(str_repeat('b', 64), 73, '2026-11-10 12:00:00');
        $this->dashboard->unknown = [173];
        $report = $this->check()->run([$this->addon(72), $this->addon(73)], 'cron:daily');
        self::assertSame([State::Connecting, null], [$this->storage->site(73)?->state, $this->storage->site(73)?->dashboardSiteId]);
        self::assertContains('Demo Client (client 540), https://site73.example (Connecting): waiting for the client to approve the connection on their site', $this->lines($report));

        // A retry that is armed stays as it is.
        $this->careSite(74, State::Failed, 174, 'The site could not be reached. It is tried again by itself.', '2026-11-11 00:00:00', 3);
        $this->storage->saveLogin(74, new Login('owner', 'Secret1'));
        $this->dashboard->unknown = [174];
        $report = $this->check()->run([$this->addon(72), $this->addon(73), $this->addon(74)], 'cron:daily');
        $site = $this->storage->site(74);
        self::assertSame([State::Failed, null, '2026-11-11 00:00:00', 3], [$site?->state, $site?->dashboardSiteId, $site?->retryAfter, $site?->retryCount]);
        self::assertContains('Demo Client (client 540), https://site74.example (Failed): The site could not be reached. It is tried again by itself. Next retry on 2026-11-11.', $this->lines($report));
        self::assertCount(3, $report->lines());
    }

    public function testAConnectedSiteTheDashboardLostStartsItsSevenRetriesAtTheFirst(): void
    {
        // The mend tried the stored login once (its mark is the count); the login works, so the retry list may use it.
        $this->careSite(75, State::Connected, 175, null, null, 1);
        $this->storage->saveLogin(75, new Login('owner', 'Secret1'));
        $this->dashboard->unknown = [175];

        $this->check()->run([$this->addon(75)], 'cron:daily');

        $site = $this->storage->site(75);
        self::assertSame([State::Failed, null, 0, 'Your site is no longer connected to care. It is tried again by itself.'], [$site?->state, $site?->dashboardSiteId, $site?->retryCount, $site?->lastError]);
        self::assertNotNull($site?->retryAfter);
    }

    public function testAFailedSiteWhoseDashboardSiteIsConnectedAgainIsConnectedAgain(): void
    {
        $this->careSite(73, State::Failed, 173, 'Your site is no longer connected to care. Connect it again.');
        $this->onDashboard(173, 'https://site73.example/');

        $report = $this->check()->run([$this->addon(73)], 'cron:daily');

        self::assertSame([], $this->lines($report));
        self::assertSame([State::Connected, null], [$this->storage->site(73)?->state, $this->storage->site(73)?->lastError]);
    }

    public function testASiteMissingFromTheListIsAskedForSingly(): void
    {
        // A site flagged as staging is not in the list (bench, sixth run) but is there when asked for.
        $this->careSite(71, State::Connected, 171);
        // This one is gone from the dashboard.
        $this->careSite(72, State::Connected, 172);
        $this->storage->saveLogin(72, new Login('owner', 'Secret1'));
        $this->dashboard->unknown = [172];

        $report = $this->check()->run([$this->addon(71), $this->addon(72)], 'cron:daily');

        self::assertSame(State::Connected, $this->storage->site(71)?->state);
        $lost = $this->storage->site(72);
        self::assertSame([State::Failed, null], [$lost?->state, $lost?->dashboardSiteId]);
        self::assertNotNull($lost?->retryAfter, 'with a stored login it is added again by the retry list');
        self::assertSame(['Demo Client (client 540), https://site72.example (Failed): the dashboard no longer holds its site; it is added again with the stored login'], $this->lines($report));
        self::assertNull($report->fault);
    }

    public function testWhenNoneOfSeveralSitesIsFoundItIsADashboardFaultAndNoRecordIsChanged(): void
    {
        // Another dashboard's address in the settings, or a dashboard restored from nothing, would make every site look removed.
        foreach ([71, 72] as $id) {
            $this->careSite($id, State::Connected, 100 + $id);
            $this->storage->saveLogin($id, new Login('owner', 'Secret1'));
        }
        $this->dashboard->unknown = [171, 172];

        $report = $this->check()->run([$this->addon(71), $this->addon(72)], 'cron:daily');

        self::assertSame([State::Connected, State::Connected], [$this->storage->site(71)?->state, $this->storage->site(72)?->state]);
        self::assertSame('None of the 2 sites the module\'s records hold a dashboard site for was found on the dashboard; nothing was changed for them. Check the dashboard address and the API key in the settings.', $report->fault);
        self::assertSame([
            'Demo Client (client 540), https://site71.example (Connected): not found on the dashboard',
            'Demo Client (client 540), https://site72.example (Connected): not found on the dashboard',
        ], $this->lines($report));
    }

    public function testADashboardSiteAtAnotherAddressIsNotTheCareSitesAndNothingIsChanged(): void
    {
        // Another MainWP's address in the settings: its sites are numbered from 1 too, so an id can exist there, at another address.
        foreach ([3, 8, 12] as $n => $dashboardSiteId) {
            $this->careSite(71 + $n, State::Connected, $dashboardSiteId);
            $this->storage->saveLogin(71 + $n, new Login('owner', 'Secret1'));
        }
        $this->onDashboard(3, 'https://someone-else.example/', 'connected', 5);
        $this->dashboard->unknown = [8, 12];
        $before = $this->storage->sites;

        $report = $this->check()->run([$this->addon(71), $this->addon(72), $this->addon(73)], 'cron:daily');

        self::assertEquals($before, $this->storage->sites, 'no record is changed');
        self::assertSame([], $this->site->calls, 'and no login is sent anywhere');
        self::assertSame('The dashboard holds another site than the module\'s records say (dashboard site 3 is https://someone-else.example/, not https://site71.example); nothing was changed for the sites that were not found. Check the dashboard address and the API key in the settings.', $report->fault);
        self::assertSame([
            'Demo Client (client 540), https://site71.example (Connected): its dashboard site 3 is at another address on the dashboard: https://someone-else.example/',
            'Demo Client (client 540), https://site72.example (Connected): not found on the dashboard',
            'Demo Client (client 540), https://site73.example (Connected): not found on the dashboard',
        ], $this->lines($report));

        // One such site alone is said too, and its record is not brought in line with a site that is not its own.
        $this->storage->sites = [];
        $this->dashboard->unknown = [];
        $this->careSite(74, State::Failed, 3, 'Your site is no longer connected to care. Connect it again.');
        $report = $this->check()->run([$this->addon(74)], 'cron:daily');
        self::assertSame(State::Failed, $this->storage->site(74)?->state);
        self::assertSame(['Demo Client (client 540), https://site74.example (Failed): its dashboard site 3 is at another address on the dashboard: https://someone-else.example/'], $this->lines($report));

        // The same for an add-on that has ended: its site is not said to be "still on the dashboard" when that site is another's.
        $report = $this->check()->run([$this->addon(74, AddonState::ENDED, false, 'the add-on is Cancelled')], 'cron:daily');
        self::assertSame(['Demo Client (client 540), https://site74.example (Failed): ended in WHMCS (the add-on is Cancelled) but its dashboard site 3 is at another address on the dashboard: https://someone-else.example/'], $this->lines($report));

        // With it, even one site that is not found is not let go of: nothing shows that this is the module's dashboard.
        $this->careSite(75, State::Connected, 8);
        $this->storage->saveLogin(75, new Login('owner', 'Secret1'));
        $this->dashboard->unknown = [8];
        $report = $this->check()->run([$this->addon(74), $this->addon(75)], 'cron:daily');
        self::assertSame([State::Connected, 8], [$this->storage->site(75)?->state, $this->storage->site(75)?->dashboardSiteId]);
        self::assertNotNull($report->fault);

        // Once one of the module's own sites is seen there, the dashboard is the right one: a site that is gone is handled.
        $this->careSite(76, State::Connected, 176);
        $this->onDashboard(176, 'https://site76.example/');
        $report = $this->check()->run([$this->addon(74), $this->addon(75), $this->addon(76)], 'cron:daily');
        self::assertNull($report->fault);
        self::assertSame([State::Failed, null], [$this->storage->site(75)?->state, $this->storage->site(75)?->dashboardSiteId]);
    }

    public function testTheSameSiteWithOrWithoutWwwOrASlashIsTheSameAddress(): void
    {
        $this->careSite(71, State::Connected, 171);
        $this->onDashboard(171, 'http://www.site71.example/');

        $report = $this->check()->run([$this->addon(71)], 'cron:daily');

        self::assertSame([], $this->lines($report));
        self::assertNull($report->fault);
    }

    public function testSeveralSitesRemovedOnTheDashboardAreEachHandledWhenTheDashboardStillHoldsAnother(): void
    {
        // Staff removed two sites on the dashboard on one day; a third is still there, so the dashboard and the key are the right ones.
        foreach ([71, 72, 73] as $id) {
            $this->careSite($id, State::Connected, 100 + $id);
        }
        $this->storage->saveLogin(71, new Login('owner', 'Secret1'));
        $this->dashboard->unknown = [171, 172];

        $report = $this->check()->run([$this->addon(71), $this->addon(72), $this->addon(73)], 'cron:daily');

        self::assertNull($report->fault);
        self::assertSame([State::Failed, State::Failed, State::Connected], [$this->storage->site(71)?->state, $this->storage->site(72)?->state, $this->storage->site(73)?->state]);
        self::assertSame([
            'Demo Client (client 540), https://site71.example (Failed): the dashboard no longer holds its site; it is added again with the stored login',
            'Demo Client (client 540), https://site72.example (Failed): the dashboard no longer holds its site; the client is asked to connect again',
        ], $this->lines($report));
    }

    public function testASiteStaffConnectedByHandIsTakenOverAndAnythingElseOnTheDashboardIsOnlyReported(): void
    {
        $this->dashboard->clientsList[] = new DashboardClient(105, 'Other Client', 'other@example.com', 541);
        // Waiting to be connected; staff added it on the dashboard by hand, under the client, with "www." and a trailing slash.
        $this->careSite(71, State::Failed, null, 'The login page shows a captcha.');
        $this->onDashboard(301, 'https://www.site71.example/');
        // Under a client, and no care add-on covers it.
        $this->onDashboard(302, 'https://uncovered.example/', 'connected', 105);
        // Under no client: the dashboard owner's own sites are nobody's care.
        $this->onDashboard(303, 'https://ours.example/', 'connected', null);
        // The same address as a waiting care site, but not connected on the dashboard: nothing to take over.
        $this->careSite(74, State::Provisioned);
        $this->onDashboard(304, 'https://site74.example/', 'disconnected');

        $report = $this->check()->run([$this->addon(71), $this->addon(74)], 'cron:daily');

        $taken = $this->storage->site(71);
        self::assertSame([State::Connected, 301], [$taken?->state, $taken?->dashboardSiteId]);
        self::assertSame([
            'Demo Client (client 540), https://site74.example (Provisioned): waiting for the client to connect the site',
            'Other Client, https://uncovered.example/ (On the dashboard only, connected): under a client on the dashboard and not covered by any care add-on',
            'Demo Client, https://site74.example/ (On the dashboard only, disconnected): under a client on the dashboard and not covered by any care add-on',
        ], $this->lines($report), 'report only: nothing on the dashboard is removed');
        self::assertSame(['waiting for the client:add-on 74', 'not covered:https://uncovered.example/', 'not covered:https://site74.example/'], array_map(static fn ($line): string => $line->key(), $report->lines()));
        self::assertSame([], array_values(array_filter($this->dashboard->calls, static fn (string $c): bool => str_starts_with($c, 'removeSite'))));
    }

    public function testASiteUnderADashboardClientTheModuleDidNotMakeMakesNoLine(): void
    {
        // Made by hand on the dashboard: its note carries no "WHMCS client N".
        $this->dashboard->clientsList[] = new DashboardClient(106, 'Hand Made Client', 'hand@example.com', null);
        $this->onDashboard(305, 'https://hand-made.example/', 'connected', 106);
        // Under a client the dashboard no longer lists at all.
        $this->onDashboard(306, 'https://no-such-client.example/', 'connected', 999);
        $this->onDashboard(307, 'https://uncovered.example/', 'connected', 100);

        $report = $this->check()->run([], 'cron:daily');

        self::assertSame(['not covered:https://uncovered.example/'], array_map(static fn ($line): string => $line->key(), $report->lines()));
    }

    public function testASiteIsNotTakenOverWhenItIsNotClearWhoseItIs(): void
    {
        // Two care sites of the client wait at the same address.
        $this->storage->saveSite(new CareSite(71, 1171, 540, 'https://shared.example', State::Provisioned, 100));
        $this->storage->saveSite(new CareSite(72, 1172, 540, 'https://shared.example', State::Provisioned, 100));
        $this->onDashboard(301, 'https://shared.example/');
        // A suspended add-on's site is not taken over either.
        $this->careSite(73, State::Provisioned);
        $this->onDashboard(303, 'https://site73.example/');

        $this->check()->run([$this->addon(71), $this->addon(72), $this->addon(73, AddonState::SUSPENDED, false, 'the add-on is Suspended, not Active')], 'cron:daily');

        self::assertSame([State::Provisioned, State::Provisioned, State::Provisioned], [$this->storage->site(71)?->state, $this->storage->site(72)?->state, $this->storage->site(73)?->state]);
    }

    /** Version 0.6.0: an add-on whose hosting service has no domain waits, once a run, until the service has one. */
    public function testABilledAddonWhoseServiceHasNoDomainIsNotProvisionedAndIsReportedOncePerRun(): void
    {
        $noDomain = new AddonState(82, 1182, new Client(540, 'Demo Client', 'demo@example.com'), '', AddonState::ACTIVE, true);

        $report = $this->check()->run([$noDomain], 'cron:daily');

        self::assertNull($this->storage->site(82));
        self::assertSame(['Demo Client (client 540) (Not provisioned): its hosting service has no domain, so no site can be connected'], $this->lines($report));
        self::assertSame(['not provisioned:add-on 82'], array_map(static fn ($line): string => $line->key(), $report->lines()));
        $waiting = array_values(array_filter($this->storage->log, static fn ($e): bool => $e->action === 'provision'));
        self::assertCount(1, $waiting, 'one log line per run');
        self::assertSame(['add-on 82', 'waiting', 'not provisioned: its hosting service has no domain, so no site can be connected'], [$waiting[0]->target, $waiting[0]->outcome, $waiting[0]->detail]);
        self::assertSame([], array_values(array_filter($this->dashboard->calls, static fn (string $c): bool => str_starts_with($c, 'findClient') || str_starts_with($c, 'createClient'))));

        // The service has a domain the next night: it is provisioned as any other.
        $report = $this->check()->run([$this->addon(82)], 'cron:daily');
        self::assertSame(State::Provisioned, $this->storage->site(82)?->state);
        self::assertSame(['Demo Client (client 540), https://site82.example (Provisioned): waiting for the client to connect the site'], $this->lines($report));
    }

    public function testInDryRunAnAddonWithNoDomainIsReportedWithTheSameWords(): void
    {
        $noDomain = new AddonState(82, 1182, new Client(540, 'Demo Client', 'demo@example.com'), '', AddonState::ACTIVE, true);

        $report = $this->check(true)->run([$noDomain], 'cron:daily');

        self::assertSame(['Demo Client (client 540) (Not provisioned): its hosting service has no domain, so no site can be connected'], $this->lines($report));
        self::assertSame('waiting', $this->storage->log[0]->outcome);
    }

    /** Version 0.6.0: a second care add-on for a site that another care add-on of the client already covers. */
    public function testASecondCareAddonForACoveredSiteIsReported(): void
    {
        $this->storage->saveSite(new CareSite(71, 1171, 540, 'https://shop.example', State::Connected, 100, 301));
        $this->onDashboard(301, 'https://shop.example/');
        // Waits at the same site, written with "www." and a trailing slash.
        $this->storage->saveSite(new CareSite(72, 1172, 540, 'https://www.shop.example/', State::Provisioned, 100));
        // Failed at the same site as a suspended care site.
        $this->storage->saveSite(new CareSite(73, 1173, 540, 'https://blog.example', State::Suspended, 100, 303));
        $this->onDashboard(303, 'https://blog.example/', 'suspended');
        $this->storage->saveSite(new CareSite(74, 1174, 540, 'https://blog.example', State::Failed, 100, null, 0, null, 'The site could not be reached.', '2026-11-09 00:00:10'));
        $this->storage->saveLogin(74, new Login('owner', 'Secret1'));

        $report = $this->check()->run([$this->addon(71), $this->addon(72), $this->addon(73, AddonState::SUSPENDED, false, 'the add-on is Suspended, not Active'), $this->addon(74)], 'cron:daily');

        self::assertSame([
            'Demo Client (client 540), https://www.shop.example/ (Provisioned): a second care add-on for a site that add-on 71 already covers',
            'Demo Client (client 540), https://blog.example (Failed): a second care add-on for a site that add-on 73 already covers',
        ], $this->lines($report));
        self::assertSame(['second add-on:add-on 72', 'second add-on:add-on 74'], array_map(static fn ($line): string => $line->key(), $report->lines()));
        self::assertSame([], $this->site->calls, 'the second add-on is not retried at the covered site');
        self::assertNull($this->storage->login(74), 'a login kept for retries that will never run is deleted');
        self::assertNull($this->storage->site(74)?->retryAfter);
    }

    public function testInDryRunASecondCareAddonIsReportedWithTheSameWords(): void
    {
        $this->storage->saveSite(new CareSite(71, 1171, 540, 'https://shop.example', State::Connected, 100, 301));
        $this->storage->saveSite(new CareSite(72, 1172, 540, 'https://www.shop.example/', State::Provisioned, 100));

        $report = $this->check(true)->run([$this->addon(71), $this->addon(72)], 'cron:daily');

        self::assertContains('Demo Client (client 540), https://www.shop.example/ (Provisioned): a second care add-on for a site that add-on 71 already covers', $this->lines($report));
    }

    public function testACareSiteThatWasRemovedCoversNothing(): void
    {
        $this->storage->saveSite(new CareSite(77, 1177, 540, 'https://gone.example', State::Removed, 100));
        $this->storage->saveSite(new CareSite(78, 1178, 540, 'https://gone.example', State::Provisioned, 100));

        $report = $this->check()->run([$this->addon(78)], 'cron:daily');

        self::assertSame([], array_values(array_filter($this->lines($report), static fn (string $line): bool => str_contains($line, 'second care add-on'))));
    }

    public function testTwoDifferentSitesOfOneClientAreNoSecondAddon(): void
    {
        $this->storage->saveSite(new CareSite(71, 1171, 540, 'https://shop.example', State::Connected, 100, 301));
        $this->onDashboard(301, 'https://shop.example/');
        $this->storage->saveSite(new CareSite(72, 1172, 540, 'https://shop.example/blog', State::Provisioned, 100));
        $this->storage->saveSite(new CareSite(73, 1173, 540, 'https://other.example', State::Provisioned, 100));
        // Another client's care site at the same address is not this client's.
        $this->storage->saveSite(new CareSite(75, 1175, 541, 'https://elsewhere.example', State::Connected, 105, 305));
        $this->onDashboard(305, 'https://elsewhere.example/', 'connected', 105);
        $this->storage->saveSite(new CareSite(76, 1176, 540, 'https://elsewhere.example', State::Provisioned, 100));

        $report = $this->check()->run([$this->addon(71), $this->addon(72), $this->addon(73), $this->addon(76)], 'cron:daily');

        self::assertSame([], array_values(array_filter($this->lines($report), static fn (string $l): bool => str_contains($l, 'second care add-on'))));
    }

    /**
     * Version 0.6.0: a customer filed under a dashboard client made by hand (found by its email, its
     * note naming no WHMCS client) keeps that client's other sites out of the "not covered" lines.
     */
    public function testTheOtherSitesOfADashboardClientTakenByEmailAreNotReportedAsUncovered(): void
    {
        $this->dashboard->clients = [];
        $this->dashboard->clientsList = [new DashboardClient(12, 'Demo by hand', 'DEMO@example.com', null)];
        $this->dashboard->clientRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to create the client: Client email exists.');
        $this->onDashboard(305, 'https://hand-made.example/', 'connected', 12);

        $report = $this->check()->run([$this->addon(80)], 'cron:daily');

        self::assertSame(12, $this->storage->site(80)?->dashboardClientId);
        self::assertSame(['Demo Client (client 540), https://site80.example (Provisioned): waiting for the client to connect the site'], $this->lines($report));
    }

    public function testInDryRunNothingIsCalledAndTheRecordsAloneAreReported(): void
    {
        $this->careSite(71, State::Failed, null, 'The site could not be reached.', '2026-11-09 00:00:10', 1);
        $this->storage->saveLogin(71, new Login('owner', 'Secret1'));
        $this->careSite(72, State::Connected, 172);

        $report = $this->check(true)->run([$this->addon(70), $this->addon(71), $this->addon(72, AddonState::ENDED, false, 'the add-on is Cancelled, not Active')], 'cron:daily');

        self::assertSame([[], []], [$this->dashboard->calls, $this->site->calls]);
        self::assertNull($this->storage->site(70));
        self::assertSame(1, $this->storage->site(71)?->retryCount);
        self::assertSame([
            'Demo Client (client 540), https://site70.example (Not provisioned): would be provisioned (dry run)',
            'Demo Client (client 540), https://site71.example (Failed): The site could not be reached. Would be retried (dry run).',
            'Demo Client (client 540), https://site72.example (Connected): ended in WHMCS (the add-on is Cancelled, not Active) but its care site is not marked removed',
        ], $this->lines($report));
        self::assertNull($report->fault);
        $entry = end($this->storage->log);
        self::assertSame(['daily check', 'done', true], [$entry->action, $entry->outcome, $entry->dryRun]);
    }

    public function testOnlyOneRunAtATime(): void
    {
        $this->careSite(71, State::Failed, null, 'The site could not be reached.', '2026-11-09 00:00:10', 1);
        $this->storage->saveLogin(71, new Login('owner', 'Secret1'));
        $this->storage->locked = [0];

        $report = $this->check()->run([$this->addon(71)], 'admin:1');

        self::assertTrue($report->alreadyRunning);
        self::assertSame([[], []], [$this->dashboard->calls, $this->site->calls]);
        self::assertSame([], $this->lines($report));
    }

    public function testTheRunEndsWithOneLogRowThatCountsWhatItDid(): void
    {
        $this->careSite(71, State::Failed, null, 'The site could not be reached.', '2026-11-09 00:00:10', 1);
        $this->storage->saveLogin(71, new Login('owner', 'Secret1'));
        $this->careSite(72, State::Connected, 172);
        $this->onDashboard(172, 'https://site72.example/', 'disconnected');
        $this->dashboard->mendedBy[172] = ['sync'];
        $this->careSite(73, State::Provisioned);
        $this->onDashboard(301, 'https://site73.example/');

        $report = $this->check()->run([$this->addon(70), $this->addon(71), $this->addon(72), $this->addon(73)], 'cron:daily');

        $entry = end($this->storage->log);
        self::assertSame(['daily check', 'all care add-ons', 'done', 'cron:daily'], [$entry->action, $entry->target, $entry->outcome, $entry->actor]);
        self::assertSame('4 care add-ons: 1 provisioned, 1 retried (1 connected), 1 mended, 1 taken over, 1 to report', $entry->detail);

        $this->check()->run([$this->addon(70)], 'cron:daily');
        $entry = end($this->storage->log);
        self::assertStringStartsWith('1 care add-on: ', $entry->detail);
        self::assertCount(1, $report->lines());
        self::assertSame([], $this->storage->locked, 'the run\'s lock is given back');
    }
}
