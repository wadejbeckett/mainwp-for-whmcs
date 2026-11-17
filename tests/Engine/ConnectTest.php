<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\CareEngine;
use MainWpForWhmcs\Engine\Client;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\SiteFailure;
use MainWpForWhmcs\Engine\State;

require_once __DIR__ . '/Fakes.php';

/** The password route as the engine runs it */
final class ConnectTest extends TestCase
{
    private RecordingDashboard $dashboard;
    private RecordingSite $site;
    private MemoryStorage $storage;

    protected function setUp(): void
    {
        $this->dashboard = new RecordingDashboard();
        $this->site = new RecordingSite();
        $this->storage = new MemoryStorage();
        $this->engine(false)->provision(68, 1176, new Client(540, 'Demo Client', 'demo@example.com'), 'https://clientsite.example', 'hook:AddonActivated');
        $this->dashboard->calls = [];
        $this->storage->log = [];
    }

    private function engine(bool $dryRun): CareEngine
    {
        return new CareEngine($this->dashboard, $this->site, $this->storage, new FixedClock(), $dryRun);
    }

    public function testTheDashboardConnectsAsTheLoginNameTheSiteReportsEvenWhenAnEmailWasTyped(): void
    {
        $this->site->loginName = 'owner';

        $outcome = $this->engine(false)->connect(68, new Login('owner@example.com', "it's \"q\\uoted"), 'client:540');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame('addSite 100 https://clientsite.example owner', $this->dashboard->calls[0]);
        self::assertSame("it's \"q\\uoted", $this->dashboard->added[0]->password);
        self::assertSame([], $this->storage->logins, 'without a Care Account Email no care account takes its place, and the login typed is not kept either');
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, '2026-11-09 14:00:00', null, null], [$site?->state, $site?->lastSync, $site?->lastError, $site?->retryAfter]);
    }

    public function testAFailureOnTheSiteEndsInFailedWithThePlainReasonAndARetryEntry(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::CAPTCHA, 'The login page has a captcha, so the module cannot log in.');

        $outcome = $this->engine(false)->connect(68, new Login('owner', 'Secret1'), 'client:540');

        self::assertFalse($outcome->ok);
        self::assertSame([], $this->dashboard->calls, 'nothing is sent to the dashboard when the site refused');
        $site = $this->storage->site(68);
        self::assertSame(State::Failed, $site?->state);
        self::assertSame('The login page has a captcha, so the module cannot log in.', $site?->lastError);
        self::assertSame('2026-11-10 14:00:00', $site?->retryAfter, 'on the retry list: the daily check tries again a day later');
        self::assertSame(0, $site?->retryCount);
        self::assertSame('Secret1', $this->storage->logins[68]->password, 'the retry uses the stored login');
        $entry = end($this->storage->log);
        self::assertSame(['connect', 'failed'], [$entry->action, $entry->outcome]);
        self::assertStringContainsString('retry after 2026-11-10 14:00:00', $entry->detail);
    }

    public function testAPlainHttpAddressIsFailedWithoutARetryAndKeepsNoLogin(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::NOT_HTTPS, 'The site address must be an https:// address: the login is never sent over plain HTTP. Switch the site to HTTPS, or contact support.');

        $outcome = $this->engine(false)->connect(68, new Login('owner', 'Secret1'), 'client:540', 'http://clientsite.example');

        self::assertFalse($outcome->ok);
        $site = $this->storage->site(68);
        self::assertSame([State::Failed, null], [$site?->state, $site?->retryAfter], 'no retry: the same address cannot work tomorrow either');
        self::assertArrayNotHasKey(68, $this->storage->logins, 'the login typed is not kept');
        self::assertStringNotContainsString(CareEngine::RETRY_PROMISE, (string) $site?->lastError);
        self::assertStringContainsString('not retried, and the login is not kept', end($this->storage->log)->detail);
    }

    public function testATypedAddressReplacesTheDefaultOneWhenTheSiteConnects(): void
    {
        $outcome = $this->engine(false)->connect(68, new Login('owner', 'Secret1'), 'client:540', 'https://shop.clientsite.example');

        self::assertTrue($outcome->ok);
        self::assertSame(['installPlugin https://shop.clientsite.example owner'], $this->site->calls);
        self::assertSame('https://shop.clientsite.example', $this->storage->site(68)?->siteUrl);
    }

    public function testATypedAddressIsKeptWhenTheConnectFails(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached at that address.');

        $this->engine(false)->connect(68, new Login('owner', 'Secret1'), 'client:540', 'https://shop.clientsite.example');

        self::assertSame('https://shop.clientsite.example', $this->storage->site(68)?->siteUrl, 'the retry goes to the address typed');
    }

    public function testAFailedSiteCanBeConnectedAgainAndLeavesTheRetryList(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::LOGIN_REFUSED, 'The username or password was not accepted.');
        $this->engine(false)->connect(68, new Login('owner', 'wrong'), 'client:540');
        $this->site->refuses = null;

        $outcome = $this->engine(false)->connect(68, new Login('owner', 'right'), 'client:540');

        self::assertTrue($outcome->ok);
        $site = $this->storage->site(68);
        self::assertSame([State::Connected, null, null], [$site?->state, $site?->retryAfter, $site?->lastError]);
        self::assertSame('right', end($this->dashboard->added)->password);
    }

    public function testAConnectedSiteIsNotConnectedAgain(): void
    {
        $this->engine(false)->connect(68, new Login('owner', 'Secret1'), 'client:540');
        $this->site->calls = [];

        $outcome = $this->engine(false)->connect(68, new Login('owner', 'Other1'), 'client:540');

        self::assertFalse($outcome->ok);
        self::assertSame('Add-on 68 is already connected.', $outcome->message);
        self::assertSame([], $this->site->calls);
        self::assertCount(1, $this->dashboard->added);
    }

    public function testInDryRunATypedAddressIsNamedAndNothingIsStored(): void
    {
        $outcome = $this->engine(true)->connect(68, new Login('owner', 'Secret1'), 'client:540', 'https://shop.clientsite.example');

        self::assertTrue($outcome->ok);
        self::assertSame('Dry run: would install MainWP\'s plugin on https://shop.clientsite.example as owner and connect it to the dashboard.', $outcome->message);
        self::assertSame('https://clientsite.example', $this->storage->site(68)?->siteUrl);
        self::assertSame([[], [], []], [$this->site->calls, $this->dashboard->calls, $this->storage->logins]);
    }

    public function testARetryConnectsWithTheStoredLoginAndCountsTheAttempt(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::LIMITER, 'The site has locked out logins from our server for a while.');
        $this->engine(false)->connect(68, new Login('owner', 'Secret1'), 'client:540');
        $this->site->refuses = null;
        $this->site->calls = [];

        $outcome = $this->engine(false)->retryConnect(68, 'cron:daily');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['installPlugin https://clientsite.example owner'], $this->site->calls);
        self::assertSame('Secret1', $this->dashboard->added[0]->password);
        self::assertSame(State::Connected, $this->storage->site(68)?->state);
    }

    public function testAFailedRetryCountsUpAndStaysOnTheRetryList(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->engine(false)->connect(68, new Login('owner', 'Secret1'), 'client:540');

        $this->engine(false)->retryConnect(68, 'cron:daily');

        $site = $this->storage->site(68);
        self::assertSame([State::Failed, 1, '2026-11-10 14:00:00'], [$site?->state, $site?->retryCount, $site?->retryAfter]);
    }

    public function testARetryWithoutAStoredLoginSaysSoAndSendsNothing(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->engine(false)->connect(68, new Login('owner', 'Secret1'), 'client:540');
        $this->storage->deleteLogin(68);
        $this->site->calls = [];

        $outcome = $this->engine(false)->retryConnect(68, 'cron:daily');

        self::assertFalse($outcome->ok);
        self::assertSame('Add-on 68 has no stored login to retry with.', $outcome->message);
        self::assertSame([], $this->site->calls);
    }
}
