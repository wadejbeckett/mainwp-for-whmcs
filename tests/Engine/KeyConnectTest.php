<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\ApprovalReturn;
use MainWpForWhmcs\Engine\CareEngine;
use MainWpForWhmcs\Engine\CareSite;
use MainWpForWhmcs\Engine\Client;
use MainWpForWhmcs\Engine\DashboardFailure;
use MainWpForWhmcs\Engine\DashboardSite;
use MainWpForWhmcs\Engine\HeldKey;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\SiteFailure;
use MainWpForWhmcs\Engine\State;

require_once __DIR__ . '/Fakes.php';

/**
 * The key route from the moment the client's browser comes back from their own site: the key used at once and deleted on the site, the care account, the
 * retry list, and every way a return can be refused without leaving a key behind.
 */
final class KeyConnectTest extends TestCase
{
    private const SITE = 'https://clientsite.example';
    private const SUCCESS = 'https://billing.example.com/index.php?m=mainwp&care_return={token}';
    private const REJECT = 'https://billing.example.com/index.php?m=mainwp&care_return={token}&care_rejected=1';
    private const KEY = 'BenchKey0123456789ABCDEF';
    private const OTHER_KEY = 'OtherKey0123456789ABCDEF';
    private const EMAIL = 'wordpress@care.example.com';

    private RecordingDashboard $dashboard;
    private RecordingSite $site;
    private MemoryStorage $storage;
    private FixedSecrets $secrets;
    private FixedClock $clock;

    protected function setUp(): void
    {
        $this->dashboard = new RecordingDashboard();
        $this->site = new RecordingSite();
        $this->storage = new MemoryStorage();
        $this->secrets = new FixedSecrets();
        $this->clock = new FixedClock();
        $this->engine()->provision(68, 1176, new Client(540, 'Demo Client', 'demo@example.com'), self::SITE, 'hook:AddonActivated');
        $this->dashboard->calls = [];
        $this->storage->log = [];
    }

    private function engine(bool $dryRun = false): CareEngine
    {
        return new CareEngine($this->dashboard, $this->site, $this->storage, $this->clock, $dryRun, $this->secrets, self::EMAIL);
    }

    /** The client presses the button; returns the token in the return address. Calls so far are forgotten. */
    private function start(int $addonRowId = 68): string
    {
        $start = $this->engine()->startApproval($addonRowId, self::SUCCESS, self::REJECT, 'client:540');
        self::assertTrue($start->ok, $start->message);
        $this->site->calls = [];
        $this->dashboard->calls = [];
        return $this->secrets->tokens[array_key_last($this->secrets->tokens)];
    }

    private function back(string $token, string $key = self::KEY, string $siteUrl = self::SITE, string $user = 'owner', ?string $refuse = null): ApprovalReturn
    {
        return $this->engine()->completeApproval($token, $siteUrl, $user, $key, 'client:540', $refuse);
    }

    private function care(): Login
    {
        return new Login(FixedSecrets::CARE_LOGIN, FixedSecrets::CARE_PASSWORD);
    }

    private function logText(): string
    {
        return json_encode(array_map(static fn ($e): array => (array) $e, $this->storage->log), JSON_THROW_ON_ERROR);
    }

    private function assertNoSecretIsLogged(): void
    {
        foreach ([self::KEY, self::OTHER_KEY, FixedSecrets::CARE_PASSWORD, ...$this->secrets->tokens] as $secret) {
            self::assertStringNotContainsString($secret, $this->logText());
        }
    }

    public function testTheKeyThatComesBackConnectsTheSiteAsTheCareAccountAndIsDeletedOnTheSite(): void
    {
        $token = $this->start();

        $return = $this->back($token);

        self::assertSame([ApprovalReturn::CONNECTED, 68, false], [$return->result, $return->addonRowId, $return->keyLeft], $return->message);
        self::assertSame(['installPluginWithKey ' . self::SITE . ' owner wpcarek7m2x9ab ' . self::EMAIL, 'deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'the key is used at once and deleted as the last step');
        self::assertSame(self::KEY, $this->site->keyed[0]['key']->password);
        self::assertSame(['addSite 100 ' . self::SITE . ' wpcarek7m2x9ab', 'administrators 101'], $this->dashboard->calls, 'the dashboard connects as the care account');
        self::assertSame(FixedSecrets::CARE_PASSWORD, $this->dashboard->added[0]->password);
        self::assertEquals($this->care(), $this->storage->logins[68], 'the care account\'s login is the stored login');
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, '2026-11-09 14:00:00', null, null], [$site?->state, $site?->lastSync, $site?->lastError, $site?->retryAfter]);
        self::assertNull($this->storage->heldKey(68), 'nothing of the key is kept');
        self::assertTrue($this->storage->approval(hash('sha256', $token))?->used);
        $entry = end($this->storage->log);
        self::assertSame(['key', 'done'], [$entry->action, $entry->outcome]);
        $this->assertNoSecretIsLogged();
    }

    public function testTheSitesOtherAdministratorsAreRecordedOnceTheKeyConnectedIt(): void
    {
        $this->dashboard->admins[101] = ['owner', FixedSecrets::CARE_LOGIN, 'editor-in-chief'];

        $this->back($this->start());

        self::assertSame(['addSite 100 ' . self::SITE . ' ' . FixedSecrets::CARE_LOGIN, 'administrators 101'], $this->dashboard->calls);
        self::assertSame(['owner', 'editor-in-chief'], $this->storage->site(68)?->otherAdmins);
    }

    public function testWhenTheListingFailsAfterAKeyConnectAtLeastTheApprovingAccountIsRecorded(): void
    {
        $this->dashboard->adminsRefuse = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');

        $return = $this->back($this->start());

        self::assertSame(ApprovalReturn::CONNECTED, $return->result, 'a failure of the listing never fails the connect');
        self::assertSame(['owner'], $this->storage->site(68)?->otherAdmins);
    }

    public function testAKeyThatArrivesInGroupsWithSpacesIsUsedWithoutThem(): void
    {
        $return = $this->back($this->start(), 'Benc hKey 0123 4567 89AB CDEF');

        self::assertSame(ApprovalReturn::CONNECTED, $return->result, $return->message);
        self::assertSame(self::KEY, $this->site->keyed[0]['key']->password);
    }

    public function testEveryCareAccountGetsTheCareAccountEmail(): void
    {
        $this->engine()->provision(69, 1177, new Client(540, 'Demo Client', 'demo@example.com'), 'https://shop.clientsite.example', 'hook:AddonActivated');
        $this->back($this->start(68));
        $token = $this->start(69);
        $this->engine()->completeApproval($token, 'https://shop.clientsite.example', 'owner', self::KEY, 'client:540');

        self::assertSame([self::EMAIL, self::EMAIL], array_column($this->site->keyed, 'email'), 'one address, given to the care account on every site');
    }

    public function testAnUnknownTokenChangesNothing(): void
    {
        $this->start();

        $return = $this->back(str_repeat('f', 64));

        self::assertSame(ApprovalReturn::UNKNOWN, $return->result);
        self::assertNull($return->addonRowId);
        self::assertSame([[], []], [$this->site->calls, $this->dashboard->calls]);
        self::assertSame(State::Connecting, $this->storage->site(68)?->state);
    }

    public function testWhatComesBackMustBeAKeyAndALoginName(): void
    {
        $token = $this->start();

        self::assertSame(ApprovalReturn::INVALID, $this->back($token, 'tooshort')->result);
        self::assertSame(ApprovalReturn::INVALID, $this->back($token, self::KEY . 'x')->result);
        self::assertSame(ApprovalReturn::INVALID, $this->back($token, self::KEY, self::SITE, '')->result);
        self::assertSame(ApprovalReturn::INVALID, $this->back($token, self::KEY, self::SITE, str_repeat('a', 61))->result);
        self::assertSame([], $this->site->calls, 'nothing is sent for it');
        self::assertFalse($this->storage->approval(hash('sha256', $token))?->used, 'the link still waits for the real key');

        self::assertSame(ApprovalReturn::CONNECTED, $this->back($token)->result);
    }

    public function testAKeyApprovedOnAnotherSiteIsNeverSentAnywhere(): void
    {
        $token = $this->start();

        $return = $this->back($token, self::KEY, 'https://victim.example');

        self::assertSame([ApprovalReturn::MISMATCH, false], [$return->result, $return->keyLeft], 'the key is on the other site, not on this one');
        self::assertSame([[], []], [$this->site->calls, $this->dashboard->calls], 'a key made on another site must not reach the address this add-on stores');
        self::assertSame(State::Connecting, $this->storage->site(68)?->state);
        self::assertFalse($this->storage->approval(hash('sha256', $token))?->used);
        $entry = end($this->storage->log);
        self::assertSame(['approve', 'refused'], [$entry->action, $entry->outcome]);
        self::assertStringContainsString('victim.example', $entry->detail);
        $this->assertNoSecretIsLogged();

        self::assertSame(ApprovalReturn::MISMATCH, $this->back($token, self::KEY, 'http://clientsite.example')->result, 'plain http is not the site either');
        self::assertSame(ApprovalReturn::CONNECTED, $this->back($token, self::KEY, 'https://www.clientsite.example/wp')->result, 'the www form and WordPress in a folder are the same site');
    }

    public function testTheSameReturnAgainOnlyShowsHowItEnded(): void
    {
        $token = $this->start();
        $this->back($token);
        $this->site->calls = [];
        $this->dashboard->calls = [];

        $again = $this->back($token);

        self::assertSame([ApprovalReturn::USED, 68, false], [$again->result, $again->addonRowId, $again->keyLeft]);
        self::assertSame([[], []], [$this->site->calls, $this->dashboard->calls], 'a reload sends nothing');
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
    }

    public function testAnotherKeyOnALinkAlreadyUsedIsDeletedOnTheSiteAndRefused(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::INSTALL_FORBIDDEN, 'The site does not allow plugins to be installed.');
        $this->back($token);
        $this->site->calls = [];

        $return = $this->back($token, self::OTHER_KEY);

        self::assertSame([ApprovalReturn::USED, false], [$return->result, $return->keyLeft]);
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'a second key made by approving twice is not left on the site');
        self::assertSame(self::KEY, $this->storage->heldKey(68)?->key->password, 'the key held for the retry is untouched');
    }

    public function testALinkThatLapsedDeletesTheKeyThatCameBackAndTheSiteIsFailedWithTheReason(): void
    {
        $token = $this->start();
        $this->clock->forward('24 hours');

        $return = $this->back($token);

        self::assertSame([ApprovalReturn::LAPSED, 68, false], [$return->result, $return->addonRowId, $return->keyLeft]);
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame([], $this->dashboard->calls);
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null], [$site?->state, $site?->retryAfter], 'nothing to retry with: the client is prompted again');
        self::assertStringContainsString('within 24 hours', (string) $site?->lastError);
    }

    public function testALinkReplacedByANewerOneIsLapsedButTheNewerOneStillWorks(): void
    {
        $first = $this->start();
        $second = $this->start();

        $return = $this->back($first);

        self::assertSame(ApprovalReturn::LAPSED, $return->result);
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame(State::Connecting, $this->storage->site(68)?->state, 'the newer approval is still awaited');
        self::assertSame(ApprovalReturn::CONNECTED, $this->back($second, self::OTHER_KEY)->result);
    }

    public function testAKeyThatCouldNotBeDeletedOnARefusedReturnIsLeftForTheClientToDelete(): void
    {
        $token = $this->start();
        $this->clock->forward('25 hours');
        $this->site->keyStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');

        $return = $this->back($token);

        self::assertSame([ApprovalReturn::LAPSED, true], [$return->result, $return->keyLeft]);
        $this->assertNoSecretIsLogged();
    }

    public function testAReturnForASiteThatIsNotWaitingOrNotBilledDeletesTheKeyAndIsRefused(): void
    {
        $token = $this->start();
        $return = $this->back($token, self::KEY, self::SITE, 'owner', 'WordPress Care is not active for this site.');

        self::assertSame([ApprovalReturn::REFUSED, 'WordPress Care is not active for this site.'], [$return->result, $return->message]);
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame([], $this->dashboard->calls);
        self::assertFalse($this->storage->approval(hash('sha256', $token))?->used);

        $this->site->calls = [];
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Suspended, 100, 7));
        self::assertSame(ApprovalReturn::REFUSED, $this->back($token)->result);
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls);
    }

    public function testARefusedInstallEndsInFailedWithARetryEntryAndTheKeyKeptForIt(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::INSTALL_FORBIDDEN, 'The site does not allow plugins to be installed from its dashboard.');

        $return = $this->back($token);

        self::assertSame([ApprovalReturn::FAILED, 68], [$return->result, $return->addonRowId]);
        self::assertSame([], $this->dashboard->calls);
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, 'The site does not allow plugins to be installed from its dashboard.', '2026-11-10 14:00:00', 0], [$site?->state, $site?->lastError, $site?->retryAfter, $site?->retryCount]);
        self::assertEquals(new HeldKey(new Login('owner', self::KEY), $this->care()), $this->storage->heldKey(68), 'the retry runs with the approved key');
        self::assertArrayNotHasKey(68, $this->storage->logins);
        self::assertNotContains('deleteKey ' . self::SITE . ' owner', $this->site->calls, 'the key is deleted when the site is connected or the retries end');
        $this->assertNoSecretIsLogged();
    }

    public function testADashboardThatRefusesKeepsTheCareAccountAndTheKeyForTheRetry(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to connect ' . self::SITE . ': Unable to connect to the site.');

        $return = $this->back($token);

        self::assertSame(ApprovalReturn::FAILED, $return->result);
        self::assertSame(['installPluginWithKey ' . self::SITE . ' owner wpcarek7m2x9ab ' . self::EMAIL], $this->site->calls, 'one care account, kept for the retry, not made and removed every day');
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, '2026-11-10 14:00:00'], [$site?->state, $site?->retryAfter]);
        self::assertStringContainsString('Unable to connect to the site', (string) $site?->lastError);
        self::assertNotNull($this->storage->heldKey(68));
    }

    public function testAnApproverWhoIsNotAnAdministratorHasTheKeyDeletedAtOnceAndNoRetry(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::NOT_ADMIN, 'The account editor1 is not an administrator of the site. Use an administrator account.');

        $return = $this->back($token, self::KEY, self::SITE, 'editor1');

        self::assertSame([ApprovalReturn::FAILED, false], [$return->result, $return->keyLeft]);
        self::assertSame(['installPluginWithKey ' . self::SITE . ' editor1 wpcarek7m2x9ab ' . self::EMAIL, 'deleteKey ' . self::SITE . ' editor1'], $this->site->calls);
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null], [$site?->state, $site?->retryAfter], 'a retry with that key could never work');
        self::assertStringContainsString('not an administrator', (string) $site?->lastError);
        self::assertStringContainsString('Approve the connection again', (string) $site?->lastError);
        self::assertNull($this->storage->heldKey(68));
    }

    public function testAKeyThatCannotBeDeletedAfterConnectingStaysHeldUntilTheTidyDeletesIt(): void
    {
        $token = $this->start();
        $this->site->keyStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');

        $return = $this->back($token);

        self::assertSame(ApprovalReturn::CONNECTED, $return->result, 'the site is connected all the same');
        self::assertNotNull($this->storage->heldKey(68));
        $entry = end($this->storage->log);
        self::assertSame(['key', 'failed'], [$entry->action, $entry->outcome]);

        $this->site->calls = [];
        self::assertSame(0, $this->engine()->tidyKeys('cron:daily'), 'still not deleted');
        self::assertNotNull($this->storage->heldKey(68));

        $this->site->keyStays = null;
        self::assertSame(1, $this->engine()->tidyKeys('cron:daily'));
        self::assertNull($this->storage->heldKey(68));
        self::assertSame(['deleteKey ' . self::SITE . ' owner', 'deleteKey ' . self::SITE . ' owner'], $this->site->calls);
    }

    public function testTheTidyGivesUpAfterAWeekAndSaysSo(): void
    {
        $token = $this->start();
        $this->site->keyStays = new SiteFailure(SiteFailure::KEY_REFUSED, 'The site did not accept the key.');
        $this->back($token);
        $this->clock->forward('9 days');

        $this->engine()->tidyKeys('cron:daily');

        self::assertNull($this->storage->heldKey(68), 'the module does not keep a key for ever');
        $entry = end($this->storage->log);
        self::assertSame(['key', 'given up'], [$entry->action, $entry->outcome]);
        self::assertStringContainsString('delete', $entry->detail);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
    }

    public function testARetryUsesTheHeldKeyAndTheSameCareAccount(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->back($token);
        $this->site->refuses = null;
        $this->site->calls = [];
        $this->clock->forward('1 day');

        $outcome = $this->engine()->retryConnect(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['installPluginWithKey ' . self::SITE . ' owner wpcarek7m2x9ab ' . self::EMAIL, 'deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame(['sites', 'addSite 100 ' . self::SITE . ' wpcarek7m2x9ab', 'administrators 101'], $this->dashboard->calls, 'a retry first asks whether the dashboard already holds the site');
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertEquals($this->care(), $this->storage->logins[68]);
        self::assertNull($this->storage->heldKey(68));
    }

    public function testAFailedRetryWithTheKeyCountsUpAndStaysOnTheRetryList(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'The site did not accept the key that was approved.');
        $this->back($token);
        $this->clock->forward('1 day');
        // No care account was ever made, so the dashboard cannot connect as it either.
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to connect the site: verify your Admin Username and Password.');

        $outcome = $this->engine()->retryConnect(68, 'cron:daily');

        self::assertFalse($outcome->ok);
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, 1, '2026-11-11 14:00:00'], [$site?->state, $site?->retryCount, $site?->retryAfter]);
        self::assertSame('The site did not accept the key that was approved.', $site?->lastError, 'the reason stays the site\'s: that is what the client can act on');
        self::assertNotNull($this->storage->heldKey(68));
    }

    public function testARetryAdoptsASiteTheDashboardConnectedWhenItsAnswerWasLost(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached: Operation timed out.');
        $this->back($token);
        $this->dashboard->addRefuses = null;
        $this->dashboard->sitesList = [
            new DashboardSite(554, 'https://other.example/', 100, 'connected', null, ''),
            new DashboardSite(555, 'https://clientsite.example/', 100, 'connected', '2026-11-09T12:00:10', ''),
        ];
        $this->site->calls = [];
        $this->dashboard->calls = [];

        $outcome = $this->engine()->retryConnect(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['sites', 'administrators 555'], $this->dashboard->calls, 'no second add for a site that is already there');
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'the care account is left as the dashboard connected it; the key is deleted');
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, 555], [$site?->state, $site?->dashboardSiteId]);
        self::assertEquals($this->care(), $this->storage->logins[68]);
    }

    public function testWhenTheListingFailsAfterAnAdoptionWithAKeyAtLeastTheApprovingAccountIsRecorded(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached: Operation timed out.');
        $this->back($token);
        $this->dashboard->addRefuses = null;
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://clientsite.example/', 100, 'connected', '2026-11-09T12:00:10', '')];
        $this->dashboard->adminsRefuse = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');

        // Ending the retries finds the connection the dashboard finished and records it instead.
        $outcome = $this->engine()->endRetries(68, 'admin:1');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertEquals($this->care(), $this->storage->logins[68]);
        self::assertSame([State::Connected, 555, ['owner']], [$this->storage->site(68)?->state, $this->storage->site(68)?->dashboardSiteId, $this->storage->site(68)?->otherAdmins]);
    }

    public function testAPasswordRetryAdoptsASiteTheDashboardAlreadyHoldsToo(): void
    {
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached: Operation timed out.');
        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');
        $this->dashboard->addRefuses = null;
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://CLIENTSITE.example', 100, 'connected', null, ''), new DashboardSite(556, 'https://clientsite.example/', 101, 'connected', null, '')];
        $this->site->calls = [];
        $this->dashboard->calls = [];

        $outcome = $this->engine()->retryConnect(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame([['sites', 'createAdministrator 555 ' . FixedSecrets::CARE_LOGIN . ' ' . self::EMAIL . ' WordPress Care', 'setSiteAdmin 555 ' . FixedSecrets::CARE_LOGIN, 'syncSite 555', 'administrators 555'], []], [$this->dashboard->calls, $this->site->calls], 'nothing is added; the care account is made before the site is recorded connected');
        self::assertSame(555, $this->storage->site(68)?->dashboardSiteId, 'the one under this client');
    }

    /** @return iterable<string, array{string}> how an add whose answer never came fails: a real timeout since 0.5.0, a lost connection before */
    public static function lateAnswers(): iterable
    {
        yield 'timed out' => [DashboardFailure::TIMED_OUT];
        yield 'connection lost' => [DashboardFailure::UNREACHABLE];
    }

    /** @dataProvider lateAnswers */
    public function testAnApprovalWhoseAddIsNotAnsweredInTimeIsFoundOnTheDashboard(string $kind): void
    {
        // The key route's own first attempt (live, 11 November 2026: MainWP's sites/add outlasted the module's wait).
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure($kind, 'The dashboard did not answer in time: Operation timed out.');
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://clientsite.example/', 100, 'connected', '2026-11-11T19:11:45', '')];

        $return = $this->back($token);

        self::assertSame([ApprovalReturn::CONNECTED, 68, false], [$return->result, $return->addonRowId, $return->keyLeft], $return->message);
        self::assertSame(['addSite 100 ' . self::SITE . ' wpcarek7m2x9ab', 'sites', 'administrators 555'], $this->dashboard->calls);
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, 555], [$site?->state, $site?->dashboardSiteId]);
        self::assertEquals($this->care(), $this->storage->logins[68]);
        self::assertNull($this->storage->heldKey(68), 'the key is deleted as when the add answers');
        $connect = array_values(array_filter($this->storage->log, static fn ($e): bool => $e->action === 'connect'));
        self::assertSame(self::SITE . ' connected as dashboard site 555 with the care account wpcarek7m2x9ab (the dashboard\'s answer did not come in time; it holds the site)', end($connect)->detail);
        $this->assertNoSecretIsLogged();
    }

    public function testAKeyRouteAddTheSiteDidNotAnswerInTimeIsTheSitesFailureAndItsRetryIsCounted(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::TIMED_OUT, 'The dashboard at dash.example.com did not answer in time to connect the site: Operation timed out.');

        $this->back($token);

        $site = $this->storage->site(68);
        self::assertSame([State::Failed, 'Your site did not answer in time. It is tried again by itself.'], [$site?->state, $site?->lastError]);
        self::assertContains('counts', $this->dashboard->calls, 'the dashboard is asked whether it still answers');
        $before = (int) $site?->retryCount;

        $outcome = $this->engine()->retryConnect(68, 'cron:daily');

        self::assertSame([false, true, false], [$outcome->ok, $outcome->attempted, $outcome->dashboardFault], 'the dashboard answers: not a fault on our side, and the run goes on');
        self::assertSame($before + 1, $this->storage->site(68)?->retryCount, 'the retry is counted');
        self::assertSame('Your site did not answer in time. It is tried again by itself.', $this->storage->site(68)?->lastError);

        $this->dashboard->countsFail = new DashboardFailure(DashboardFailure::TIMED_OUT, 'The dashboard did not answer in time.');
        $outcome = $this->engine()->retryConnect(68, 'cron:daily');
        self::assertSame([true, $before + 1], [$outcome->dashboardFault, $this->storage->site(68)?->retryCount], 'a dashboard that does not answer either is a fault on our side, not counted');
    }

    public function testADashboardSiteAnotherCareSiteHoldsIsNotTakenOver(): void
    {
        // Two care add-ons of one client at one address: the first is connected as dashboard site 555. The
        // second must not come to hold the same dashboard site, or ending one would act on the other's.
        $this->engine()->provision(69, 1177, new Client(540, 'Demo Client', 'demo@example.com'), self::SITE, 'hook:AddonActivated');
        $first = $this->storage->site(68);
        self::assertNotNull($first);
        $this->storage->saveSite($first->connectedAs(555, '2026-11-11 21:11:45'));
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://clientsite.example/', 100, 'connected', '2026-11-11T19:11:45', '')];
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached: Operation timed out.');
        $this->dashboard->calls = [];

        $outcome = $this->engine()->connect(69, new Login(FixedSecrets::CARE_LOGIN, 'HostMade1'), 'admin:1');

        self::assertFalse($outcome->ok, 'the first add-on\'s dashboard site is not this add-on\'s');
        self::assertSame([State::Failed, null], [$this->storage->site(69)?->state, $this->storage->site(69)?->dashboardSiteId]);
        self::assertSame(555, $this->storage->site(68)?->dashboardSiteId);

        $this->dashboard->calls = [];
        $this->engine()->retryConnect(69, 'cron:daily');

        self::assertSame(['sites', 'addSite 100 ' . self::SITE . ' ' . FixedSecrets::CARE_LOGIN, 'sites'], $this->dashboard->calls, 'the retry does not take it over either: it asks the dashboard to add the site');
        self::assertNull($this->storage->site(69)?->dashboardSiteId);
    }

    public function testASiteTheDashboardHoldsButCannotReachIsNotAdopted(): void
    {
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://clientsite.example/', 100, 'disconnected', null, 'ERROR')];
        $this->dashboard->calls = [];

        $this->engine()->retryConnect(68, 'cron:daily');

        // The add's answer did not come, so the dashboard is asked once more whether it holds the site by now:
        // it still holds it disconnected only, which is not taken over.
        self::assertSame(['sites', 'addSite 100 ' . self::SITE . ' owner', 'sites'], $this->dashboard->calls);
        self::assertSame(State::Failed, $this->storage->site(68)?->state);
    }

    public function testARetryWhoseKeyIsNoLongerAcceptedStillConnectsWithTheCareAccountsLogin(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to connect the site.');
        $this->back($token);
        $this->dashboard->addRefuses = null;
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');
        $this->site->keyStays = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');
        $this->dashboard->calls = [];

        $outcome = $this->engine()->retryConnect(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['sites', 'addSite 100 ' . self::SITE . ' wpcarek7m2x9ab', 'administrators 101'], $this->dashboard->calls, 'the care account from the attempt before is still there: the dashboard connects as it (SPEC, the retry list)');
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertEquals($this->care(), $this->storage->logins[68]);
        self::assertNotNull($this->storage->heldKey(68), 'the key could not be deleted now; the tidy tries again');
    }

    public function testAFirstAttemptWhoseKeyIsNotAcceptedDoesNotAskTheDashboard(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');

        $this->back($token);

        self::assertSame([], $this->dashboard->calls, 'no care account exists yet for the dashboard to connect as');
        self::assertSame(State::Failed, $this->storage->site(68)?->state);
    }

    public function testWhatOnlyStaffNeedToKnowGoesToTheLogAndNotToTheClient(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.', null, 'HTTP 401 from WordPress itself');

        $this->back($token);

        self::assertSame('Your site did not accept the connection you approved.', $this->storage->site(68)?->lastError);
        $entry = end($this->storage->log);
        self::assertSame(['connect', 'failed'], [$entry->action, $entry->outcome]);
        self::assertStringContainsString('[HTTP 401 from WordPress itself]', $entry->detail);
    }

    public function testAFailedSiteOnTheRetryListWithNothingLeftToRetryWithLeavesTheList(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');
        $this->storage->deleteLogin(68); // as when the stored login can no longer be decrypted
        $this->site->calls = [];

        $outcome = $this->engine()->retryConnect(68, 'cron:daily');

        self::assertFalse($outcome->ok);
        self::assertSame('Add-on 68 has no stored login to retry with.', $outcome->message);
        self::assertSame([], $this->site->calls);
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null, 0], [$site?->state, $site?->retryAfter, $site?->retryCount], 'not retried for ever: the client is prompted again');
        self::assertStringContainsString('connect the site again', (string) $site?->lastError);
    }

    public function testDecliningOnTheSiteEndsInFailedWithTheReasonAndNothingToRetry(): void
    {
        $token = $this->start();

        $return = $this->engine()->rejectApproval($token, 'client:540');

        self::assertSame([ApprovalReturn::REJECTED, 68], [$return->result, $return->addonRowId]);
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null], [$site?->state, $site?->retryAfter]);
        self::assertStringContainsString('was not approved on your site', (string) $site?->lastError);
        self::assertTrue($this->storage->approval(hash('sha256', $token))?->lapsed('2026-11-09 14:00:00'), 'the link cannot be used afterwards');
        self::assertSame([], $this->site->calls);
    }

    public function testASiteThatWouldNotLetTheAccountApproveSaysWhatStopsIt(): void
    {
        $return = $this->engine()->rejectApproval($this->start(), 'client:540', true);

        self::assertSame(ApprovalReturn::REJECTED, $return->result);
        $error = (string) $this->storage->site(68)?->lastError;
        self::assertStringContainsString('application passwords are switched off for the account', $error);
        self::assertStringContainsString('HTTP login', $error);
        self::assertStringContainsString('username and password', $error);
    }

    public function testADeclineOnALinkAlreadyUsedOrLapsedChangesNothing(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->back($token);
        $before = $this->storage->site(68);

        $return = $this->engine()->rejectApproval($token, 'client:540');

        self::assertSame(ApprovalReturn::USED, $return->result);
        self::assertEquals($before, $this->storage->site(68), 'still Failed with its retry entry');
        self::assertNotNull($this->storage->heldKey(68));
        self::assertSame(ApprovalReturn::UNKNOWN, $this->engine()->rejectApproval(str_repeat('e', 64), 'client:540')->result);
    }

    public function testAnApprovalNeverGivenExpiresIntoFailedWithTheReason(): void
    {
        $this->start();
        self::assertFalse($this->engine()->expireApproval(68, 'cron:daily'), 'not yet');
        self::assertSame(State::Connecting, $this->storage->site(68)?->state);

        $this->clock->forward('24 hours');
        self::assertTrue($this->engine()->expireApproval(68, 'cron:daily'));

        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null], [$site?->state, $site?->retryAfter]);
        self::assertStringContainsString('within 24 hours', (string) $site?->lastError);
        self::assertStringContainsString('sign in to your site first', (string) $site?->lastError, 'a moved login page cannot be seen in advance; the reason says what to do');
        self::assertFalse($this->engine()->expireApproval(68, 'cron:daily'), 'once');
    }

    public function testAnApprovalNeverGivenLeavesAnEarlierPasswordAttemptOnTheRetryList(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');
        $this->engine()->retryConnect(68, 'cron:daily');
        $this->site->refuses = null;
        $this->start();
        $this->clock->forward('25 hours');

        $this->engine()->expireApproval(68, 'cron:daily');

        $site = $this->storage->site(68);
        self::assertSame([State::Failed, 0, '2026-11-11 15:00:00'], [$site?->state, $site?->retryCount, $site?->retryAfter], 'the stored login is still tried, and the person\'s new attempt started its count again');
        self::assertSame('Secret1', $this->storage->logins[68]->password);
    }

    public function testAConnectionCutOffHalfwayGoesOnTheRetryListWithItsKey(): void
    {
        $token = $this->start();
        $this->storage->claimApproval(hash('sha256', $token), new HeldKey(new Login('owner', self::KEY), $this->care()), '2026-11-09 14:00:00');

        $this->storage->locked = [68];
        self::assertFalse($this->engine()->expireApproval(68, 'client:540'), 'another request is still working on it');
        $this->storage->locked = [];

        self::assertTrue($this->engine()->expireApproval(68, 'client:540'));
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, '2026-11-10 14:00:00'], [$site?->state, $site?->retryAfter]);
        self::assertStringContainsString('interrupted', (string) $site?->lastError);
        self::assertNotNull($this->storage->heldKey(68));
    }

    public function testExpiringEverythingCoversEverySiteAndPurgesLinksAWeekOld(): void
    {
        $this->engine()->provision(69, 1177, new Client(540, 'Demo Client', 'demo@example.com'), 'https://shop.clientsite.example', 'hook:AddonActivated');
        $this->start(68);
        $this->clock->forward('2 days');
        $this->start(69);
        $this->clock->forward('7 days');

        self::assertSame(2, $this->engine()->expireApprovals('cron:daily'));

        self::assertSame([State::Failed, State::Failed], [$this->storage->site(68)?->state, $this->storage->site(69)?->state]);
        self::assertCount(1, $this->storage->approvals, 'the link that lapsed more than a week ago is forgotten');
    }

    public function testWhenTheRetriesEndTheCareAccountAndTheKeyAreRemovedOnTheSiteAndNothingIsKept(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to connect the site.');
        $this->back($token);
        $this->site->calls = [];

        $outcome = $this->engine()->endRetries(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['removeAccount ' . self::SITE . ' owner wpcarek7m2x9ab', 'deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'the account first, while the key still works');
        self::assertNull($this->storage->heldKey(68));
        self::assertArrayNotHasKey(68, $this->storage->logins);
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null], [$site?->state, $site?->retryAfter], 'Failed for good: the client is prompted again');
        self::assertStringContainsString('The dashboard refused to connect the site', (string) $site?->lastError);
        self::assertStringNotContainsString('delete', (string) $site?->lastError);
    }

    public function testWhatCouldNotBeRemovedWhenTheRetriesEndIsNamedForTheClient(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'The site did not accept the key that was approved.');
        $this->back($token);
        $this->site->accountStays = new SiteFailure(SiteFailure::KEY_REFUSED, 'The site did not accept the key that was approved.');
        $this->site->keyStays = new SiteFailure(SiteFailure::KEY_REFUSED, 'The site did not accept the key that was approved.');

        $this->engine()->endRetries(68, 'cron:daily');

        $error = (string) $this->storage->site(68)?->lastError;
        self::assertStringContainsString('application password', $error);
        self::assertStringContainsString('wpcarek7m2x9ab', $error);
        self::assertNull($this->storage->heldKey(68), 'the module keeps no key it can do nothing with');
    }

    public function testWhenThePasswordRoutesRetriesEndTheStoredLoginIsDeleted(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::CAPTCHA, 'The login page has a captcha.');
        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');
        $this->site->calls = [];

        $outcome = $this->engine()->endRetries(68, 'cron:daily');

        self::assertTrue($outcome->ok);
        self::assertSame([], $this->site->calls);
        self::assertArrayNotHasKey(68, $this->storage->logins);
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null, 'The login page has a captcha.'], [$site?->state, $site?->retryAfter, $site?->lastError]);
        self::assertFalse($this->engine()->endRetries(99, 'cron:daily')->ok);
    }

    public function testThePasswordRouteTakesOverFromAnApprovalNotYetGiven(): void
    {
        $token = $this->start();

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'admin:1');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['installPlugin ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        $this->site->calls = [];
        self::assertSame(ApprovalReturn::LAPSED, $this->back($token)->result, 'a key approved afterwards is deleted, not used');
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls);
    }

    public function testThePasswordRouteAfterAFailedKeyAttemptRemovesThatAttemptsAccountAndKeyFirst(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to connect the site.');
        $this->back($token);
        $this->dashboard->addRefuses = null;
        $this->site->calls = [];

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['removeAccount ' . self::SITE . ' owner wpcarek7m2x9ab', 'deleteKey ' . self::SITE . ' owner', 'installPlugin ' . self::SITE . ' owner'], $this->site->calls);
        self::assertNull($this->storage->heldKey(68));
        self::assertSame(FixedSecrets::CARE_LOGIN, $this->storage->logins[68]->username, 'a new care account of this connect, not the one the key attempt made');
    }

    public function testANewApprovalAfterAFailedKeyAttemptRemovesThatAttemptsAccountAndKeyFirst(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to connect the site.');
        $this->back($token);
        $this->site->calls = [];

        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540');

        self::assertTrue($start->ok, $start->message);
        self::assertSame(['index ' . self::SITE, 'removeAccount ' . self::SITE . ' owner wpcarek7m2x9ab', 'deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'no second care account beside one nobody holds the password of');
        self::assertNull($this->storage->heldKey(68));
        self::assertSame(State::Connecting, $this->storage->site(68)?->state);
    }

    public function testRemovingASiteThatWasNeverConnectedCleansUpItsKeyAndCareAccount(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to connect the site.');
        $this->back($token);
        $this->site->calls = [];
        $this->dashboard->calls = [];

        $outcome = $this->engine()->remove(68, 'whmcs:AddonCancelled');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['removeAccount ' . self::SITE . ' owner wpcarek7m2x9ab', 'deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame(['sites'], $this->dashboard->calls, 'the dashboard is asked whether it holds the site after all; it does not');
        self::assertSame(State::Removed, $this->storage->site(68)?->state);
        self::assertNull($this->storage->heldKey(68));
    }

    public function testRemovingAConnectedSiteDeletesAKeyStillHeldButLeavesTheCareAccount(): void
    {
        $token = $this->start();
        $this->site->keyStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->back($token);
        $this->site->keyStays = null;
        $this->site->calls = [];

        $this->engine()->remove(68, 'whmcs:AddonCancelled');

        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'the care account stays on the site after removal, as the client is told');
        self::assertSame(State::Removed, $this->storage->site(68)?->state);
        self::assertNull($this->storage->heldKey(68));
        self::assertArrayNotHasKey(68, $this->storage->logins);
    }

    public function testInDryRunAReturnUsesNothingAndTheLinkStaysUnused(): void
    {
        $token = $this->start();

        $return = $this->engine(true)->completeApproval($token, self::SITE, 'owner', self::KEY, 'client:540');

        self::assertSame([ApprovalReturn::DRY_RUN, true], [$return->result, $return->keyLeft]);
        self::assertSame([[], []], [$this->site->calls, $this->dashboard->calls]);
        self::assertFalse($this->storage->approval(hash('sha256', $token))?->used);
        self::assertSame(State::Connecting, $this->storage->site(68)?->state);
        self::assertSame(ApprovalReturn::DRY_RUN, $this->engine(true)->rejectApproval($token, 'client:540')->result);
        self::assertFalse($this->engine(true)->expireApproval(68, 'cron:daily'));
    }

    public function testAReturnWaitsForItsTurnAndAKeyThatStillCannotBeUsedIsDeletedOnTheSite(): void
    {
        $token = $this->start();
        $this->storage->locked = [68];
        $this->storage->waits = [];

        $return = $this->back($token);

        self::assertSame([ApprovalReturn::BUSY, 68, false], [$return->result, $return->addonRowId, $return->keyLeft]);
        self::assertSame([10], $this->storage->waits, 'a return waits a few seconds for another request to finish');
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'the browser is sent on without the key, so a key that is not used must not stay on the site');
        self::assertFalse($this->storage->approval(hash('sha256', $token))?->used);

        $this->site->keyStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        self::assertTrue($this->back($token)->keyLeft, 'and when it cannot be deleted the client is told');
    }

    public function testWhatAnEarlierKeyAttemptLeftIsRemovedOnTheSiteItWasMadeOnNotOnANewlyTypedAddress(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to connect the site.');
        $this->back($token);
        $this->dashboard->addRefuses = null;
        $this->site->calls = [];

        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540', 'https://shop.clientsite.example');

        self::assertSame(['removeAccount ' . self::SITE . ' owner wpcarek7m2x9ab', 'deleteKey ' . self::SITE . ' owner', 'installPlugin https://shop.clientsite.example owner'], $this->site->calls, 'a key only ever goes to the site it was approved on');
    }

    public function testANewApprovalForANewlyTypedAddressAlsoCleansUpAtTheOldOne(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to connect the site.');
        $this->back($token);
        $this->site->calls = [];

        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540', 'https://shop.clientsite.example');

        self::assertTrue($start->ok, $start->message);
        self::assertSame(['index https://shop.clientsite.example', 'removeAccount ' . self::SITE . ' owner wpcarek7m2x9ab', 'deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame('', $start->note);
    }

    public function testWhatCouldNotBeRemovedBeforeAPasswordConnectIsToldToTheClient(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');
        $this->back($token);
        $this->site->refuses = null;
        $this->site->accountStays = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');
        $this->site->keyStays = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertStringContainsString('application password whose name begins "WordPress Care"', $outcome->note);
        self::assertStringContainsString('wpcarek7m2x9ab', $outcome->note);
        self::assertNull($this->storage->heldKey(68));
    }

    public function testWhatCouldNotBeRemovedIsKeptInTheReasonWhenThePasswordConnectFailsToo(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->back($token);
        $this->site->accountStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->site->keyStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertFalse($outcome->ok);
        $error = (string) $this->storage->site(68)?->lastError;
        self::assertStringStartsWith('The site could not be reached.', $error);
        self::assertStringContainsString('application password whose name begins "WordPress Care"', $error, 'nothing left on the site is forgotten without the client being told');
    }

    public function testWhatCouldNotBeRemovedBeforeANewApprovalIsGivenToTheClientToReadFirst(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');
        $this->back($token);
        $this->site->refuses = null;
        $this->site->keyStays = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');

        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540');

        self::assertTrue($start->ok);
        self::assertNotNull($start->url);
        self::assertStringContainsString('application password whose name begins "WordPress Care"', $start->note);
        self::assertStringNotContainsString('wpcarek7m2x9ab', $start->note, 'the care account was removed');
    }

    public function testASiteTheDashboardAlreadyConnectedIsFinishedNotTornDownByANewAttempt(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached: Operation timed out.');
        $this->back($token);
        $this->dashboard->addRefuses = null;
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://clientsite.example/', 100, 'connected', null, '')];
        $this->site->calls = [];
        $this->dashboard->calls = [];

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'the care account the dashboard is connected as is not removed, and no second login is made');
        self::assertSame(['sites', 'administrators 555'], $this->dashboard->calls);
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, 555], [$site?->state, $site?->dashboardSiteId]);
        self::assertEquals($this->care(), $this->storage->logins[68], 'the stored login is the one the dashboard connected with');
    }

    public function testANewApprovalForASiteTheDashboardAlreadyConnectedSaysSoAndSendsNobodyAnywhere(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->back($token);
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://clientsite.example/', 100, 'connected', null, '')];

        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540');

        self::assertTrue($start->ok);
        self::assertNull($start->url);
        self::assertStringContainsString('already connected', $start->message);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
    }

    public function testACommandThatWaitedActsOnWhatIsThereNowNotOnWhatItSawBefore(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->back($token);
        $this->site->refuses = null;
        $this->site->calls = [];
        // While the daily retry waits for its turn, the client's own attempt connects the site.
        $this->storage->whileWaiting = function (): void {
            $this->storage->releaseKey(68);
            $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Connected, 100, 777, 0, '2026-11-09 14:00:00'));
        };

        $outcome = $this->engine()->retryConnect(68, 'cron:daily');

        self::assertFalse($outcome->ok);
        self::assertSame('Add-on 68 has no failed connect to retry.', $outcome->message);
        self::assertSame([], $this->site->calls);
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, 777], [$site?->state, $site?->dashboardSiteId], 'the newer state is not overwritten');
    }

    public function testAReturnThatWaitedSeesThatItsLinkWasReplacedMeanwhile(): void
    {
        $token = $this->start();
        $this->storage->whileWaiting = function (): void {
            $this->storage->lapseApprovals(68, '2026-11-09 14:00:00');
        };

        $return = $this->back($token);

        self::assertSame(ApprovalReturn::LAPSED, $return->result);
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertNull($this->storage->heldKey(68));
    }

    public function testRemovingSuspendingAndResumingWaitForARunningConnectionAndSaySoWhenItIsStillRunning(): void
    {
        $this->back($this->start());
        $before = $this->storage->site(68);
        $this->storage->locked = [68];
        $this->storage->waits = [];
        $this->dashboard->calls = [];

        foreach (['suspend', 'remove'] as $command) {
            $outcome = $this->engine()->{$command}(68, 'whmcs:event');
            self::assertFalse($outcome->ok, $command);
            self::assertStringContainsString('being connected right now', $outcome->message);
        }
        self::assertEquals($before, $this->storage->site(68));
        // Resuming is only ever asked of a suspended site.
        self::assertNotNull($before);
        $this->storage->sites[68] = $before->withState(State::Suspended);
        $outcome = $this->engine()->resume(68, 'whmcs:event');
        self::assertFalse($outcome->ok);

        self::assertSame([20, 20, 20], $this->storage->waits);
        self::assertSame([], $this->dashboard->calls);
        self::assertSame(State::Suspended, $this->storage->site(68)?->state);
    }

    public function testAPasswordConnectionCutOffHalfwayIsNotCalledAnApprovalThatWasNotGiven(): void
    {
        $this->start();
        $seen = null;
        $kept = null;
        $this->site->during = function () use (&$seen, &$kept): void {
            $seen = $this->storage->site(68);
            $kept = $this->storage->login(68);
        };
        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');
        self::assertInstanceOf(CareSite::class, $seen);
        self::assertInstanceOf(Login::class, $kept);
        self::assertSame([State::Connecting, '2026-11-10 14:00:00'], [$seen->state, $seen->retryAfter], 'in flight, with its retry already armed');

        // A request killed during the install leaves the care site and its login as they were at that moment.
        $this->storage->saveSite($seen);
        $this->storage->saveLogin(68, $kept);

        self::assertTrue($this->engine()->expireApproval(68, 'client:540'));
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, '2026-11-10 14:00:00'], [$site?->state, $site?->retryAfter]);
        self::assertStringContainsString('interrupted', (string) $site?->lastError);
        self::assertStringNotContainsString('24 hours', (string) $site?->lastError);
        self::assertSame('Secret1', $this->storage->logins[68]->password, 'the retry runs with the login that was given');
    }

    public function testALoginGivenForOneAddressIsNeverRetriedAtAnother(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->engine()->connect(68, new Login('owner', 'PasswordForTheFirstSite'), 'client:540');
        $this->site->refuses = null;

        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540', 'https://shop.clientsite.example');
        self::assertTrue($start->ok, $start->message);
        self::assertArrayNotHasKey(68, $this->storage->logins, 'the login was given for the first address; it must not wait in store for the second');

        $this->engine()->rejectApproval($this->secrets->tokens[0], 'client:540');

        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null, 'https://shop.clientsite.example'], [$site?->state, $site?->retryAfter, $site?->siteUrl], 'nothing to retry with at the new address');
        self::assertFalse($this->engine()->retryConnect(68, 'cron:daily')->ok);
        self::assertNotContains('installPlugin https://shop.clientsite.example owner', $this->site->calls);
    }

    public function testTheSameReturnArrivingWhileItsFirstRequestStillRunsLeavesTheKeyAlone(): void
    {
        $token = $this->start();
        // The first request has claimed the link and is still connecting the site.
        $this->storage->claimApproval(hash('sha256', $token), new HeldKey(new Login('owner', self::KEY), $this->care()), '2026-11-09 14:00:00');
        $this->storage->locked = [68];

        $again = $this->back($token);

        self::assertSame([ApprovalReturn::RUNNING, 68, false], [$again->result, $again->addonRowId, $again->keyLeft]);
        self::assertSame([], $this->site->calls, 'a reload must not delete the key the first request is using');

        $other = $this->back($token, self::OTHER_KEY);
        self::assertSame(ApprovalReturn::BUSY, $other->result);
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'a second key made meanwhile is still deleted');
    }

    public function testAnAttemptForAnotherAddressWhileTheDashboardHoldsTheFirstSaysWhichAddressIsConnected(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->back($token);
        $this->dashboard->addRefuses = null;
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://clientsite.example/', 100, 'connected', null, '')];

        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540', 'https://shop.clientsite.example');

        self::assertTrue($start->ok);
        self::assertNull($start->url);
        self::assertStringContainsString('already connected at ' . self::SITE, $start->message);
        self::assertStringContainsString('https://shop.clientsite.example was not used', $start->message, 'the client is not left thinking the new address was connected');
        self::assertSame(self::SITE, $this->storage->site(68)?->siteUrl);
    }

    public function testAPasswordConnectForAnotherAddressWhileTheDashboardHoldsTheFirstSaysSoToo(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->back($token);
        $this->dashboard->addRefuses = null;
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://clientsite.example/', 100, 'connected', null, '')];

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540', 'https://shop.clientsite.example');

        self::assertTrue($outcome->ok);
        self::assertStringContainsString('already connected at ' . self::SITE, $outcome->note);
        self::assertStringContainsString('https://shop.clientsite.example was not used', $outcome->note);
        self::assertSame('', $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540')->note, 'a connected site is simply already connected');
    }

    public function testRemovingASiteTheDashboardConnectedWithoutTheModuleKnowingTakesItOffTheDashboardToo(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->back($token);
        $this->dashboard->addRefuses = null;
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://clientsite.example/', 100, 'connected', null, '')];
        $this->site->calls = [];
        $this->dashboard->calls = [];

        $outcome = $this->engine()->remove(68, 'whmcs:AddonCancelled');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['sites', 'removeSite 555'], $this->dashboard->calls, 'no site is left on the dashboard that nothing bills and nothing points at');
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'MainWP switches its plugin off; the care account stays, as for any removed site');
        self::assertSame(State::Removed, $this->storage->site(68)?->state);
        self::assertNull($this->storage->heldKey(68));
    }

    public function testRemovingASiteWhoseConnectFailedOnThePasswordRouteAsksTheDashboardToo(): void
    {
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');
        $this->dashboard->addRefuses = null;
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://clientsite.example/', 100, 'connected', null, '')];
        $this->dashboard->calls = [];

        $this->engine()->remove(68, 'whmcs:AddonCancelled');

        self::assertSame(['sites', 'removeSite 555'], $this->dashboard->calls);
        self::assertSame(State::Removed, $this->storage->site(68)?->state);
        self::assertArrayNotHasKey(68, $this->storage->logins);
    }

    public function testAnAddressThatOnlyRedirectsEndsWithoutARetryAndSaysWhereItLeads(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::MOVED, 'The site at clientsite.example sends visitors on to www.clientsite.example.', 'https://www.clientsite.example');

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertFalse($outcome->ok);
        self::assertTrue($outcome->attempted);
        self::assertSame('https://www.clientsite.example', $outcome->movedTo, 'the caller can try the address the site really answers at');
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null], [$site?->state, $site?->retryAfter], 'trying the same address again tomorrow cannot work');
        self::assertNull($this->storage->login(68), 'the login was not sent to that address and nothing will retry it there, so it is not kept');
    }

    public function testAnOutcomeSaysWhetherTheAttemptRanAtAll(): void
    {
        $this->storage->locked = [68];
        self::assertFalse($this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540')->attempted, 'busy: nothing was tried and nothing kept');
        $this->storage->locked = [];

        $this->site->refuses = new SiteFailure(SiteFailure::CAPTCHA, 'The login page has a captcha.');
        self::assertTrue($this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540')->attempted);
        $this->site->refuses = null;
        self::assertTrue($this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540')->attempted);
        self::assertFalse($this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540')->attempted, 'already connected: nothing was tried');
    }

    public function testWhatAnEarlierKeyAttemptLeftIsAlsoInTheLogLineStaffSeeForTheConnect(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');
        $this->back($token);
        $this->site->refuses = null;
        $this->site->keyStays = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');

        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'admin:1');

        $entry = end($this->storage->log);
        self::assertSame(['connect', 'done'], [$entry->action, $entry->outcome]);
        self::assertStringContainsString('application password whose name begins "WordPress Care" may still be on', $entry->detail, 'staff connected the site for the client: what was left must be where staff look');
    }

    public function testWhatAnEarlierAttemptInTheSameRequestCouldNotRemoveIsCarriedIntoTheNextOne(): void
    {
        $carried = 'The account wpcarek7m2x9ab made for care may still be on your site: delete it there under Users.';

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540', 'https://www.clientsite.example', $carried);

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame($carried, $outcome->note, 'a move that was followed must not lose what the first attempt found');
        $entry = end($this->storage->log);
        self::assertStringContainsString($carried, $entry->detail);
    }

    public function testWhatWasCarriedIsKeptWhenTheDashboardTurnsOutToHoldTheSiteAlready(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->back($token);
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://clientsite.example/', 100, 'connected', null, '')];
        $carried = 'The account wpcare-old made for care may still be on your site: delete it there under Users.';

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540', null, $carried);

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
        self::assertSame($carried, $outcome->note);
    }

    public function testWhatCouldNotBeRemovedIsInTheLogLineOfAFailedConnectToo(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->back($token);
        $this->site->accountStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->site->keyStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');

        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'admin:1');

        $entry = end($this->storage->log);
        self::assertSame(['connect', 'failed'], [$entry->action, $entry->outcome]);
        self::assertStringContainsString('For the client: The account wpcarek7m2x9ab made for care may still be on your site', $entry->detail, 'the line the staff panel shows');
    }

    public function testADeclineThatFindsTheSiteBusyIsNotCalledAnApprovalThatCameBack(): void
    {
        $token = $this->start();
        $this->storage->locked = [68];
        $this->storage->waits = [];

        $return = $this->engine()->rejectApproval($token, 'client:540');

        self::assertSame(ApprovalReturn::RUNNING, $return->result);
        self::assertSame([10], $this->storage->waits, 'a decline waits for its turn like any return');
        self::assertSame(State::Connecting, $this->storage->site(68)?->state);
    }

    public function testWhenTheRetriesEndForASiteTheDashboardAlreadyHoldsItIsFinishedNotTornDown(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->back($token);
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://clientsite.example/', 100, 'connected', null, '')];
        $this->site->calls = [];

        $outcome = $this->engine()->endRetries(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'the care account the dashboard connected as is not removed');
        self::assertSame([State::Connected, 555], [$this->storage->site(68)?->state, $this->storage->site(68)?->dashboardSiteId]);
    }

    public function testTheTidyActsOnWhatIsThereOnceItHasTheAddon(): void
    {
        $token = $this->start();
        $this->site->keyStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->back($token);
        $this->site->keyStays = null;
        $this->site->calls = [];
        // While the tidy waits for its turn, the site is removed and its key released.
        $this->storage->whileWaiting = function (): void {
            $this->storage->releaseKey(68);
            $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Removed, 100, 7));
        };

        self::assertSame(0, $this->engine()->tidyKeys('cron:daily'));
        self::assertSame([], $this->site->calls, 'a key that was dealt with meanwhile is not sent again');
    }

    public function testASiteWhoseConnectWasTriedIsNotMarkedRemovedWhileTheDashboardCannotSayWhetherItHoldsIt(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->back($token);
        $this->dashboard->offline = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached to list the sites.');
        $this->site->calls = [];

        $outcome = $this->engine()->remove(68, 'whmcs:AddonCancelled');

        self::assertFalse($outcome->ok);
        self::assertStringContainsString('could not be removed', $outcome->message);
        self::assertSame(State::Failed, $this->storage->site(68)?->state, 'nothing is torn down on a guess; the removal is tried again');
        self::assertSame([], $this->site->calls);
        self::assertNotNull($this->storage->heldKey(68));
    }

    public function testOfTwoKeysArrivingOnOneLinkAtTheSameMomentTheLoserIsDeletedOnTheSite(): void
    {
        $token = $this->start();
        $this->storage->claimedFirstBy = new HeldKey(new Login('owner', self::OTHER_KEY), $this->care());

        $return = $this->back($token);

        self::assertSame([ApprovalReturn::USED, false], [$return->result, $return->keyLeft]);
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'the key that did not get the link is not left on the site');
        self::assertSame(self::OTHER_KEY, $this->storage->heldKey(68)?->key->password);
    }

    public function testTheSameKeyArrivingTwiceAtTheSameMomentIsNotDeleted(): void
    {
        $token = $this->start();
        $this->storage->claimedFirstBy = new HeldKey(new Login('owner', self::KEY), $this->care());

        $return = $this->back($token);

        self::assertSame([ApprovalReturn::USED, false], [$return->result, $return->keyLeft]);
        self::assertSame([], $this->site->calls, 'the other request is using it');
    }

    public function testAPasswordConnectIsRefusedWhileAnotherRequestWorksOnTheAddon(): void
    {
        $this->storage->locked = [68];

        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertFalse($outcome->ok);
        self::assertStringContainsString('being connected right now', $outcome->message);
        self::assertSame([], $this->site->calls);
    }

    public function testANewPasswordAttemptStartsTheRetryCountAgain(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');
        $this->engine()->retryConnect(68, 'cron:daily');
        $this->engine()->retryConnect(68, 'cron:daily');
        self::assertSame(2, $this->storage->site(68)?->retryCount);

        $this->engine()->connect(68, new Login('owner', 'Secret2'), 'client:540');

        self::assertSame(0, $this->storage->site(68)?->retryCount, 'a login the client typed anew gets its own week of retries');
    }

    public function testSuspensionEndsAnApprovalThatIsStillOutAndTheSiteWaitsToBeConnectedAgain(): void
    {
        $token = $this->start();

        $outcome = $this->engine()->suspend(68, 'whmcs:AddonSuspended');

        self::assertSame([true, 'Add-on 68 has no site on the dashboard to suspend.'], [$outcome->ok, $outcome->message]);
        self::assertSame(['sites'], $this->dashboard->calls, 'only asked whether it holds the site; nothing to suspend');
        $site = $this->storage->site(68);
        self::assertSame([State::Provisioned, null, null], [$site?->state, $site?->lastError, $site?->retryAfter], 'when care is active again the client is prompted again');
        $entry = end($this->storage->log);
        self::assertSame(['suspend', 'done'], [$entry->action, $entry->outcome]);
        self::assertStringContainsString('the approval the client was sent to give was ended', $entry->detail);

        // A key approved after that is not used, and is deleted on the site.
        $return = $this->back($token);
        self::assertSame(ApprovalReturn::LAPSED, $return->result);
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame(State::Provisioned, $this->storage->site(68)?->state);
    }

    public function testSuspensionRemovesWhatAFailedKeyAttemptLeftSoThatNoKeyIsHeldWhileCareIsSuspended(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::INSTALL_FAILED, 'The plugin could not be installed.');
        $this->back($token);
        self::assertNotNull($this->storage->heldKey(68), 'held for the retries');
        $this->site->refuses = null;
        $this->site->calls = [];
        $this->dashboard->calls = [];

        $outcome = $this->engine()->suspend(68, 'whmcs:AddonSuspended');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['removeAccount ' . self::SITE . ' owner wpcarek7m2x9ab', 'deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame(['sites'], $this->dashboard->calls);
        self::assertNull($this->storage->heldKey(68));
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null, 'The plugin could not be installed.'], [$site?->state, $site?->retryAfter, $site?->lastError], 'nothing is left to retry with; the client is prompted again when care is active');
        $this->assertNoSecretIsLogged();
    }

    public function testWhatASuspensionCouldNotRemoveOnTheSiteIsKeptForTheClientToRead(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::INSTALL_FAILED, 'The plugin could not be installed.');
        $this->back($token);
        // The hosting is suspended too, so the site no longer answers.
        $this->site->accountStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->site->keyStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');

        $outcome = $this->engine()->suspend(68, 'whmcs:AfterModuleSuspend');

        self::assertTrue($outcome->ok);
        self::assertNull($this->storage->heldKey(68), 'the key is forgotten here either way');
        self::assertStringContainsString('The account wpcarek7m2x9ab made for care may still be on your site', (string) $this->storage->site(68)?->lastError);
        self::assertStringContainsString('may still be on your site', $outcome->note);
    }

    public function testSuspensionLeavesAFailedPasswordAttemptItsLoginAndItsPlaceOnTheRetryList(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');
        $before = $this->storage->site(68);
        $this->site->calls = [];
        $this->dashboard->calls = [];
        $this->storage->log = [];

        $outcome = $this->engine()->suspend(68, 'whmcs:AddonSuspended');

        self::assertTrue($outcome->ok);
        self::assertEquals($before, $this->storage->site(68), 'the login is kept until the care site is removed');
        self::assertArrayHasKey(68, $this->storage->logins);
        self::assertSame([[], ['sites'], []], [$this->site->calls, $this->dashboard->calls, $this->storage->log]);
    }

    public function testSuspensionOfASiteTheDashboardHoldsAlthoughItsAnswerWasLostSuspendsItThere(): void
    {
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');
        $this->back($token);
        $this->dashboard->addRefuses = null;
        $this->dashboard->sitesList = [new DashboardSite(555, 'https://clientsite.example/', 100, 'connected', null, '')];
        $this->dashboard->calls = [];
        $this->site->calls = [];

        $outcome = $this->engine()->suspend(68, 'whmcs:AddonSuspended');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['sites', 'administrators 555', 'suspendSite 555'], $this->dashboard->calls, 'care does not go on unpaid on the dashboard because an answer was lost');
        $site = $this->storage->site(68);
        self::assertSame([State::Suspended, 555, null], [$site?->state, $site?->dashboardSiteId, $site?->retryAfter]);
        self::assertEquals($this->care(), $this->storage->logins[68], 'the dashboard connected as the care account, so that is the stored login');
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'the care account stays: the dashboard connects as it');
        self::assertNull($this->storage->heldKey(68));
    }

    public function testASuspensionFailsAndChangesNothingWhileTheDashboardCannotSayWhetherItHoldsTheSite(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::INSTALL_FAILED, 'The plugin could not be installed.');
        $this->back($token);
        $before = $this->storage->site(68);
        $this->site->calls = [];
        $this->dashboard->offline = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard could not be reached.');

        $outcome = $this->engine()->suspend(68, 'whmcs:AddonSuspended');

        self::assertFalse($outcome->ok);
        self::assertStringContainsString('could not be suspended', $outcome->message);
        self::assertEquals($before, $this->storage->site(68));
        self::assertNotNull($this->storage->heldKey(68));
        self::assertSame([], $this->site->calls);
        $entry = end($this->storage->log);
        self::assertSame(['suspend', 'failed'], [$entry->action, $entry->outcome]);
    }

    public function testSuspensionEndsAPasswordAttemptThatWasCutOffAndKeepsItsLoginForLater(): void
    {
        // A password attempt armed its retry and its request then died: Connecting, nothing running.
        $site = $this->storage->site(68);
        self::assertNotNull($site);
        $this->storage->saveSite($site->inFlight('2026-11-10 14:00:00'));
        $this->storage->saveLogin(68, new Login('owner', 'Secret1'));

        $outcome = $this->engine()->suspend(68, 'whmcs:AddonSuspended');

        self::assertTrue($outcome->ok);
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, '2026-11-10 14:00:00'], [$site?->state, $site?->retryAfter]);
        self::assertArrayHasKey(68, $this->storage->logins);
    }

    public function testInDryRunASuspensionOfASiteThatIsNotOnTheDashboardEndsNothing(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::INSTALL_FAILED, 'The plugin could not be installed.');
        $this->back($token);
        $this->site->calls = [];
        $this->dashboard->calls = [];

        $outcome = $this->engine(true)->suspend(68, 'whmcs:AddonSuspended');

        self::assertTrue($outcome->ok);
        self::assertSame([[], []], [$this->site->calls, $this->dashboard->calls]);
        self::assertNotNull($this->storage->heldKey(68));
        $entry = end($this->storage->log);
        self::assertSame(['suspend', 'would run', true], [$entry->action, $entry->outcome, $entry->dryRun]);
        self::assertStringContainsString('is not on the dashboard', $entry->detail);
    }

    /** A return claimed the key and its request then died; the panel's lazy expiry has since made it Failed, to be tried again. */
    private function aKeyAttemptThatWasCutOff(): void
    {
        $token = $this->start();
        $this->storage->claimApproval(hash('sha256', $token), new HeldKey(new Login('owner', self::KEY), $this->care()), '2026-11-09 14:00:00');
        self::assertTrue($this->engine()->expireApproval(68, 'module'));
        self::assertSame('The connection was interrupted before it finished. It is tried again by itself.', $this->storage->site(68)?->lastError);
        $this->site->calls = [];
        $this->dashboard->calls = [];
    }

    public function testASuspensionThatEndsTheRetriesDoesNotLeaveThePromiseThatItIsTriedAgain(): void
    {
        $this->aKeyAttemptThatWasCutOff();

        $this->engine()->suspend(68, 'whmcs:AddonSuspended');

        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null, 'The connection was interrupted before it finished.'], [$site?->state, $site?->retryAfter, $site?->lastError], 'nothing will try again, so the client must not be told to wait for it');
    }

    public function testEndingTheRetriesDoesNotLeaveThePromiseThatItIsTriedAgain(): void
    {
        $this->aKeyAttemptThatWasCutOff();

        $this->engine()->endRetries(68, 'cron:daily');

        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null, 'The connection was interrupted before it finished.'], [$site?->state, $site?->retryAfter, $site?->lastError]);
    }

    public function testARetryThatFindsNothingLeftToRetryWithDoesNotSayBothThatItIsAndIsNotTriedAgain(): void
    {
        // A password attempt was cut off, and its stored login can no longer be read.
        $site = $this->storage->site(68);
        self::assertNotNull($site);
        $this->storage->saveSite($site->failedWithRetry('The connection was interrupted before it finished. It is tried again by itself.', '2026-11-09 13:00:00'));

        $this->engine()->retryConnect(68, 'cron:daily');

        $reason = (string) $this->storage->site(68)?->lastError;
        self::assertStringNotContainsString('It is tried again by itself.', $reason);
        self::assertStringContainsString('so it is not tried again: connect the site again', $reason);
    }

    public function testAReturnForCareThatIsNotActiveIsToldThatAndNotThatItsLinkLapsed(): void
    {
        // The suspension ended the approval; the client approved on their site all the same.
        $token = $this->start();
        $this->engine()->suspend(68, 'whmcs:AddonSuspended');
        $this->site->calls = [];

        $return = $this->back($token, refuse: 'WordPress Care is not active for this site.');

        self::assertSame([ApprovalReturn::REFUSED, 'WordPress Care is not active for this site.'], [$return->result, $return->message], 'a lapsed link asks the client to start again, which they cannot do now');
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame(State::Provisioned, $this->storage->site(68)?->state);
    }

    private const OUR_SIDE = 'We could not finish connecting your site because of a fault on our side. It is tried again by itself.';

    public function testAFaultOnOurSideIsToldToTheClientAsOursAndInFullOnlyToStaff(): void
    {
        // The password route: the dashboard refuses the module's own API key.
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::KEY_REFUSED, 'The dashboard refused the API key (HTTP 401). Make a key on the dashboard under API Access and enter it in the module\'s settings. If the key is right, the dashboard\'s web server may not be passing the Authorization header on to WordPress.');
        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        $site = $this->storage->site(68);
        self::assertSame(self::OUR_SIDE, $site?->lastError, 'the client can do nothing about the module\'s settings and is not shown them');
        self::assertNotNull($site?->retryAfter);
        self::assertStringNotContainsString('settings', $outcome->message);
        $entry = end($this->storage->log);
        self::assertSame(['connect', 'failed'], [$entry->action, $entry->outcome]);
        self::assertStringContainsString('Make a key on the dashboard under API Access', $entry->detail, 'staff read the real reason where they look');

        // The key route: the dashboard address answers without MainWP's REST API.
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::BAD_ANSWER, 'The dashboard at dash.example.com does not answer as MainWP\'s REST API when asked to connect ' . self::SITE . ' (HTTP 404). Check the dashboard address in the module\'s settings and that MainWP Dashboard is active there; the dashboard\'s permalinks must not be set to Plain.');
        $this->back($token);

        $site = $this->storage->site(68);
        self::assertSame(self::OUR_SIDE, $site?->lastError);
        self::assertNotNull($this->storage->heldKey(68), 'the key is kept for the retry, as for any other failure');
        $failed = array_values(array_filter($this->storage->log, static fn ($e): bool => $e->action === 'connect' && $e->outcome === 'failed'));
        self::assertStringContainsString('dash.example.com does not answer as MainWP', end($failed)->detail);
        $this->assertNoSecretIsLogged();
    }

    public function testWhatTheDashboardSaysAboutTheSiteItselfIsStillToldToTheClient(): void
    {
        // MainWP's own refusal to add the site is about the site or the login, which the client can put right.
        $reason = 'The dashboard refused to connect ' . self::SITE . ': Invalid user. Please verify your Admin Username and Password';
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, $reason);

        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertSame($reason, $this->storage->site(68)?->lastError);
    }

    public function testWordsOfTheDashboardThatNameMainWpAreNotToldToTheClient(): void
    {
        // The client knows the service, not the tool behind it: MainWP's own words that name MainWP stay in the log.
        $said = 'No MainWP Child plugin detected. First, install and activate the plugin and add your site to your MainWP Dashboard afterward';
        $refused = 'Your site could not be connected.';

        // The password route.
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to connect ' . self::SITE . ': ' . $said . '.');
        $outcome = $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        $site = $this->storage->site(68);
        self::assertSame($refused, $site?->lastError);
        self::assertNotNull($site?->retryAfter, 'retried as any other refusal is');
        self::assertFalse($outcome->dashboardFault, 'the dashboard answered: not a fault on our side');
        $entry = end($this->storage->log);
        self::assertSame(['connect', 'failed'], [$entry->action, $entry->outcome]);
        self::assertStringContainsString($said, $entry->detail, 'staff read the dashboard\'s own words where they look');

        // The key route.
        $token = $this->start();
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to connect ' . self::SITE . ': Please review this help document: https://kb.mainwp.com/docs/potential-issues/.');
        $this->back($token);

        self::assertSame($refused, $this->storage->site(68)?->lastError, 'in any spelling');
        $failed = array_values(array_filter($this->storage->log, static fn ($e): bool => $e->action === 'connect' && $e->outcome === 'failed'));
        self::assertStringContainsString('kb.mainwp.com', end($failed)->detail);
        $this->assertNoSecretIsLogged();
    }

    public function testAnErrorOfTheModuleItselfIsToldToTheClientAsAFaultOnOurSide(): void
    {
        // An error nobody wrote for a client, whatever its words: PHP's or the database's, not the site's and not the dashboard's.
        foreach (['SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded (SQL: update `mod_mainwp_sites` set `state` = connected)', 'Division by zero'] as $words) {
            $this->setUp();
            $this->dashboard->addRefuses = new \RuntimeException($words);

            $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

            $site = $this->storage->site(68);
            self::assertSame(self::OUR_SIDE, $site?->lastError, $words);
            self::assertNotNull($site?->retryAfter, 'it is tried again, as the sentence says');
            $entry = end($this->storage->log);
            self::assertStringContainsString($words, $entry->detail, 'staff read it in full');
        }
    }

    public function testAnApproverWhoIsNotAnAdministratorAndWhoseOwnNameHoldsTheWordIsNotToldOfAFaultOnOurSide(): void
    {
        $token = $this->start();
        $this->site->refuses = new SiteFailure(SiteFailure::NOT_ADMIN, 'The account mainwp_editor is not an administrator of the site. Use an administrator account.');

        $this->back($token, self::KEY, self::SITE, 'mainwp_editor');

        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null], [$site?->state, $site?->retryAfter]);
        self::assertSame('Your site could not be connected. Approve the connection again while signed in as an administrator.', $site?->lastError, 'no fault of ours, and no retry that never comes');
        $failed = array_values(array_filter($this->storage->log, static fn ($e): bool => $e->action === 'connect' && $e->outcome === 'failed'));
        self::assertStringContainsString('The account mainwp_editor is not an administrator of the site', end($failed)->detail, 'staff read the site\'s own words');
    }

    public function testAStoredReasonIsReadByTheClientUnlessItNamesMainWpInAnyCase(): void
    {
        // MainWP writes its name as one word everywhere, also inside longer ones (its error code NOMAINWP, its classes and tables).
        $plain = 'Your site could not be connected.';
        foreach ([
            'MainWP\'s plugin could not be installed on the site: Destination folder already exists. Contact support.',
            'The dashboard refused to add the site: MAINWP CHILD missing.',
            'The dashboard refused to add the site: NOMAINWP.',
            'SQLSTATE[HY000] (SQL: update `mod_mainwp_sites`)',
            'See https://kb.mainwp.com/docs/potential-issues/ for help.',
        ] as $stored) {
            self::assertSame($plain, CareEngine::reasonForClient($stored, self::SITE), $stored);
        }
        foreach ([
            'The username or password was not accepted by the site. Check them and try again.',
            'WordPress\'s login page was not found at clientsite.example/wp-login.php; a security plugin may have moved it.',
            'The login page of your domain wp-login.php was not found.',
            'Your main wp-config.php could not be read.',
        ] as $stored) {
            self::assertSame($stored, CareEngine::reasonForClient($stored, self::SITE), $stored);
        }
        self::assertNull(CareEngine::reasonForClient(null, self::SITE));
        self::assertSame('', CareEngine::reasonForClient('', self::SITE));
    }

    public function testTheSitesOwnAddressInAnyOfItsFormsDoesNotHideAReason(): void
    {
        foreach ([
            ['https://clientsite.example/mainwp-shop', 'The site could not be reached at https://clientsite.example/mainwp-shop (timed out). Check the address and try again.'],
            ['https://clientsite.example/mainwp-shop/', 'The WordPress site at clientsite.example/mainwp-shop answers at https://clientsite.example.'],
            ['https://www.mainwp-fans.example', 'The site could not be reached at mainwp-fans.example (timed out).'],
            ['https://mainwp-fans.example', 'The site at mainwp-fans.example sends visitors on to www.mainwp-fans.example.'],
            ['mainwp-fans.example', 'The site could not be reached at mainwp-fans.example (timed out).'],
            ['https://mainwp-fans.example:8443', 'The site could not be reached at mainwp-fans.example (Connection refused).'],
            ['https://mainwp-fans.example:8443/shop', 'The site at mainwp-fans.example:8443/shop sends visitors on to mainwp-fans.example:8443.'],
        ] as [$siteUrl, $stored]) {
            self::assertSame($stored, CareEngine::reasonForClient($stored, $siteUrl), $siteUrl);
        }
        // The address does not excuse other words that name it.
        self::assertSame('Your site could not be connected.', CareEngine::reasonForClient('The dashboard refused to connect https://www.mainwp-fans.example: No MainWP Child plugin detected.', 'https://www.mainwp-fans.example'));
    }

    public function testASiteThatCannotBeAskedWhichRouteItOffersGivesOnlyTheSitesOwnSentenceAsTheReason(): void
    {
        $off = 'Approving the connection on your site is not available at the moment. Connect with an administrator username and password instead, or contact support.';
        // An error of the module itself, whatever its words, and a sentence of the site that names MainWP: neither is the client's to read.
        foreach ([
            new \RuntimeException('MainWpForWhmcs\Engine\WordPressSite::index(): Return value must be of type MainWpForWhmcs\Engine\SiteIndex, null returned'),
            new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused'),
            new SiteFailure(SiteFailure::BAD_ANSWER, 'WordPress did not answer at clientsite.example as it should: MainWP Child said no.'),
        ] as $failure) {
            $this->site->index = $failure;

            $offer = $this->engine()->routeOffer(68);

            self::assertSame([false, $off], [$offer->keyRoute, $offer->reason], $failure->getMessage());
        }

        $this->site->index = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached at clientsite.example (timed out). Check the address and try again.');
        self::assertSame('The site could not be reached at clientsite.example (timed out). Check the address and try again.', $this->engine()->routeOffer(68)->reason, 'the site\'s own sentence is the client\'s to read');
    }

    public function testWhenTheRetriesOfAFaultOnOurSideEndItNoLongerSaysItIsTriedAgain(): void
    {
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard at dash.example.com could not be reached to connect the site: Operation timed out.');
        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        $this->engine()->endRetries(68, 'cron:daily');

        self::assertSame('We could not finish connecting your site because of a fault on our side.', $this->storage->site(68)?->lastError);
    }

    /** A care site whose connection broke and could not be mended: Failed, and the dashboard still holds its (dead) site. */
    private function aSiteWhoseDashboardSiteIsDead(int $dashboardSiteId = 101): void
    {
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Failed, 100, $dashboardSiteId, 0, '2026-11-01 00:00:05', 'Your site is no longer connected to care. Connect it again.', null));
        $this->dashboard->status[$dashboardSiteId] = 'disconnected';
        $this->dashboard->calls = [];
    }

    public function testConnectingAgainPointsTheDashboardsOwnSiteAtTheNewAdministratorAndAddsNoSecondOne(): void
    {
        $this->aSiteWhoseDashboardSiteIsDead();
        $this->dashboard->mendedBy[101] = ['admin', 'sync'];

        $outcome = $this->engine()->connect(68, new Login('newadmin', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['setSiteAdmin 101 newadmin', 'syncSite 101'], array_slice($this->dashboard->calls, 0, 2), 'no password goes to the dashboard, nothing is removed, nothing is added (bench, sixth run)');
        self::assertNotContains('addSite 100 ' . self::SITE . ' newadmin', $this->dashboard->calls);
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, 101, null], [$site?->state, $site?->dashboardSiteId, $site?->lastError]);
        self::assertSame(FixedSecrets::CARE_LOGIN, $this->storage->logins[68]->username, 'the care account is then made on the site, as on any password connect');
    }

    public function testConnectingAgainTriesMainWpsReconnectWhenTheSyncAloneDoesNotMendIt(): void
    {
        $this->aSiteWhoseDashboardSiteIsDead();
        $this->dashboard->mendedBy[101] = ['admin', 'reconnect'];

        $outcome = $this->engine()->connect(68, new Login('newadmin', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['setSiteAdmin 101 newadmin', 'syncSite 101', 'reconnectSite 101', 'site 101'], array_slice($this->dashboard->calls, 0, 4));
        self::assertSame([State::Connected, 101], [$this->storage->site(68)?->state, $this->storage->site(68)?->dashboardSiteId]);
    }

    public function testConnectingAgainAddsTheSiteWhenTheDashboardNoLongerHoldsTheOldOne(): void
    {
        // Staff removed the dead site on the dashboard meanwhile.
        $this->aSiteWhoseDashboardSiteIsDead(555);
        $this->dashboard->unknown = [555];

        $outcome = $this->engine()->connect(68, new Login('newadmin', 'Secret1'), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['setSiteAdmin 555 newadmin', 'addSite 100 ' . self::SITE . ' newadmin'], array_slice($this->dashboard->calls, 0, 2));
        $site = $this->storage->site(68);
        self::assertSame(State::Connected, $site?->state);
        self::assertNotSame(555, $site?->dashboardSiteId);
        self::assertNotNull($site?->dashboardSiteId);
    }

    public function testConnectingAgainThatMainWpCannotMendIsNotRetriedAndStaffAreToldWhatToClear(): void
    {
        $this->aSiteWhoseDashboardSiteIsDead();

        $outcome = $this->engine()->connect(68, new Login('newadmin', 'Secret1'), 'client:540');

        self::assertSame([false, false], [$outcome->ok, $outcome->dashboardFault], 'the dashboard answered; it is the old connection that is in the way');
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, 101, null], [$site?->state, $site?->dashboardSiteId, $site?->retryAfter], 'the dashboard still holds the site, so its id is kept; a retry would only fail the same way');
        self::assertStringStartsWith('Your site still holds an earlier care connection', (string) $site?->lastError);
        $detail = end($this->storage->log)->detail;
        self::assertStringContainsString('Please remove the site and add it again', $detail, 'MainWP\'s own words');
        self::assertStringContainsString('Remove that site on the dashboard', $detail);
    }

    public function testOnTheKeyRouteTheBrokenDashboardSiteIsPointedAtTheCareAccount(): void
    {
        $this->aSiteWhoseDashboardSiteIsDead();
        $this->dashboard->mendedBy[101] = ['admin', 'sync'];
        $token = $this->start();

        $return = $this->back($token);

        self::assertSame(ApprovalReturn::CONNECTED, $return->result, $return->message);
        self::assertSame(['setSiteAdmin 101 wpcarek7m2x9ab', 'syncSite 101', 'administrators 101'], $this->dashboard->calls);
        self::assertSame([State::Connected, 101], [$this->storage->site(68)?->state, $this->storage->site(68)?->dashboardSiteId]);
        self::assertEquals($this->care(), $this->storage->logins[68]);
    }

    public function testASiteWhosePluginStillHoldsAnEarlierConnectionIsNotRetriedAndTheClientIsSentToSupport(): void
    {
        $refused = new DashboardFailure(DashboardFailure::EARLIER_CONNECTION, 'The dashboard refused to connect ' . self::SITE . ': MainWP Child on the site refused it, and MainWP hid the reason. When the site was connected to a dashboard before, MainWP Child still holds that connection: clear it on the site (Settings, MainWP Child, Disconnect) and connect again.');
        $this->dashboard->addRefuses = $refused;

        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');

        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null], [$site?->state, $site?->retryAfter], 'no retry can clear what is on the site');
        self::assertSame('Your site still holds an earlier care connection, which has to be cleared before it can be connected again. Please contact support.', $site?->lastError);
        self::assertStringContainsString('MainWP Child, Disconnect', end($this->storage->log)->detail);

        // The key route: what the attempt made on the site is taken away again, since nothing will use it.
        $token = $this->start();
        $this->back($token);
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null], [$site?->state, $site?->retryAfter]);
        self::assertStringStartsWith('Your site still holds an earlier care connection', (string) $site?->lastError);
        self::assertNull($this->storage->heldKey(68));
        self::assertContains('removeAccount ' . self::SITE . ' owner wpcarek7m2x9ab', $this->site->calls);
        self::assertContains('deleteKey ' . self::SITE . ' owner', $this->site->calls);
    }

    public function testStartingAnApprovalStartsTheRetryCountAgain(): void
    {
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Failed, 100, null, 5, null, 'The login was refused.', '2026-11-10 14:00:00'));
        $this->storage->saveLogin(68, new Login('owner', 'Secret1'));

        $this->start();

        self::assertSame(0, $this->storage->site(68)?->retryCount, 'a person\'s own attempt is a fresh start, on either route');
    }

    public function testARetryRunsOnlyWhenItIsDueOnTheDayGiven(): void
    {
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Failed, 100, null, 1, null, 'The site could not be reached.', '2026-11-10 14:00:00'));
        $this->storage->saveLogin(68, new Login('owner', 'Secret1'));

        $early = $this->engine()->retryConnect(68, 'cron:daily', '2026-11-09');
        self::assertSame([false, false], [$early->ok, $early->attempted]);
        self::assertSame([[], 1], [$this->site->calls, $this->storage->site(68)?->retryCount], 'not due: nothing is tried and nothing counted');

        // Due by the date, not by the second: the daily run does not reach a site at the same moment each night.
        $due = $this->engine()->retryConnect(68, 'cron:daily', '2026-11-10');
        self::assertTrue($due->ok, $due->message);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
    }

    public function testARetryThatFailsBecauseOfTheDashboardIsNotCounted(): void
    {
        $this->storage->saveSite(new CareSite(68, 1176, 540, self::SITE, State::Failed, 100, null, 2, null, 'The site could not be reached.', '2026-11-09 00:00:10'));
        $this->storage->saveLogin(68, new Login('owner', 'Secret1'));
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::UNREACHABLE, 'The dashboard at dash.example.com could not be reached to connect the site: Operation timed out.');

        $outcome = $this->engine()->retryConnect(68, 'cron:daily');

        self::assertSame([false, true, true], [$outcome->ok, $outcome->attempted, $outcome->dashboardFault]);
        self::assertSame(2, $this->storage->site(68)?->retryCount, 'the client\'s seven retries are not used up by a fault on our side');
        self::assertNotNull($this->storage->site(68)?->retryAfter);

        // MainWP's own refusal of the site is about the site: it counts.
        $this->dashboard->addRefuses = new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard refused to connect the site: Invalid user.');
        $outcome = $this->engine()->retryConnect(68, 'cron:daily');
        self::assertSame([false, false, 3], [$outcome->ok, $outcome->dashboardFault, $this->storage->site(68)?->retryCount]);
    }

    public function testAFailedSiteThatWasSuspendedIsFailedAgainWhenCareResumes(): void
    {
        $this->aSiteWhoseDashboardSiteIsDead();

        self::assertTrue($this->engine()->suspend(68, 'whmcs:AddonSuspended')->ok);
        self::assertSame(State::Suspended, $this->storage->site(68)?->state);
        self::assertTrue($this->engine()->resume(68, 'whmcs:AddonActivated')->ok);

        $site = $this->storage->site(68);
        self::assertSame([State::Failed, 101, 'Your site is no longer connected to care. Connect it again.'], [$site?->state, $site?->dashboardSiteId, $site?->lastError], 'a site that was not connected before the suspension is not shown as connected after it');
        self::assertSame(['suspendSite 101', 'resumeSite 101'], $this->dashboard->calls);
    }
}
