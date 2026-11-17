<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\CareEngine;
use MainWpForWhmcs\Engine\CareSite;
use MainWpForWhmcs\Engine\DashboardFailure;
use MainWpForWhmcs\Engine\DashboardSite;
use MainWpForWhmcs\Engine\HeldKey;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\SiteFailure;
use MainWpForWhmcs\Engine\State;

require_once __DIR__ . '/Fakes.php';

/**
 * What the daily check does to one care site whose dashboard site is not as the module's record
 * says: mending a connection that broke, in the order the bench's sixth run showed to
 * work; taking over a site that is connected on the dashboard without the module knowing; and
 * recording that the dashboard no longer holds a site.
 */
final class MendTest extends TestCase
{
    private const SITE = 'https://clientsite.example';

    private RecordingDashboard $dashboard;
    private RecordingSite $site;
    private MemoryStorage $storage;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->dashboard = new RecordingDashboard();
        $this->site = new RecordingSite();
        $this->storage = new MemoryStorage();
        $this->clock = new FixedClock();
    }

    private FixedSecrets $secrets;

    private function engine(bool $dryRun = false): CareEngine
    {
        $this->secrets ??= new FixedSecrets();
        return new CareEngine($this->dashboard, $this->site, $this->storage, $this->clock, $dryRun, $this->secrets, 'wordpress@care.example.com');
    }

    /**
     * A Connected care site whose care account is gone: the dashboard, pointed at it, cannot reach
     * the site, and connects again only as one of $canConnectAs; $others are on record.
     *
     * @param list<string> $others
     * @param list<string> $canConnectAs
     */
    private function careAccountGone(array $others, array $canConnectAs, ?Login $stored = null): void
    {
        $this->broken($stored ?? new Login(FixedSecrets::CARE_LOGIN, 'OldCarePass1'));
        $this->storage->saveSite($this->storage->site(68)->withOtherAdmins($others));
        $this->dashboard->canConnectAs[101] = $canConnectAs;
        $this->dashboard->admins[101] = $others;
    }

    /** A Connected care site whose dashboard site 101 the dashboard now reports as disconnected. */
    private function broken(?Login $stored = null): void
    {
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Connected, 100, 101, 0, '2026-11-08 00:00:05'));
        if ($stored !== null) {
            $this->storage->saveLogin(68, $stored);
        }
        $this->dashboard->status[101] = 'disconnected';
    }

    /** @return list<array{0: string, 1: string}> action and outcome of each log row */
    private function logged(): array
    {
        return array_map(static fn ($e): array => [$e->action, $e->outcome], $this->storage->log);
    }

    public function testASyncAloneMendsASiteWhosePluginIsOnAgain(): void
    {
        $this->broken(new Login('owner', 'Secret1'));
        $this->dashboard->mendedBy[101] = ['sync'];

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['syncSite 101'], $this->dashboard->calls);
        self::assertSame([], $this->site->calls, 'the stored login is not used when the dashboard can mend it alone');
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, 101, '2026-11-09 14:00:00'], [$site?->state, $site?->dashboardSiteId, $site?->lastSync]);
        self::assertSame([['mend', 'done']], $this->logged());
        self::assertStringContainsString('by a sync', $this->storage->log[0]->detail);
    }

    public function testMainWpsReconnectMendsLostKeys(): void
    {
        $this->broken();
        $this->dashboard->mendedBy[101] = ['reconnect'];

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['syncSite 101', 'reconnectSite 101', 'site 101'], $this->dashboard->calls);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertStringContainsString('by MainWP\'s reconnect', $this->storage->log[0]->detail);
    }

    public function testTheStoredLoginSwitchesThePluginOnAgainWhenTheDashboardCannotMendItAlone(): void
    {
        $this->broken(new Login('owner', 'Secret1'));
        $this->dashboard->mendedBy[101] = ['plugin', 'sync'];
        $dashboard = $this->dashboard;
        $this->site->during = static function () use ($dashboard): void {
            $dashboard->pluginOn = true; // the module logged in and switched MainWP's plugin on again
        };

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['installPlugin ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame(['syncSite 101', 'reconnectSite 101', 'site 101', 'syncSite 101'], $this->dashboard->calls);
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, 0], [$site?->state, $site?->retryCount]);
        self::assertStringContainsString('with the stored login', end($this->storage->log)->detail);
        self::assertStringNotContainsString('Secret1', serialize($this->storage->log));
    }

    /** @return iterable<string, array{string}> */
    public static function loginsOfNoUse(): iterable
    {
        yield 'the login is refused' => [SiteFailure::LOGIN_REFUSED];
        yield 'the account is no administrator any more' => [SiteFailure::NOT_ADMIN];
        yield 'a captcha guards the login' => [SiteFailure::CAPTCHA];
        yield 'a two-factor login' => [SiteFailure::TWO_FACTOR];
        yield 'the login page has moved' => [SiteFailure::LOGIN_NOT_FOUND];
    }

    /** @dataProvider loginsOfNoUse */
    public function testWhenTheStoredLoginIsOfNoUseTheClientIsAskedToConnectAgain(string $kind): void
    {
        $this->broken(new Login('owner', 'Secret1'));
        $this->site->refuses = new SiteFailure($kind, 'The site did not let us in.');

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertFalse($outcome->ok);
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, 101, null, 'Your site is no longer connected to care. Connect it again.'], [$site?->state, $site?->dashboardSiteId, $site?->retryAfter, $site?->lastError], 'the dashboard still holds the site, so its id is kept for the next connect');
        self::assertStringContainsString('the client is asked to connect again', $outcome->message);
        $rows = $this->logged();
        self::assertSame(['mend', 'failed'], end($rows));
        self::assertStringContainsString('MainWP Child plugin not detected', end($this->storage->log)->detail, 'the dashboard\'s reason is for staff, in the log');
        self::assertStringNotContainsString('MainWP', (string) $site?->lastError);
    }

    public function testWithoutAStoredLoginTheClientIsAskedToConnectAgain(): void
    {
        $this->broken();

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertFalse($outcome->ok);
        self::assertSame([], $this->site->calls);
        self::assertSame(State::Failed, $this->storage->site(68)?->state);
    }

    /** @return iterable<string, array{string}> */
    public static function passingTrouble(): iterable
    {
        yield 'the site cannot be reached' => [SiteFailure::UNREACHABLE];
        yield 'a login limiter has locked us out for a while' => [SiteFailure::LIMITER];
        yield 'a firewall is in the way' => [SiteFailure::BLOCKED];
        yield 'the plugin could not be switched on' => [SiteFailure::INSTALL_FAILED];
    }

    /** @dataProvider passingTrouble */
    public function testTroubleThatMayPassLeavesTheSiteConnectedAndIsNotTriedWithTheLoginAgainTheNextDay(string $kind): void
    {
        $this->broken(new Login('owner', 'Secret1'));
        $this->site->refuses = new SiteFailure($kind, 'The site could not be reached.');

        $first = $this->engine()->mend(68, 'cron:daily');

        self::assertFalse($first->ok);
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, 101], [$site?->state, $site?->dashboardSiteId], 'an outage that heals by itself must not send the client to connect again');
        self::assertStringNotContainsString('the client is asked', $first->message);

        // The next day: the dashboard's own steps are tried again; the client's site is not logged in to a second time.
        $this->site->calls = [];
        $this->dashboard->calls = [];
        $second = $this->engine()->mend(68, 'cron:daily');
        self::assertFalse($second->ok);
        self::assertSame([], $this->site->calls, 'one login per disconnection: a login a day could lock the account');
        self::assertSame(['syncSite 101', 'reconnectSite 101', 'site 101'], $this->dashboard->calls);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
    }

    public function testAfterASiteHealedTheStoredLoginMayBeUsedForTheNextDisconnection(): void
    {
        $this->broken(new Login('owner', 'Secret1'));
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->engine()->mend(68, 'cron:daily');
        // It healed, and the daily check saw it connected.
        $this->engine()->seenOnDashboard(68, new DashboardSite(101, self::SITE . '/', 100, 'connected', '2026-11-10T00:00:05', ''), 'cron:daily');
        self::assertSame(0, $this->storage->site(68)?->retryCount);

        $this->site->refuses = null;
        $this->site->calls = [];
        $this->engine()->mend(68, 'cron:daily');

        self::assertSame(['installPlugin ' . self::SITE . ' owner'], $this->site->calls);
    }

    public function testAnotherAdministratorOnRecordConnectsTheSiteAndTheCareAccountIsMadeAgainUnderItsName(): void
    {
        $this->careAccountGone(['gone', 'owner'], ['owner']);

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame([
            'syncSite 101', 'reconnectSite 101', 'site 101',
            'setSiteAdmin 101 gone', 'syncSite 101',
            'setSiteAdmin 101 owner', 'syncSite 101',
            'administrators 101',
            'createAdministrator 101 ' . FixedSecrets::CARE_LOGIN . ' wordpress@care.example.com WordPress Care',
            'setSiteAdmin 101 ' . FixedSecrets::CARE_LOGIN, 'syncSite 101',
            'administrators 101',
        ], $this->dashboard->calls, 'the care account is made only once a sync has connected the site (bench, eighth run)');
        self::assertSame([], $this->site->calls, 'no login to the site');
        self::assertSame([FixedSecrets::CARE_LOGIN, FixedSecrets::CARE_PASSWORD], [$this->storage->logins[68]->username, $this->storage->logins[68]->password], 'the same name, a new password');
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, 0, ['gone', 'owner']], [$site?->state, $site?->retryCount, $site?->otherAdmins]);
        self::assertSame('dashboard site 101 (' . self::SITE . ') was disconnected and is connected again by pointing the dashboard at another administrator (owner) and making the care account again', end($this->storage->log)->detail);
        self::assertStringNotContainsString(FixedSecrets::CARE_PASSWORD, serialize($this->storage->log));
    }

    public function testACareAccountStillThereButDemotedIsMadeAnewUnderANewLoginWithThePlusTaggedAddress(): void
    {
        $this->careAccountGone(['owner'], ['owner']);
        $this->engine();
        $this->secrets->logins = [FixedSecrets::SECOND_LOGIN];
        $this->dashboard->createRefuses = [new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to make administrator ' . FixedSecrets::CARE_LOGIN . ' on site 101: ERROR: Sorry, that username already exists!')];

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame([[FixedSecrets::CARE_LOGIN, 'wordpress@care.example.com'], [FixedSecrets::SECOND_LOGIN, 'wordpress+' . FixedSecrets::SECOND_LOGIN . '@care.example.com']], array_map(static fn (array $c): array => [$c['login']->username, $c['email']], $this->dashboard->created));
        self::assertSame(FixedSecrets::SECOND_LOGIN, $this->storage->logins[68]->username);
    }

    public function testWhenTheCareAccountCannotBeMadeAgainTheSiteIsStillMendedAndTheStoredLoginIsDeleted(): void
    {
        $this->careAccountGone(['owner'], ['owner']);
        $this->engine();
        $this->secrets->logins = [FixedSecrets::SECOND_LOGIN];
        $refusal = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to make administrator a care account on site 101: ERROR: Sorry, that email address is already used!');
        $this->dashboard->createRefuses = [$refusal, $refusal];

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame([], $this->storage->logins);
        self::assertSame('owner', $this->dashboard->pointedAt[101]);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertSame('dashboard site 101 (' . self::SITE . ') was disconnected and is connected again by pointing the dashboard at another administrator (owner); the care account could not be made again: The dashboard refused to make administrator a care account on site 101: ERROR: Sorry, that email address is already used!', end($this->storage->log)->detail);
    }

    public function testACareAccountStillAnAdministratorIsUsedAgainAndNoSecondOneIsMade(): void
    {
        // The disconnection was passing: the care account is still an administrator of the site.
        $this->careAccountGone(['owner'], ['owner', FixedSecrets::CARE_LOGIN]);
        $this->dashboard->admins[101] = ['owner', FixedSecrets::CARE_LOGIN];

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame([], $this->dashboard->created, 'no second care account on the client\'s site');
        self::assertSame([
            'syncSite 101', 'reconnectSite 101', 'site 101',
            'setSiteAdmin 101 owner', 'syncSite 101',
            'administrators 101',
            'setSiteAdmin 101 ' . FixedSecrets::CARE_LOGIN, 'syncSite 101',
            'administrators 101',
        ], $this->dashboard->calls);
        self::assertSame(FixedSecrets::CARE_LOGIN, $this->dashboard->pointedAt[101]);
        self::assertSame([FixedSecrets::CARE_LOGIN, 'OldCarePass1'], [$this->storage->logins[68]->username, $this->storage->logins[68]->password], 'its password is kept');
        self::assertSame('dashboard site 101 (' . self::SITE . ') was disconnected and is connected again by pointing the dashboard at another administrator (owner) and back at the care account', end($this->storage->log)->detail);
    }

    public function testACareAccountMadeAgainThatTheDashboardStillCannotReachLeavesItPointedAtTheOtherAdministrator(): void
    {
        $this->careAccountGone(['owner'], ['owner']);
        $this->dashboard->neverConnectsAs = [FixedSecrets::CARE_LOGIN];

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertCount(1, $this->dashboard->created);
        self::assertSame('owner', $this->dashboard->pointedAt[101]);
        self::assertSame(['setSiteAdmin 101 ' . FixedSecrets::CARE_LOGIN, 'syncSite 101', 'setSiteAdmin 101 owner', 'syncSite 101', 'administrators 101'], array_slice($this->dashboard->calls, -5));
        self::assertSame('connected', $this->dashboard->status[101]);
        self::assertSame([], $this->storage->logins);
        self::assertStringEndsWith('(owner); the care account could not be made again: the dashboard could not reach the site as ' . FixedSecrets::CARE_LOGIN, end($this->storage->log)->detail);
    }

    public function testADashboardFaultWhileAnotherAdministratorIsTriedPointsTheDashboardBackAtTheCareAccount(): void
    {
        $this->careAccountGone(['owner'], ['owner']);
        $this->dashboard->syncRefuses['owner'] = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached: Operation timed out.');

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertSame([false, true], [$outcome->ok, $outcome->dashboardFault]);
        self::assertSame(['setSiteAdmin 101 owner', 'syncSite 101', 'setSiteAdmin 101 ' . FixedSecrets::CARE_LOGIN], array_slice($this->dashboard->calls, -3));
        self::assertSame(FixedSecrets::CARE_LOGIN, $this->dashboard->pointedAt[101], 'not left on the client\'s own administrator, so that the next mend makes the care account again');
        self::assertSame(FixedSecrets::CARE_LOGIN, $this->storage->logins[68]->username);
    }

    public function testTheFirstOtherAdministratorThatConnectsEndsTheSearch(): void
    {
        $this->careAccountGone(['owner', 'editor'], ['owner', 'editor']);

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertNotContains('setSiteAdmin 101 editor', $this->dashboard->calls);
        self::assertStringContainsString('another administrator (owner)', end($this->storage->log)->detail);
    }

    public function testAStoredLoginThatIsNoCareLoginIsKeptAndNoCareAccountIsMade(): void
    {
        $this->careAccountGone(['editor'], ['editor'], new Login('owner', 'Secret1'));

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame([], $this->dashboard->created);
        self::assertSame('owner', $this->storage->logins[68]->username);
        self::assertSame('dashboard site 101 (' . self::SITE . ') was disconnected and is connected again by pointing the dashboard at another administrator (editor)', end($this->storage->log)->detail);
    }

    public function testWhenNoOtherAdministratorConnectsTheDashboardIsPointedBackAndTheStoredLoginIsTried(): void
    {
        $this->careAccountGone(['gone'], [FixedSecrets::CARE_LOGIN]);

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['syncSite 101', 'reconnectSite 101', 'site 101', 'setSiteAdmin 101 gone', 'syncSite 101', 'setSiteAdmin 101 ' . FixedSecrets::CARE_LOGIN, 'syncSite 101'], $this->dashboard->calls);
        self::assertSame(['installPlugin ' . self::SITE . ' ' . FixedSecrets::CARE_LOGIN], $this->site->calls);
        self::assertStringContainsString('with the stored login', end($this->storage->log)->detail);
    }

    public function testTheOtherAdministratorsAreTriedOnEveryMendAndAreNotCountedAsTheStoredLoginsTry(): void
    {
        $this->careAccountGone(['gone'], ['nobody']);
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->engine()->mend(68, 'cron:daily');
        self::assertSame(1, $this->storage->site(68)?->retryCount, 'only the login to the site counts');
        $this->dashboard->calls = [];
        $this->site->calls = [];

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertFalse($outcome->ok);
        self::assertContains('setSiteAdmin 101 gone', $this->dashboard->calls);
        self::assertSame([], $this->site->calls);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
    }

    public function testANameTheDashboardRefusesToBePointedAtIsPassedOver(): void
    {
        $this->careAccountGone(['gone', 'owner'], ['owner'], new Login('owner', 'Secret1'));
        $this->dashboard->adminRefuses = ['gone' => new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to edit site 101: no such administrator.')];

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertStringContainsString('(owner)', end($this->storage->log)->detail);
    }

    public function testADashboardFaultWhileMendingChangesNothingAndIsSaidAsSuch(): void
    {
        $this->broken(new Login('owner', 'Secret1'));
        $before = $this->storage->site(68);
        $this->dashboard->offline = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');

        $outcome = $this->engine()->mend(68, 'cron:daily');

        self::assertSame([false, true], [$outcome->ok, $outcome->dashboardFault]);
        self::assertEquals($before, $this->storage->site(68));
        self::assertSame([], $this->site->calls, 'the client\'s site is not logged in to because our dashboard is down');
    }

    public function testMendingTouchesOnlyAConnectedSiteAndNothingInDryRun(): void
    {
        $this->broken(new Login('owner', 'Secret1'));
        self::assertTrue($this->engine(true)->mend(68, 'cron:daily')->ok);
        self::assertSame([[], []], [$this->dashboard->calls, $this->site->calls]);
        $rows = $this->logged();
        self::assertSame(['mend', 'would run'], end($rows));

        $this->storage->sites[68] = $this->storage->sites[68]->withState(State::Suspended);
        $outcome = $this->engine()->mend(68, 'cron:daily');
        self::assertSame([true, false], [$outcome->ok, $outcome->attempted], 'a suspended site is not mended');
        self::assertSame([], $this->dashboard->calls);

        $this->storage->sites[68] = $this->storage->sites[68]->withState(State::Connected);
        $this->storage->locked = [68];
        $busy = $this->engine()->mend(68, 'cron:daily');
        self::assertSame([false, false], [$busy->ok, $busy->attempted]);
    }

    public function testASiteConnectedOnTheDashboardByHandIsTakenOver(): void
    {
        // Staff installed the plugin and added the site on the dashboard, under the client, as the spec allows.
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Failed, 100, null, 2, null, 'The login page shows a captcha.', '2026-11-10 14:00:00'));
        $this->dashboard->admins[555] = ['staffadmin', 'owner'];

        $outcome = $this->engine()->adoptSite(68, 555, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, 555, null, null, 0], [$site?->state, $site?->dashboardSiteId, $site?->lastError, $site?->retryAfter, $site?->retryCount]);
        self::assertSame(['administrators 555'], $this->dashboard->calls, 'nothing is changed on the dashboard; its other administrators are only read');
        self::assertSame(['staffadmin', 'owner'], $site?->otherAdmins, 'the administrator staff chose stays; the others are recorded');
        self::assertSame([['connect', 'done']], $this->logged());
        self::assertStringContainsString('the dashboard already held it', $this->storage->log[0]->detail);
    }

    public function testTakingASiteOverDeletesALoginTypedForAFailedConnect(): void
    {
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Failed, 100, null, 2, null, 'The login page shows a captcha.', '2026-11-10 14:00:00'));
        $this->storage->saveLogin(68, new Login('owner', 'ClientPw1'));

        $outcome = $this->engine()->adoptSite(68, 555, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertNull($this->storage->login(68), 'the client\'s own password is not kept once the site is connected');
        self::assertStringContainsString('the login typed is deleted', end($this->storage->log)->detail);
        self::assertStringNotContainsString('ClientPw1', serialize($this->storage->log));
    }

    public function testTakingASiteOverEndsWhatAKeyAttemptLeftOpen(): void
    {
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Failed, 100, null, 1, null, 'The plugin could not be installed.', '2026-11-10 14:00:00'));
        $this->storage->saveApproval('h', 68, '2026-11-10 14:00:00');
        $this->storage->claimApproval('h', new HeldKey(new Login('owner', 'BenchKey0123456789ABCDEF'), new Login('wpcarek7m2x9ab', 'CarePass')), '2026-11-09 14:00:00');

        $this->engine()->adoptSite(68, 555, 'cron:daily');

        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertNull($this->storage->heldKey(68));
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls);
    }

    public function testOnlyASiteThatWaitsToBeConnectedIsTakenOver(): void
    {
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Connected, 100, 101));

        $outcome = $this->engine()->adoptSite(68, 555, 'cron:daily');

        self::assertFalse($outcome->ok);
        self::assertSame(101, $this->storage->site(68)?->dashboardSiteId);
    }

    public function testASiteSeenConnectedOnTheDashboardHasItsRecordBroughtInLine(): void
    {
        $row = new DashboardSite(101, self::SITE . '/', 100, 'connected', '2026-11-10T00:00:05', '');

        // Connected: only the last sync moves, and nothing is logged for it.
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Connected, 100, 101, 0, '2026-11-08 00:00:05'));
        $this->engine()->seenOnDashboard(68, $row, 'cron:daily');
        // MainWP gives the time in UTC; the module keeps its times in the clock's own zone.
        $local = (new \DateTimeImmutable('2026-11-10T00:00:05', new \DateTimeZone('UTC')))->setTimezone($this->clock->now()->getTimezone())->format('Y-m-d H:i:s');
        self::assertSame($local, $this->storage->site(68)?->lastSync);
        self::assertSame([], $this->storage->log);

        // Failed after a connection that broke, and since mended by hand: Connected again.
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Failed, 100, 101, 1, null, 'Your site is no longer connected to care. Connect it again.'));
        $this->engine()->seenOnDashboard(68, $row, 'cron:daily');
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, null, 0], [$site?->state, $site?->lastError, $site?->retryCount], 'the client is no longer asked to connect a site that is connected');
        self::assertSame([['connect', 'done']], $this->logged());

        // Another dashboard site than the one on record changes nothing.
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Failed, 100, 101, 1, null, 'x'));
        $this->engine()->seenOnDashboard(68, new DashboardSite(999, self::SITE . '/', 100, 'connected', null, ''), 'cron:daily');
        self::assertSame(State::Failed, $this->storage->site(68)?->state);
    }

    public function testASiteTheDashboardNoLongerHoldsGoesBackOnTheRetryListWhenALoginIsStored(): void
    {
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Connected, 100, 101, 0, '2026-11-08 00:00:05'));
        $this->storage->saveLogin(68, new Login('owner', 'Secret1'));
        $this->dashboard->unknown = [101];

        $outcome = $this->engine()->lostFromDashboard(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['site 101'], $this->dashboard->calls, 'asked once more, for this one site, before anything is changed');
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null, '2026-11-10 14:00:00', 'Your site is no longer connected to care. It is tried again by itself.'], [$site?->state, $site?->dashboardSiteId, $site?->retryAfter, $site?->lastError], 'the stored login reconnects the site without asking the client');
        self::assertSame([['connect', 'failed']], $this->logged());
    }

    public function testWithoutAStoredLoginASiteTheDashboardLostAsksTheClientAtOnce(): void
    {
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Connected, 100, 101));
        $this->dashboard->unknown = [101];

        $this->engine()->lostFromDashboard(68, 'cron:daily');

        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null, null, 'Your site is no longer connected to care. Connect it again.'], [$site?->state, $site?->dashboardSiteId, $site?->retryAfter, $site?->lastError]);
    }

    public function testASiteTheDashboardStillHoldsOrCannotSpeakForIsNotMarkedLost(): void
    {
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Connected, 100, 101));
        $before = $this->storage->site(68);

        self::assertFalse($this->engine()->lostFromDashboard(68, 'cron:daily')->ok, 'the dashboard holds it');
        $this->dashboard->offline = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        self::assertFalse($this->engine()->lostFromDashboard(68, 'cron:daily')->ok, 'the dashboard cannot say');

        self::assertEquals($before, $this->storage->site(68));
    }
}
