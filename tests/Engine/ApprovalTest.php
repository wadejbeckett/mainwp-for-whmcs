<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\CareEngine;
use MainWpForWhmcs\Engine\Client;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\SiteFailure;
use MainWpForWhmcs\Engine\SiteIndex;
use MainWpForWhmcs\Engine\State;

require_once __DIR__ . '/Fakes.php';

/**
 * The key route up to the moment the client is sent to their own site: which route is offered,
 * and the approval the module then waits for.
 */
final class ApprovalTest extends TestCase
{
    private const SITE = 'https://clientsite.example';
    private const SUCCESS = 'https://billing.example.com/index.php?m=mainwp&care_return={token}';
    private const REJECT = 'https://billing.example.com/index.php?m=mainwp&care_return={token}&care_rejected=1';

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
        $this->storage->log = [];
    }

    private function engine(bool $dryRun = false, string $careEmail = 'wordpress@care.example.com'): CareEngine
    {
        return new CareEngine($this->dashboard, $this->site, $this->storage, new FixedClock(), $dryRun, $this->secrets, $careEmail);
    }

    public function testTheKeyRouteIsOfferedWhenTheSitesIndexShowsAnApprovalScreen(): void
    {
        $offer = $this->engine()->routeOffer(68);

        self::assertTrue($offer->keyRoute);
        self::assertSame('', $offer->reason);
        self::assertFalse($offer->wordfence);
        self::assertSame(['index ' . self::SITE], $this->site->calls);
    }

    public function testWithoutAnApprovalScreenThePasswordRouteIsOfferedWithTheReason(): void
    {
        $this->site->index = new SiteIndex(self::SITE . '/wp-json/', null);

        $offer = $this->engine()->routeOffer(68);

        self::assertFalse($offer->keyRoute);
        self::assertStringContainsString('Application passwords are switched off on your site', $offer->reason);
        self::assertStringContainsString('username and password', $offer->reason);
    }

    public function testWhereWordfenceSwitchedApplicationPasswordsOffTheReasonNamesWordfence(): void
    {
        $this->site->index = new SiteIndex(self::SITE . '/wp-json/', null, true);

        $offer = $this->engine()->routeOffer(68);

        self::assertFalse($offer->keyRoute);
        self::assertTrue($offer->wordfence);
        self::assertStringContainsString('Wordfence', $offer->reason);
    }

    public function testWordfenceWithApplicationPasswordsOnIsOfferedTheKeyRouteAndFlagged(): void
    {
        $this->site->index = new SiteIndex(self::SITE . '/wp-json/', self::SITE . '/wp-admin/authorize-application.php', true);

        $offer = $this->engine()->routeOffer(68);

        self::assertTrue($offer->keyRoute);
        self::assertTrue($offer->wordfence, 'the client is told what alert to expect');
    }

    public function testASiteThatCannotBeReadIsOfferedThePasswordRouteWithTheSitesReason(): void
    {
        $this->site->index = new SiteFailure(SiteFailure::NOT_HTTPS, 'The site address must be an https:// address: the login is never sent over plain HTTP.');

        $offer = $this->engine()->routeOffer(68);

        self::assertFalse($offer->keyRoute);
        self::assertSame('The site address must be an https:// address: the login is never sent over plain HTTP.', $offer->reason);
    }

    public function testASiteThatMovedToAnotherHostIsOfferedWithTheAddressToUse(): void
    {
        $this->site->index = new SiteFailure(SiteFailure::MOVED, 'The site at clientsite.example sends visitors on to www.clientsite.example.', 'https://www.clientsite.example');

        $offer = $this->engine()->routeOffer(68);

        self::assertFalse($offer->keyRoute);
        self::assertSame('https://www.clientsite.example', $offer->movedTo);
        self::assertStringContainsString('sends visitors on to www.clientsite.example', $offer->reason);
    }

    public function testATypedAddressIsTheOneWhoseIndexIsRead(): void
    {
        $this->engine()->routeOffer(68, 'https://shop.clientsite.example');

        self::assertSame(['index https://shop.clientsite.example'], $this->site->calls);
        self::assertSame(self::SITE, $this->storage->site(68)?->siteUrl, 'looking changes nothing');
    }

    public function testWithoutAnEmailDomainForCareAccountsTheKeyRouteIsNotOfferedAndTheSiteIsNotAsked(): void
    {
        $offer = $this->engine(false, '')->routeOffer(68);

        self::assertFalse($offer->keyRoute);
        self::assertStringContainsString('not available at the moment', $offer->reason);
        self::assertSame([], $this->site->calls);
    }

    public function testInDryRunToTheKeyRouteIsNotOfferedWhereLiveWouldNotOfferItEither(): void
    {
        $offer = $this->engine(true, '')->routeOffer(68);

        self::assertFalse($offer->keyRoute, 'the test client must see in dry-run what a client will see live');
        self::assertSame([], $this->site->calls);
    }

    public function testInDryRunTheOfferIsMadeWithoutAskingTheSite(): void
    {
        $offer = $this->engine(true)->routeOffer(68);

        self::assertTrue($offer->keyRoute);
        self::assertSame([], $this->site->calls, 'nothing is sent to a client\'s site in dry-run');
    }

    public function testStartingAnApprovalSendsTheClientToTheirOwnSitesApprovalScreenWithAOneTimeToken(): void
    {
        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540');

        $token = $this->secrets->tokens[0];
        self::assertTrue($start->ok, $start->message);
        self::assertNotNull($start->url);
        self::assertStringStartsWith(self::SITE . '/wp-admin/authorize-application.php?', (string) $start->url);
        parse_str((string) parse_url((string) $start->url, PHP_URL_QUERY), $query);
        self::assertSame([
            'app_name' => 'WordPress Care (2026-11-09 14:00:00)',
            'app_id' => CareEngine::APP_ID,
            'success_url' => 'https://billing.example.com/index.php?m=mainwp&care_return=' . $token,
            'reject_url' => 'https://billing.example.com/index.php?m=mainwp&care_return=' . $token . '&care_rejected=1',
        ], $query);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', CareEngine::APP_ID, 'WordPress refuses an app_id that is not a UUID');

        $approval = $this->storage->approval(hash('sha256', $token));
        self::assertNotNull($approval, 'only the token\'s hash is kept');
        self::assertArrayNotHasKey($token, $this->storage->approvals);
        self::assertSame([68, '2026-11-10 14:00:00', false], [$approval->addonRowId, $approval->expiresAt, $approval->used], 'it lapses after 24 hours');
        self::assertSame(State::Connecting, $this->storage->site(68)?->state);
        self::assertSame(['lock 68', 'unlock 68'], $this->storage->locks, 'one command at a time works on an add-on');

        $entry = end($this->storage->log);
        self::assertSame(['approve', 'add-on 68', 'started', 'client:540'], [$entry->action, $entry->target, $entry->outcome, $entry->actor]);
        self::assertStringNotContainsString($token, json_encode(array_map(static fn ($e): array => (array) $e, $this->storage->log), JSON_THROW_ON_ERROR), 'the token is never logged');
    }

    public function testStartingAgainMakesTheEarlierLinkLapseButKeepsItSoALateReturnIsRecognised(): void
    {
        $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540');
        $second = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540');

        self::assertTrue($second->ok);
        $first = $this->storage->approval(hash('sha256', $this->secrets->tokens[0]));
        self::assertNotNull($first);
        self::assertTrue($first->lapsed('2026-11-09 14:00:00'), 'the first link no longer works');
        self::assertFalse($this->storage->approval(hash('sha256', $this->secrets->tokens[1]))?->lapsed('2026-11-09 14:00:00'));
        self::assertSame(State::Connecting, $this->storage->site(68)?->state);
    }

    public function testStartingAfterAFailedPasswordAttemptLeavesTheRetryListAndKeepsThatLoginUntilAKeyArrives(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::CAPTCHA, 'The login page has a captcha.');
        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');
        $this->site->refuses = null;

        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540');

        self::assertTrue($start->ok, $start->message);
        $site = $this->storage->site(68);
        self::assertSame([State::Connecting, null, null], [$site?->state, $site?->retryAfter, $site?->lastError]);
        self::assertSame('Secret1', $this->storage->logins[68]->password, 'an approval the client never gives must not cost the earlier attempt its retry');
    }

    public function testAnAddonAnotherRequestIsWorkingOnIsNotStarted(): void
    {
        $this->storage->locked = [68];

        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540');

        self::assertFalse($start->ok);
        self::assertStringContainsString('being connected right now', $start->message);
        self::assertSame([[], []], [$this->site->calls, $this->storage->approvals]);
    }

    public function testATypedAddressIsKeptForTheApproval(): void
    {
        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540', 'https://shop.clientsite.example');

        self::assertStringStartsWith('https://shop.clientsite.example/wp-admin/authorize-application.php?', (string) $start->url);
        self::assertSame('https://shop.clientsite.example', $this->storage->site(68)?->siteUrl);
    }

    public function testAnAddressThatOnlyRedirectsIsNotStartedAndSaysWhereItLeads(): void
    {
        $this->site->index = new SiteFailure(SiteFailure::MOVED, 'The site at clientsite.example sends visitors on to www.clientsite.example.', 'https://www.clientsite.example');

        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540');

        self::assertFalse($start->ok);
        self::assertSame('https://www.clientsite.example', $start->movedTo);
        self::assertSame(State::Provisioned, $this->storage->site(68)?->state);
    }

    public function testAnAddressThatIsNotTheSitesOwnIsNotOfferedOrStartedAndNothingIsStored(): void
    {
        // A folder with no WordPress of its own reads the main site's index.
        $this->site->index = new SiteIndex(self::SITE . '/wp-json/', self::SITE . '/wp-admin/authorize-application.php', false, self::SITE, self::SITE);

        $offer = $this->engine()->routeOffer(68, self::SITE . '/nothing-here');
        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540', self::SITE . '/nothing-here');

        self::assertSame([false, self::SITE], [$offer->keyRoute, $offer->answersAt]);
        self::assertSame([false, null, self::SITE], [$start->ok, $start->url, $start->answersAt]);
        self::assertSame([self::SITE, State::Provisioned], [$this->storage->site(68)?->siteUrl, $this->storage->site(68)?->state]);
        self::assertSame([[], []], [$this->storage->approvals, $this->storage->log]);
    }

    public function testWhereTheSiteAnswersIsAskedOfItsIndexAndNotInDryRun(): void
    {
        $this->site->index = new SiteIndex(self::SITE . '/wp-json/', null, false, self::SITE . '/blog');

        self::assertNull($this->engine()->answersElsewhere(self::SITE . '/blog'), 'a WordPress in a folder is its own site');
        self::assertSame(self::SITE . '/blog', $this->engine()->answersElsewhere(self::SITE));
        $this->site->index = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        self::assertNull($this->engine()->answersElsewhere(self::SITE), 'an index that cannot be read leaves the connect to find out what is wrong');
        $this->site->calls = [];
        self::assertNull($this->engine(true)->answersElsewhere(self::SITE));
        self::assertSame([], $this->site->calls, 'in dry-run the site is not asked');
    }

    public function testASiteThatOffersNoApprovalScreenIsNotStarted(): void
    {
        $this->site->index = new SiteIndex(self::SITE . '/wp-json/', null, true);

        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540');

        self::assertFalse($start->ok);
        self::assertNull($start->url);
        self::assertStringContainsString('Wordfence', $start->message);
        self::assertSame(State::Provisioned, $this->storage->site(68)?->state);
        self::assertSame([], $this->storage->approvals);
    }

    public function testAReturnAddressOverPlainHttpIsRefusedBeforeTheSiteIsAsked(): void
    {
        $start = $this->engine()->startApproval(68, 'http://billing.example.com/index.php?m=mainwp&care_return={token}', self::REJECT, 'client:540');

        self::assertFalse($start->ok);
        self::assertSame([], $this->site->calls);
        self::assertSame([], $this->storage->approvals);
        self::assertSame(State::Provisioned, $this->storage->site(68)?->state);
    }

    public function testASiteThatIsConnectedOrNotProvisionedIsNotStarted(): void
    {
        self::assertFalse($this->engine()->startApproval(99, self::SUCCESS, self::REJECT, 'client:540')->ok, 'no care site');

        $this->engine()->connect(68, new Login('owner', 'Secret1'), 'client:540');
        $this->site->calls = [];
        $start = $this->engine()->startApproval(68, self::SUCCESS, self::REJECT, 'client:540');

        self::assertFalse($start->ok);
        self::assertSame('Add-on 68 is already connected.', $start->message);
        self::assertSame([], $this->site->calls);
    }

    public function testInDryRunNothingIsSentStoredOrHandedOut(): void
    {
        $start = $this->engine(true)->startApproval(68, self::SUCCESS, self::REJECT, 'client:540');

        self::assertTrue($start->ok);
        self::assertNull($start->url, 'no client is sent to approve a key the module would not then delete');
        self::assertStringStartsWith('Dry run: would ', $start->message);
        self::assertSame([[], [], []], [$this->site->calls, $this->storage->approvals, $this->secrets->tokens]);
        self::assertSame(State::Provisioned, $this->storage->site(68)?->state);
        $entry = end($this->storage->log);
        self::assertSame(['approve', 'would run', true], [$entry->action, $entry->outcome, $entry->dryRun]);
    }
}
