<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\CareEngine;
use MainWpForWhmcs\Engine\CareSite;
use MainWpForWhmcs\Engine\Client;
use MainWpForWhmcs\Engine\DashboardFailure;
use MainWpForWhmcs\Engine\DashboardSite;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\SiteFailure;
use MainWpForWhmcs\Engine\State;

require_once __DIR__ . '/Fakes.php';

/**
 * The care account on the password route: the site is connected as the login typed,
 * then the module makes the care account through the dashboard, points the dashboard at it and
 * deletes the login typed; a care login typed is connected without logging in to the site; and the
 * site's other administrators are recorded whenever a site becomes Connected.
 */
final class CareAccountTest extends TestCase
{
    private const SITE = 'https://clientsite.example';
    private const EMAIL = 'wordpress@care.example.com';
    private const CARE = FixedSecrets::CARE_LOGIN;
    private const SECOND = FixedSecrets::SECOND_LOGIN;

    private RecordingDashboard $dashboard;
    private RecordingSite $site;
    private MemoryStorage $storage;
    private FixedSecrets $secrets;

    protected function setUp(): void
    {
        $this->dashboard = new RecordingDashboard();
        $this->site = new RecordingSite();
        $this->storage = new MemoryStorage();
        $this->secrets = new FixedSecrets();
        $this->engine()->provision(68, 1176, new Client(540, 'Demo Client', 'demo@example.com'), self::SITE, 'hook:AddonActivated');
        $this->dashboard->calls = [];
        $this->dashboard->admins[101] = ['owner'];
        $this->storage->log = [];
    }

    private function engine(bool $dryRun = false, string $careEmail = self::EMAIL): CareEngine
    {
        return new CareEngine($this->dashboard, $this->site, $this->storage, new FixedClock(), $dryRun, $this->secrets, $careEmail);
    }

    private function lastLog(): string
    {
        $entry = end($this->storage->log);
        return $entry === false ? '' : $entry->detail;
    }

    private static function refusal(string $words): DashboardFailure
    {
        return new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to make administrator a care account on site 101: ' . $words);
    }

    public function testThePasswordRouteMakesTheCareAccountPointsTheDashboardAtItAndDeletesTheLoginTyped(): void
    {
        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['installPlugin ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame([
            'addSite 100 ' . self::SITE . ' owner',
            'createAdministrator 101 ' . self::CARE . ' ' . self::EMAIL . ' WordPress Care',
            'setSiteAdmin 101 ' . self::CARE,
            'syncSite 101',
            'administrators 101',
        ], $this->dashboard->calls);
        self::assertSame(FixedSecrets::CARE_PASSWORD, $this->dashboard->created[0]['login']->password);
        self::assertSame([self::CARE, FixedSecrets::CARE_PASSWORD], [$this->storage->logins[68]->username, $this->storage->logins[68]->password], 'the care account is the stored login; the login typed is gone');
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, 101, ['owner']], [$site?->state, $site?->dashboardSiteId, $site?->otherAdmins]);
        self::assertSame(self::SITE . ' connected as dashboard site 101 with the care account ' . self::CARE, $this->lastLog());
        self::assertStringNotContainsString('Secret1', serialize($this->storage->log));
        self::assertStringNotContainsString(FixedSecrets::CARE_PASSWORD, serialize($this->storage->log) . $outcome->message);
    }

    public function testASiteThatRefusesTheAccountGetsANewLoginWithTheAddressPlusTaggedWithIt(): void
    {
        $this->secrets->logins = [self::CARE, self::SECOND];
        $this->secrets->passwords = [FixedSecrets::CARE_PASSWORD, FixedSecrets::SECOND_PASSWORD];
        $this->dashboard->createRefuses = [self::refusal('ERROR: Sorry, that email address is already used!')];

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame([[self::CARE, self::EMAIL], [self::SECOND, 'wordpress+' . self::SECOND . '@care.example.com']], array_map(static fn (array $c): array => [$c['login']->username, $c['email']], $this->dashboard->created));
        self::assertSame(FixedSecrets::SECOND_PASSWORD, $this->dashboard->created[1]['login']->password, 'the second login is drawn afresh, password and all');
        self::assertSame([self::SECOND, FixedSecrets::SECOND_PASSWORD], [$this->storage->logins[68]->username, $this->storage->logins[68]->password]);
        self::assertSame('setSiteAdmin 101 ' . self::SECOND, $this->dashboard->calls[3]);
    }

    public function testASecondRefusalLeavesTheSiteConnectedAsTheLoginTypedAndTheLoginIsDeleted(): void
    {
        $this->secrets->logins = [self::CARE, self::SECOND];
        $this->dashboard->createRefuses = [self::refusal('ERROR: Sorry, that username already exists!'), self::refusal('ERROR: Sorry, that email address is already used! ' . FixedSecrets::CARE_PASSWORD)];

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertCount(2, $this->dashboard->created);
        self::assertNotContains('setSiteAdmin 101 ' . self::CARE, $this->dashboard->calls, 'the dashboard was never pointed at an account that was not made');
        self::assertSame([], $this->storage->logins, 'the login typed is deleted although no care account took its place');
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, ['owner']], [$site?->state, $site?->otherAdmins]);
        self::assertSame(self::SITE . ' connected as dashboard site 101 as owner; the care account could not be made: The dashboard refused to make administrator a care account on site 101: ERROR: Sorry, that email address is already used! (hidden)', $this->lastLog());
    }

    public function testWhenTheDashboardCannotReachTheSiteAsTheCareAccountItIsPointedBackAtTheLoginTyped(): void
    {
        $this->dashboard->canConnectAs[101] = ['owner'];
        $this->dashboard->neverConnectsAs = [self::CARE];

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame([
            'addSite 100 ' . self::SITE . ' owner',
            'createAdministrator 101 ' . self::CARE . ' ' . self::EMAIL . ' WordPress Care',
            'setSiteAdmin 101 ' . self::CARE,
            'syncSite 101',
            'setSiteAdmin 101 owner',
            'syncSite 101',
            'administrators 101',
        ], $this->dashboard->calls);
        self::assertSame('owner', $this->dashboard->pointedAt[101]);
        self::assertSame('connected', $this->dashboard->status[101]);
        self::assertSame([], $this->storage->logins);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertSame(self::SITE . ' connected as dashboard site 101 as owner; the care account could not be made: the dashboard could not reach the site as ' . self::CARE, $this->lastLog());
    }

    public function testADashboardFaultWhileTheAccountIsMadeLeavesTheSiteConnectedAsTheLoginTyped(): void
    {
        $this->dashboard->createRefuses = [new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.')];

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertCount(1, $this->dashboard->created, 'only a refusal by the site is tried a second time');
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertSame([], $this->storage->logins);
        self::assertStringEndsWith('as owner; the care account could not be made: The dashboard could not be reached.', $this->lastLog());
    }

    public function testWithoutACareAccountEmailTheSiteIsConnectedAsTheLoginTypedAndTheLogSaysWhy(): void
    {
        $outcome = $this->engine(false, '')->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame([], $this->dashboard->created);
        self::assertSame(['addSite 100 ' . self::SITE . ' owner', 'administrators 101'], $this->dashboard->calls);
        self::assertSame([], $this->storage->logins);
        self::assertSame(self::SITE . ' connected as dashboard site 101 as owner; no care account was made because no Care Account Email is set', $this->lastLog());
    }

    public function testACareLoginTypedIsConnectedByTheDashboardWithoutALoginToTheSite(): void
    {
        $this->dashboard->admins[101] = ['owner', self::CARE];

        $outcome = $this->engine()->connect(68, new Login(self::CARE, 'HostMade1'), 'admin:1');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame([], $this->site->calls, 'the host prepared the site: nobody logs in to it');
        self::assertSame(['addSite 100 ' . self::SITE . ' ' . self::CARE, 'administrators 101'], $this->dashboard->calls);
        self::assertSame([], $this->dashboard->created);
        self::assertSame([self::CARE, 'HostMade1'], [$this->storage->logins[68]->username, $this->storage->logins[68]->password]);
        self::assertSame(['owner'], $this->storage->site(68)?->otherAdmins);
        self::assertSame(self::SITE . ' connected as dashboard site 101 with the care account ' . self::CARE, $this->lastLog());
    }

    public function testACareLoginTheDashboardRefusesIsUsedToSwitchThePluginOnAndTheDashboardIsAskedAgain(): void
    {
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to add the site: MainWP Child plugin not detected.');
        $dashboard = $this->dashboard;
        $this->site->during = static function () use ($dashboard): void {
            $dashboard->addRefuses = null;
        };

        $outcome = $this->engine()->connect(68, new Login(self::CARE, 'HostMade1'), 'admin:1');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['installPlugin ' . self::SITE . ' ' . self::CARE], $this->site->calls);
        self::assertSame(['addSite 100 ' . self::SITE . ' ' . self::CARE, 'addSite 100 ' . self::SITE . ' ' . self::CARE, 'administrators 101'], $this->dashboard->calls);
        self::assertSame([], $this->dashboard->created);
        self::assertSame(self::CARE, $this->storage->logins[68]->username);
    }

    public function testACareLoginTheDashboardCannotTakeForAnotherReasonIsNotSentToTheSite(): void
    {
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');

        $outcome = $this->engine()->connect(68, new Login(self::CARE, 'HostMade1'), 'admin:1');

        self::assertFalse($outcome->ok);
        self::assertSame([], $this->site->calls);
        self::assertSame(State::Failed, $this->storage->site(68)?->state);
        self::assertSame('HostMade1', $this->storage->logins[68]->password, 'kept for the retry, as any login');
    }

    public function testAFailedConnectKeepsTheLoginTypedAndItsRetryMakesTheCareAccount(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');
        self::assertSame('Secret1', $this->storage->logins[68]->password);
        $this->site->refuses = null;
        $this->dashboard->calls = [];

        $outcome = $this->engine()->retryConnect(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertContains('createAdministrator 101 ' . self::CARE . ' ' . self::EMAIL . ' WordPress Care', $this->dashboard->calls);
        self::assertSame(self::CARE, $this->storage->logins[68]->username);
    }

    public function testARetryWithAStoredCareLoginFollowsTheHostsRoute(): void
    {
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->engine()->connect(68, new Login(self::CARE, 'HostMade1'), 'admin:1');
        $this->dashboard->addRefuses = null;
        $this->dashboard->calls = [];

        $outcome = $this->engine()->retryConnect(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame([], $this->site->calls);
        self::assertSame(['sites', 'addSite 100 ' . self::SITE . ' ' . self::CARE, 'administrators 101'], $this->dashboard->calls);
        self::assertSame(self::CARE, $this->storage->logins[68]->username);
    }

    public function testARetryThatFindsTheSiteOnTheDashboardMakesTheCareAccountBeforeItIsRecordedConnected(): void
    {
        $this->storage->saveSite((new CareSite(68, 1176, 540, self::SITE, State::Failed, 100))->failedWithRetry('The site could not be reached.', '2026-11-09 00:00:00'));
        $this->storage->saveLogin(68, new Login('owner', 'Secret1'));
        $this->dashboard->sitesList = [new DashboardSite(101, self::SITE . '/', 100, 'connected', '2026-11-09T00:00:05', '')];

        $outcome = $this->engine()->retryConnect(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame([], $this->site->calls);
        self::assertSame(['sites', 'createAdministrator 101 ' . self::CARE . ' ' . self::EMAIL . ' WordPress Care', 'setSiteAdmin 101 ' . self::CARE, 'syncSite 101', 'administrators 101'], $this->dashboard->calls);
        self::assertSame(self::CARE, $this->storage->logins[68]->username);
        self::assertSame(self::SITE . ' connected as dashboard site 101 with the care account ' . self::CARE . ' (the dashboard already held it)', $this->lastLog());
    }

    public function testTheOtherAdministratorsAreAtMostTenInMainWpsOrderWithoutCareAccounts(): void
    {
        $this->dashboard->admins[101] = ['zed', 'wpcare00000000', 'amy', 'a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8', 'a9', 'wpcare-k7m2x9'];

        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertSame(['zed', 'amy', 'a1', 'a2', 'a3', 'a4', 'a5', 'a6', 'a7', 'a8'], $this->storage->site(68)?->otherAdmins);
    }

    public function testWhenTheListingFailsAfterAPasswordConnectAtLeastTheLoginTypedIsRecorded(): void
    {
        $this->dashboard->adminsRefuse = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->site->loginName = 'owner';

        $outcome = $this->engine()->connect(68, new Login('owner@example.com', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, 'a failure of the listing never fails the connect');
        self::assertSame(['owner'], $this->storage->site(68)?->otherAdmins);
        self::assertSame(self::CARE, $this->storage->logins[68]->username);
    }

    public function testWhenTheListingFailsAfterAHostsConnectTheRecordIsLeftAsItWas(): void
    {
        $this->storage->saveSite((new CareSite(68, 1176, 540, self::SITE, State::Provisioned, 100))->withOtherAdmins(['earlier']));
        $this->dashboard->adminsRefuse = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to list the administrators of site 101: No website found.');

        $outcome = $this->engine()->connect(68, new Login(self::CARE, 'HostMade1'), 'admin:1');

        self::assertTrue($outcome->ok);
        self::assertSame(['earlier'], $this->storage->site(68)?->otherAdmins);
    }

    public function testInDryRunTheConnectNamesTheCareAccountStepAndSendsNothing(): void
    {
        $outcome = $this->engine(true)->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertSame('Dry run: would install MainWP\'s plugin on ' . self::SITE . ' as owner and connect it to the dashboard, then make the care account.', $outcome->message);
        self::assertSame([[], [], []], [$this->site->calls, $this->dashboard->calls, $this->storage->logins]);
    }

    public function testInDryRunARetryWithALoginTypedNamesTheCareAccountStep(): void
    {
        $this->storage->saveSite((new CareSite(68, 1176, 540, self::SITE, State::Failed, 100))->failedWithRetry('The site could not be reached.', '2026-11-09 00:00:00'));
        $this->storage->saveLogin(68, new Login('owner', 'Secret1'));

        $outcome = $this->engine(true)->retryConnect(68, 'cron:daily');

        self::assertStringEndsWith(', then make the care account.', $outcome->message);
        self::assertSame([[], []], [$this->site->calls, $this->dashboard->calls]);
    }

    public function testInDryRunACareLoginTypedIsSaidToGoToTheDashboardFirst(): void
    {
        $outcome = $this->engine(true)->connect(68, new Login(self::CARE, 'HostMade1'), 'admin:1');

        self::assertSame('Dry run: would connect ' . self::SITE . ' to the dashboard as ' . self::CARE . ', logging in to the site to install MainWP\'s plugin only if the dashboard refuses.', $outcome->message);

        $this->storage->saveSite((new CareSite(68, 1176, 540, self::SITE, State::Failed, 100))->failedWithRetry('The dashboard could not be reached.', '2026-11-09 00:00:00'));
        $this->storage->saveLogin(68, new Login(self::CARE, 'HostMade1'));
        $retry = $this->engine(true)->retryConnect(68, 'cron:daily');

        self::assertSame('Dry run: would retry connecting ' . self::SITE . ' to the dashboard as ' . self::CARE . ' with the stored login, logging in to the site to install MainWP\'s plugin only if the dashboard refuses.', $retry->message);
        self::assertSame([[], []], [$this->site->calls, $this->dashboard->calls]);
    }

    public function testACareLoginForASiteTheDashboardStillHoldsIsUsedToSwitchThePluginOnWhenTheDashboardCannotMendIt(): void
    {
        // A Connected site whose mend failed: Failed, its dashboard site kept, MainWP Child switched off.
        $this->storage->saveSite((new CareSite(68, 1176, 540, self::SITE, State::Failed, 100, 101))->failed('Your site is no longer connected.'));
        $this->dashboard->status[101] = 'disconnected';
        $this->dashboard->mendedBy[101] = ['plugin', 'sync'];
        $dashboard = $this->dashboard;
        $this->site->during = static function () use ($dashboard): void {
            $dashboard->pluginOn = true;
        };

        $outcome = $this->engine()->connect(68, new Login(self::CARE, 'HostMade1'), 'admin:1');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['installPlugin ' . self::SITE . ' ' . self::CARE], $this->site->calls, 'the dashboard refused, so the care login switches the plugin on');
        self::assertSame(['setSiteAdmin 101 ' . self::CARE, 'syncSite 101', 'reconnectSite 101', 'setSiteAdmin 101 ' . self::CARE, 'syncSite 101', 'administrators 101'], $this->dashboard->calls);
        self::assertSame([State::Connected, 101], [$this->storage->site(68)?->state, $this->storage->site(68)?->dashboardSiteId]);
        self::assertSame(self::CARE, $this->storage->logins[68]->username);
    }

    public function testAPointBackTheDashboardRefusesStillLeavesTheSiteConnectedAndTheLoginTypedDeleted(): void
    {
        $this->dashboard->canConnectAs[101] = ['owner'];
        $this->dashboard->neverConnectsAs = [self::CARE];
        $this->dashboard->adminRefuses['owner'] = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertSame([], $this->storage->logins);
        self::assertStringEndsWith('as owner; the care account could not be made: the dashboard could not reach the site as ' . self::CARE, $this->lastLog());
    }

    public function testThePasswordOfTheFirstTryIsCleanedFromAFaultThatRepeatsIt(): void
    {
        $this->dashboard->createRefuses = [new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached: ' . FixedSecrets::CARE_PASSWORD)];

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertStringContainsString('the care account could not be made: The dashboard could not be reached: (hidden)', $this->lastLog());
        self::assertStringNotContainsString(FixedSecrets::CARE_PASSWORD, serialize($this->storage->log) . $outcome->message . $outcome->note);
    }

    public function testASuspensionThatFindsAFailedSiteOnTheDashboardDeletesTheLoginTyped(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');
        self::assertSame('Secret1', $this->storage->logins[68]->password);
        $this->dashboard->sitesList = [new DashboardSite(101, self::SITE . '/', 100, 'connected', '2026-11-09T00:00:05', '')];

        $outcome = $this->engine()->suspend(68, 'hook:AddonSuspended');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(State::Suspended, $this->storage->site(68)?->state);
        self::assertSame([], $this->storage->logins, 'the client\'s own password is not kept once the site is connected');
    }

    public function testAnAddWhoseAnswerNeverComesIsFoundOnTheDashboard(): void
    {
        // Live, 11 November 2026: on a dashboard with a dozen sites MainWP's sites/add went on for over the
        // module's 30 seconds. The dashboard had connected the site within ten; the module wrote Failed.
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->dashboard->sitesList = [new DashboardSite(107, self::SITE . '/', 100, 'connected', '2026-11-11T19:11:45', '')];
        $this->dashboard->admins[107] = ['owner', self::CARE];

        $outcome = $this->engine()->connect(68, new Login(self::CARE, 'HostMade1'), 'admin:1');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame([], $this->site->calls, 'the site is not logged in to');
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertSame(107, $this->storage->site(68)?->dashboardSiteId);
        self::assertSame(['addSite 100 ' . self::SITE . ' ' . self::CARE, 'sites', 'administrators 107'], $this->dashboard->calls);
        self::assertSame(['owner'], $this->storage->site(68)?->otherAdmins);
        self::assertSame(self::CARE, $this->storage->logins[68]->username);
        self::assertSame(self::SITE . ' connected as dashboard site 107 with the care account ' . self::CARE . ' (the dashboard\'s answer did not come in time; it holds the site)', $this->lastLog());
    }

    public function testAnAddWhoseAnswerNeverComesStaysFailedWhenTheDashboardDoesNotHoldTheSiteConnected(): void
    {
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached to connect the site.');
        $this->dashboard->sitesList = [
            new DashboardSite(107, self::SITE, 100, 'disconnected', null, 'MainWP Child plugin not detected'),
            new DashboardSite(108, self::SITE, 999, 'connected', '2026-11-11T19:11:45', ''),
            new DashboardSite(109, 'https://another.example', 100, 'connected', '2026-11-11T19:11:45', ''),
        ];

        $outcome = $this->engine()->connect(68, new Login(self::CARE, 'HostMade1'), 'admin:1');

        self::assertFalse($outcome->ok);
        self::assertSame([], $this->site->calls);
        self::assertSame(State::Failed, $this->storage->site(68)?->state);
        self::assertNull($this->storage->site(68)?->dashboardSiteId);
        self::assertNotNull($this->storage->site(68)?->retryAfter, 'on the retry list, as before');
        self::assertStringContainsString('could not be reached to connect the site', $this->lastLog());
    }

    public function testAnAddWhoseAnswerNeverComesKeepsItsOwnReasonWhenTheDashboardCannotBeAskedEither(): void
    {
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached to connect the site.');
        $this->dashboard->sitesRefuse = new DashboardFailure(DashboardFailure::BAD_ANSWER, 'The dashboard gave an answer that could not be read.');

        $outcome = $this->engine()->connect(68, new Login(self::CARE, 'HostMade1'), 'admin:1');

        self::assertFalse($outcome->ok);
        self::assertSame(State::Failed, $this->storage->site(68)?->state);
        self::assertStringContainsString('could not be reached to connect the site', $this->lastLog());
        self::assertStringNotContainsString('could not be read', $this->lastLog());
    }

    public function testADashboardThatRefusesAnAddIsNotAskedWhetherItHoldsTheSite(): void
    {
        // Only an answer that never came is looked for; a refusal is an answer.
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::KEY_REFUSED, 'The dashboard refused the API key.');
        $this->dashboard->sitesList = [new DashboardSite(107, self::SITE, 100, 'connected', '2026-11-11T19:11:45', '')];

        $outcome = $this->engine()->connect(68, new Login(self::CARE, 'HostMade1'), 'admin:1');

        self::assertFalse($outcome->ok);
        self::assertNotContains('sites', $this->dashboard->calls);
        self::assertSame(State::Failed, $this->storage->site(68)?->state);
    }

    public function testAnAddAsTheLoginTypedWhoseAnswerNeverComesStillGetsItsCareAccount(): void
    {
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->dashboard->sitesList = [new DashboardSite(107, self::SITE, 100, 'connected', '2026-11-11T19:11:45', '')];
        $this->dashboard->admins[107] = ['owner'];

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(107, $this->storage->site(68)?->dashboardSiteId);
        self::assertContains('createAdministrator 107 ' . self::CARE . ' ' . self::EMAIL . ' WordPress Care', $this->dashboard->calls);
        self::assertSame(self::CARE, $this->storage->logins[68]->username);
    }
}
