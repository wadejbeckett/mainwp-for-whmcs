<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\Admin\SitesList;
use MainWpForWhmcs\Daily;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Settings;
use MainWpForWhmcs\Tests\Engine\RecordingSite;
use MainWpForWhmcs\Tests\Support\FixtureHttp;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

require_once __DIR__ . '/Engine/Fakes.php';

/**
 * The one digest a day never two emails on one calendar day, the
 * failed Suspend, Unsuspend and Terminate commands WHMCS keeps in its Module Queue, a closing link
 * to that page, and the Daily Digest setting (Off, When something is new, Every day).
 */
final class DigestTest extends TestCase
{
    private const NIGHT = '2026-11-16 00:00:10';
    private const NOON = '2026-11-16 12:00:00';
    private const NEXT_NIGHT = '2026-11-17 00:00:10';

    private MemoryRepository $repo;
    private FixtureHttp $http;

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        $this->repo->settings = ['dry_run' => '', 'dashboard_url' => 'https://dash.example.com', 'api_key' => (new WhmcsEncrypter())->encrypt('bench-key-0123456789')];
        $this->repo->addonModules = [16 => 'mainwp'];
        $this->http = new FixtureHttp();
        $site = new RecordingSite();
        $repo = $this->repo;
        $http = $this->http;
        Module::$repositoryFactory = static fn (): MemoryRepository => $repo;
        Module::$httpFactory = static fn (): FixtureHttp => $http;
        Module::$siteFactory = static fn (): RecordingSite => $site;
        WhmcsCalls::$api = [];
    }

    protected function tearDown(): void
    {
        Module::$repositoryFactory = null;
        Module::$httpFactory = null;
        Module::$siteFactory = null;
    }

    private function addon(int $id, string $status = 'Active', int $addonId = 16, bool $free = false): void
    {
        $this->repo->addons[$id] = new AddonRow($id, 1100 + $id, 540, $addonId, $status, 'Active', 'site' . $id . '.example', 'Demo Client', 'demo-client@example.com', 0, $free);
    }

    /** A care site waiting for the client: a line on every run. */
    private function waiting(int $id): void
    {
        $this->addon($id);
        $this->repo->careSites[$id] = [
            'addon_row_id' => $id, 'service_id' => 1100 + $id, 'client_id' => 540, 'site_url' => 'https://site' . $id . '.example', 'dashboard_client_id' => 1,
            'dashboard_site_id' => null, 'state' => 'provisioned', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null, 'retry_after' => null,
        ];
    }

    /** A row of WHMCS's tblmodulequeue, with the columns and words read on WHMCS 9.1. */
    private function queued(int $id, int $serviceId, string $action = 'SuspendAccount', int $retries = 2, string $error = 'The dashboard at dash.example.com could not be reached.', array $more = []): void
    {
        $this->repo->moduleQueue[] = $more + [
            'id' => $id, 'service_type' => 'addon', 'service_id' => $serviceId, 'module_name' => 'mainwp', 'module_action' => $action,
            'last_attempt' => '2026-11-15 00:05:12', 'last_attempt_error' => $error, 'num_retries' => $retries, 'completed' => 0,
            'created_at' => '2026-11-14 00:05:00', 'updated_at' => '2026-11-15 00:05:12',
        ];
    }

    private function night(string $at, bool $mayEmail = true): array
    {
        $this->http->on('GET', 'sites?page=1&per_page=20', 'sites-list-empty')->on('GET', 'clients?page=1&per_page=100', 'clients-list', 'clients-list', 'clients-list');
        return Daily::run($this->repo, $mayEmail ? 'cron:daily' : 'admin:1', $mayEmail, $at);
    }

    /** @return list<array{command: string, values: array<string, mixed>}> */
    private function emails(): array
    {
        return array_values(array_filter(WhmcsCalls::$api, static fn (array $call): bool => $call['command'] === 'SendAdminEmail'));
    }

    private function lastEmailBody(): string
    {
        $emails = $this->emails();
        return (string) end($emails)['values']['custommessage'];
    }

    // Never two emails on one calendar day

    public function testASecondNightlyRunOnTheSameDaySendsNothingAndKeepsWhatIsNewForTheNextDay(): void
    {
        $this->waiting(70);
        $this->night(self::NIGHT);
        self::assertCount(1, $this->emails());
        self::assertSame('2026-11-16', $this->repo->settings['report_sent_on'] ?? null, 'the date of the email WHMCS accepted, from the run\'s own time');

        // WHMCS's cron runs its daily tasks a second time that day, and a new site waits.
        $this->waiting(69);
        $second = $this->night(self::NOON);
        self::assertCount(1, $this->emails(), 'never two on one day');
        self::assertSame('not sent: one was already sent today', $second['email']);
        $row = $this->repo->lastLogFor('daily check', 'daily digest');
        self::assertSame('not sent: one was already sent today', $row['detail'] ?? null);
        self::assertSame([69 => true, 70 => false], array_column($second['lines'], 'new', 'addon'));

        // The next night it is still new, and told.
        $third = $this->night(self::NEXT_NIGHT);
        $emails = $this->emails();
        self::assertCount(2, $emails);
        self::assertSame('MainWP for WHMCS: 1 new of 2 to report', $emails[1]['values']['customsubject']);
        self::assertSame([69 => true, 70 => false], array_column($third['lines'], 'new', 'addon'));
        self::assertSame('2026-11-17', $this->repo->settings['report_sent_on']);
        self::assertSame([], $this->repo->locks, 'the lock is let go');
    }

    /**
     * Code review of the digest, 16 November 2026: two nightly runs at once. Run A sends while run
     * B, which read the stored result before A sent, is still checking. B may not send, and it must
     * not write back the baseline it read at its start over the one A saved after its email.
     */
    public function testARunThatMayNotSendKeepsTheBaselineAnotherRunSavedWhileItRan(): void
    {
        $this->waiting(70);
        $this->night(self::NIGHT);                                     // run A, as it ends
        $afterA = $this->repo->settings;
        self::assertCount(1, $this->emails());

        // Run B began before A sent: it reads the row as it was before A.
        unset($this->repo->settings['last_check'], $this->repo->settings['report_sent_on']);
        $this->repo->onLock = function (string $name) use ($afterA): void {
            if ($name === 'report') {                                  // A sent and saved while B's check ran
                $this->repo->settings['last_check'] = $afterA['last_check'];
                $this->repo->settings['report_sent_on'] = $afterA['report_sent_on'];
                $this->repo->onLock = null;
            }
        };
        $b = $this->night(self::NOON);
        self::assertSame('not sent: one was already sent today', $b['email']);
        self::assertCount(1, $this->emails());

        // The next night what A told is not new: nothing is sent on When something is new.
        $next = $this->night(self::NEXT_NIGHT);
        self::assertSame([false], array_column($next['lines'], 'new'));
        self::assertCount(1, $this->emails());
    }

    public function testWhileAnotherRunHoldsTheReportLockNothingIsSentAndNoDayIsUsedUp(): void
    {
        $this->waiting(70);
        $this->repo->lockedElsewhere = ['report'];
        $first = $this->night(self::NIGHT);
        self::assertSame([], $this->emails());
        self::assertSame('not sent: another run is sending today\'s digest', $first['email'], 'whether that run\'s email goes out is not known here, so the words do not say that one was sent');
        self::assertArrayNotHasKey('report_sent_on', $this->repo->settings);

        $this->repo->lockedElsewhere = [];
        $second = $this->night(self::NOON);
        self::assertCount(1, $this->emails(), 'what was not told is told by the next run');
        self::assertSame([true], array_column($second['lines'], 'new'));
    }

    public function testAFailedSendWritesNoDateAndTheNextRunThatDaySends(): void
    {
        $this->waiting(70);
        WhmcsCalls::$apiErrors['SendAdminEmail'] = 'Email Send Aborted By Hook';
        self::assertSame('not sent: Email Send Aborted By Hook', $this->night(self::NIGHT)['email']);
        self::assertArrayNotHasKey('report_sent_on', $this->repo->settings);
        self::assertSame([], $this->repo->locks);

        // No administrator to receive it: no date either.
        WhmcsCalls::$apiErrors = [];
        WhmcsCalls::$api = [];
        $this->repo->systemEmailAdmins = [];
        $this->night('2026-11-16 06:00:00');
        self::assertArrayNotHasKey('report_sent_on', $this->repo->settings);

        $this->repo->systemEmailAdmins = ['Ada Admin'];
        $third = $this->night(self::NOON);
        self::assertSame('sent to Ada Admin', $third['email']);
        self::assertSame([true], array_column($third['lines'], 'new'), 'nobody was told, so it is still new');
        self::assertSame('2026-11-16', $this->repo->settings['report_sent_on']);
    }

    public function testCheckNowSendsNothingUsesUpNoDayAndMovesNothing(): void
    {
        $this->repo->settings['report_by_email'] = 'daily';
        $this->waiting(70);
        $this->queued(1, 70);
        $page = $this->night(self::NIGHT, false);
        self::assertSame([], $this->emails());
        self::assertArrayNotHasKey('report_sent_on', $this->repo->settings);
        self::assertSame([true, true], array_column($page['lines'], 'new'));
        self::assertStringStartsWith('Suspend failed: ', (string) Daily::last($this->repo)['lines'][0]['problem'], 'the stored result holds the failed command for the Sites page');

        $night = $this->night(self::NEXT_NIGHT);
        self::assertSame([true, true], array_column($night['lines'], 'new'));
        self::assertCount(1, $this->emails());
    }

    // Failed commands from WHMCS's Module Queue

    public function testAnOpenFailedSuspendOfACareAddonIsOneLineFirstUnderNewThenFirstUnderStillOpen(): void
    {
        $this->waiting(69);
        $this->addon(72, 'Suspended');                                // no care site: the check itself has nothing to say of it
        $this->queued(1, 72, 'SuspendAccount', 2);

        $first = $this->night(self::NIGHT);

        self::assertSame('Demo Client (client 540), https://site72.example (Suspended in WHMCS): Suspend failed: The dashboard at dash.example.com could not be reached. Tried 3 times, last on 2026-11-15.', $first['lines'][0]['text']);
        self::assertSame([72, 69], array_column($first['lines'], 'addon'));
        $body = $this->lastEmailBody();
        self::assertLessThan(strpos($body, 'site69.example'), strpos($body, 'Suspend failed'), 'first under New');

        // The next night another site waits: the failed command is first under Still open.
        $this->waiting(68);
        $second = $this->night(self::NEXT_NIGHT);
        self::assertSame([68, 72, 69], array_column($second['lines'], 'addon'));
        self::assertSame([true, false, false], array_column($second['lines'], 'new'));
        $body = $this->lastEmailBody();
        $stillOpen = (int) strpos($body, 'Still open');
        self::assertGreaterThan(0, $stillOpen);
        self::assertLessThan(strpos($body, 'site69.example'), strpos($body, 'Suspend failed'));
        self::assertGreaterThan($stillOpen, strpos($body, 'Suspend failed'));
    }

    public function testEachCommandIsNamedByWhmcssOwnButtonWordAndIsItsOwnFinding(): void
    {
        $this->addon(70, 'Suspended');
        $this->addon(71, 'Terminated');
        $this->queued(1, 70, 'UnsuspendAccount', 0, "  The dashboard   refused\nthe <b>API key</b>.  ");
        $this->queued(2, 71, 'TerminateAccount', 4, '');

        $result = $this->night(self::NIGHT);

        self::assertSame([
            'Demo Client (client 540), https://site70.example (Suspended in WHMCS): Unsuspend failed: The dashboard refused the API key. Tried 1 time, last on 2026-11-15.',
            'Demo Client (client 540), https://site71.example (Terminated in WHMCS): Terminate failed. Tried 5 times, last on 2026-11-15.',
        ], array_column($result['lines'], 'text'));

        // The Unsuspend is retried and works; the next time care is suspended that fails too: a new finding.
        $this->repo->moduleQueue[0]['completed'] = 1;
        $this->queued(3, 70, 'SuspendAccount', 0);
        $next = $this->night(self::NEXT_NIGHT);
        self::assertSame([70 => true, 71 => false], array_column($next['lines'], 'new', 'addon'));
        self::assertStringContainsString('Suspend failed', (string) $next['lines'][0]['text']);
    }

    /** Code review, 16 November 2026: WHMCS may write its own error text there, with a file path in it. */
    public function testAFilePathInWhmcssErrorTextIsNotShown(): void
    {
        $this->addon(72, 'Suspended');
        $this->queued(1, 72, 'SuspendAccount', 0, 'Function not found in /home/x/public_html/modules/servers/mainwp/mainwp.php:40');
        $result = $this->night(self::NIGHT);
        self::assertSame('Demo Client (client 540), https://site72.example (Suspended in WHMCS): Suspend failed: Function not found in (path). Tried 1 time, last on 2026-11-15.', $result['lines'][0]['text']);
        self::assertStringNotContainsString('public_html', $this->lastEmailBody());
    }

    /** Code review, 16 November 2026: the Module Queue is one more source; a fault in reading it costs one line, not the night's check. */
    public function testAModuleQueueThatCannotBeReadIsOneLineAndTheCheckGoesOn(): void
    {
        $this->waiting(70);
        $this->repo->moduleQueueError = new \RuntimeException("SQLSTATE[42S02]: Table 'whmcs.tblmodulequeue' doesn't exist in /var/www/whmcs/vendor/illuminate/database/Connection.php:671");

        $result = $this->night(self::NIGHT);

        self::assertSame("Module Queue (in WHMCS): could not be read, so no failed command is listed: SQLSTATE[42S02]: Table 'whmcs.tblmodulequeue' doesn't exist in (path)", $result['lines'][0]['text']);
        self::assertSame([null, 70], array_column($result['lines'], 'addon'));
        self::assertCount(1, $this->emails());
        self::assertStringNotContainsString('/var/www', $this->lastEmailBody());
    }

    public function testACompletedOrVanishedQueueRowMakesNoLine(): void
    {
        $this->addon(70, 'Suspended');
        $this->addon(71, 'Suspended');
        $this->queued(1, 70, 'SuspendAccount', 0, 'x', ['completed' => 1]);
        $this->queued(2, 71);
        self::assertSame([71], array_column($this->night(self::NIGHT)['lines'], 'addon'));

        $this->repo->moduleQueue = [];
        self::assertSame([], $this->night(self::NEXT_NIGHT)['lines']);
    }

    public function testQueueRowsOfAnotherModuleAProductACreateInAnyLetterCaseOrAnAddonThatIsNotCareMakeNoLine(): void
    {
        $this->addon(70, 'Suspended');
        $this->repo->settings['care_addon_ids'] = '16,7';             // the old stored list is not read
        $this->addon(90, 'Active', 7);                                // another product add-on, whose module is not this one
        $this->queued(1, 70, 'SuspendAccount', 0, 'x', ['module_name' => 'cpanel']);
        $this->queued(2, 70, 'SuspendAccount', 0, 'x', ['service_type' => 'service']);
        $this->queued(3, 70, 'CreateAccount');
        $this->queued(4, 70, 'createaccount');
        $this->queued(5, 90);
        $this->queued(6, 999);                                         // an add-on row WHMCS no longer holds

        self::assertSame([], $this->night(self::NIGHT)['lines']);
        self::assertSame([], $this->emails());
    }

    public function testInDryRunFailedCommandsAreListedTooAndTheEmailIsMarked(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->addon(70);
        $this->repo->careSites[70] = ['addon_row_id' => 70, 'service_id' => 1170, 'client_id' => 540, 'site_url' => 'https://www.site70.example', 'dashboard_client_id' => 1, 'dashboard_site_id' => 1, 'state' => 'connected', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null, 'retry_after' => null];
        $this->queued(1, 70);

        $result = $this->night(self::NIGHT);

        self::assertStringStartsWith('Demo Client (client 540), https://www.site70.example (Active in WHMCS): Suspend failed', (string) $result['lines'][0]['text'], 'the site as its care site names it');
        self::assertSame('MainWP for WHMCS (dry run): 1 new of 1 to report', $this->emails()[0]['values']['customsubject']);
    }

    public function testTheSitesPageShowsAFailedCommandBesideTheSitesOtherFinding(): void
    {
        $this->waiting(70);
        $this->queued(1, 70);
        $this->night(self::NIGHT, false);

        $rows = SitesList::rows($this->repo, Settings::load($this->repo));

        self::assertCount(1, $rows);
        self::assertSame('Suspend failed: The dashboard at dash.example.com could not be reached. Tried 3 times, last on 2026-11-15. Waiting for the client to connect the site.', $rows[0]['issue']);
    }

    /** Ticket 12, "Found by the check after the install": a row under another action word is listed too, with the word as WHMCS stored it. */
    public function testAnOpenRowUnderAnUnknownActionWordMakesALineThatShowsTheWordAsStored(): void
    {
        $this->addon(70, 'Suspended');
        $this->addon(71, 'Suspended');
        $this->addon(72, 'Suspended');
        $this->queued(1, 70, 'ChangePackage', 0);
        $this->queued(2, 71, " <i>Custom</i>\n  Renew /home/x/public_html/a.php ", 0, '');
        $this->queued(3, 72, '<b></b>', 0, '');

        $result = $this->night(self::NIGHT);

        self::assertSame([
            'Demo Client (client 540), https://site70.example (Suspended in WHMCS): ChangePackage failed: The dashboard at dash.example.com could not be reached. Tried 1 time, last on 2026-11-15.',
            'Demo Client (client 540), https://site71.example (Suspended in WHMCS): Custom Renew (path) failed. Tried 1 time, last on 2026-11-15.',
            'Demo Client (client 540), https://site72.example (Suspended in WHMCS): Command failed. Tried 1 time, last on 2026-11-15.',
        ], array_column($result['lines'], 'text'));
        self::assertStringContainsString('modulequeue.php', $this->lastEmailBody(), 'a failed command under any word gets the link to the Module Queue');
    }

    public function testAnUnknownActionWordIsCutToFortyCharacters(): void
    {
        $this->addon(70);
        $this->queued(1, 70, str_repeat('Ä', 30) . str_repeat('b', 20), 0, '');
        $result = $this->night(self::NIGHT);
        self::assertSame('Demo Client (client 540), https://site70.example (Active in WHMCS): ' . str_repeat('Ä', 30) . str_repeat('b', 10) . ' failed. Tried 1 time, last on 2026-11-15.', $result['lines'][0]['text']);
    }

    public function testAnUnknownActionWordCutJustAfterASpaceLeavesNoSpaceBeforeFailed(): void
    {
        $this->addon(70);
        $this->queued(1, 70, str_repeat('a', 39) . ' bcdef', 0, '');
        $result = $this->night(self::NIGHT);
        self::assertSame('Demo Client (client 540), https://site70.example (Active in WHMCS): ' . str_repeat('a', 39) . ' failed. Tried 1 time, last on 2026-11-15.', $result['lines'][0]['text']);
    }

    public function testAnOpenRowUnderAKnownWordInAnotherLetterCaseKeepsWhmcssButtonWord(): void
    {
        $this->addon(70, 'Suspended');
        $this->addon(71, 'Suspended');
        $this->addon(72, 'Terminated');
        $this->queued(1, 70, 'suspendaccount', 0, '');
        $this->queued(2, 71, 'SUSPENDACCOUNT', 0, '');
        $this->queued(3, 72, 'terminateAccount', 0, '');

        $result = $this->night(self::NIGHT);

        self::assertSame([
            'Demo Client (client 540), https://site70.example (Suspended in WHMCS): Suspend failed. Tried 1 time, last on 2026-11-15.',
            'Demo Client (client 540), https://site71.example (Suspended in WHMCS): Suspend failed. Tried 1 time, last on 2026-11-15.',
            'Demo Client (client 540), https://site72.example (Terminated in WHMCS): Terminate failed. Tried 1 time, last on 2026-11-15.',
        ], array_column($result['lines'], 'text'));
    }

    // A care add-on set to free in WHMCS

    public function testAnActiveFreeCareAddonIsOneLineAfterTheFailedCommandsFirstUnderNewThenUnderStillOpen(): void
    {
        $this->waiting(69);
        $this->addon(72, 'Suspended');
        $this->queued(1, 72);
        $this->waiting(70);
        $this->addon(70, 'Active', 16, true);

        $first = $this->night(self::NIGHT);

        self::assertSame([72, 70, 69, 70], array_column($first['lines'], 'addon'), 'after the failed commands, before the other lines');
        self::assertSame('Demo Client (client 540), https://site70.example (Provisioned): set to free in WHMCS', $first['lines'][1]['text']);
        self::assertSame('free:add-on 70', $first['lines'][1]['key']);
        self::assertSame([true, true, true, true], array_column($first['lines'], 'new'));
        self::assertStringContainsString('<li>Demo Client (client 540), https://site70.example (Provisioned): set to free in WHMCS (add-on 70)</li>', $this->lastEmailBody());

        $second = $this->night(self::NEXT_NIGHT);
        self::assertSame('Demo Client (client 540), https://site70.example (Provisioned): set to free in WHMCS', $second['lines'][1]['text']);
        self::assertSame([false, false, false, false], array_column($second['lines'], 'new'), 'still open, and told no more on When something is new');
        self::assertCount(1, $this->emails());
    }

    public function testAFreeCareAddonWithoutACareSiteOrWithARemovedOneIsActiveInWhmcs(): void
    {
        $this->addon(70, 'Active', 16, true);
        $this->addon(71, 'Active', 16, true);
        $this->repo->careSites[71] = [
            'addon_row_id' => 71, 'service_id' => 1171, 'client_id' => 540, 'site_url' => 'https://www.site71.example', 'dashboard_client_id' => 1,
            'dashboard_site_id' => null, 'state' => 'removed', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null, 'retry_after' => null,
        ];
        $this->repo->settings['dry_run'] = 'on';                    // nothing is provisioned, so 70 keeps no care site

        $result = $this->night(self::NIGHT);
        $free = array_values(array_filter($result['lines'], static fn (array $line): bool => str_starts_with((string) $line['key'], 'free:')));

        self::assertSame([
            'Demo Client (client 540), https://site70.example (Active in WHMCS): set to free in WHMCS',
            'Demo Client (client 540), https://www.site71.example (Active in WHMCS): set to free in WHMCS',
        ], array_column($free, 'text'));
    }

    public function testAPricedOrNotActiveFreeAddonOrOneThatIsNotCareMakesNoFreeLine(): void
    {
        $this->addon(70);                                              // priced
        $this->addon(90, 'Active', 7, true);                           // free, another product's add-on
        $this->repo->settings['care_addon_ids'] = '16,7';             // the old stored list is not read
        $id = 71;
        foreach (['Pending', 'Suspended', 'Terminated', 'Cancelled', 'Fraud'] as $status) {
            $this->addon($id++, $status, 16, true);
        }
        $this->repo->settings['dry_run'] = 'on';

        $result = $this->night(self::NIGHT);

        self::assertSame([], array_values(array_filter(array_column($result['lines'], 'problem'), static fn (string $problem): bool => str_contains($problem, 'free'))));
        self::assertSame([], array_values(array_filter($result['lines'], static fn (array $line): bool => str_starts_with((string) $line['key'], 'free:'))));
    }

    public function testAnActiveFreeCareAddonOnAHostingServiceThatIsNotActiveIsStillALine(): void
    {
        foreach ([70 => 'Suspended', 71 => 'Terminated'] as $id => $serviceStatus) {
            $this->repo->addons[$id] = new AddonRow($id, 1100 + $id, 540, 16, 'Active', $serviceStatus, 'site' . $id . '.example', 'Demo Client', 'demo-client@example.com', 0, true);
        }
        $this->repo->careSites[70] = [
            'addon_row_id' => 70, 'service_id' => 1170, 'client_id' => 540, 'site_url' => 'https://site70.example', 'dashboard_client_id' => 1,
            'dashboard_site_id' => null, 'state' => 'provisioned', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null, 'retry_after' => null,
        ];
        $this->repo->careSites[71] = ['state' => 'removed', 'site_url' => 'https://site71.example'] + $this->repo->careSites[70];
        $this->repo->careSites[71]['addon_row_id'] = 71;
        $this->repo->settings['dry_run'] = 'on';

        $result = $this->night(self::NIGHT);
        $free = array_values(array_filter($result['lines'], static fn (array $line): bool => str_starts_with((string) $line['key'], 'free:')));

        self::assertSame([
            'Demo Client (client 540), https://site70.example (Provisioned): set to free in WHMCS',
            'Demo Client (client 540), https://site71.example (Active in WHMCS): set to free in WHMCS',
        ], array_column($free, 'text'));
    }

    public function testAFreeCareAddonIsStillProvisionedAndCaredForAsBefore(): void
    {
        $this->addon(70, 'Active', 16, true);

        $result = $this->night(self::NIGHT);

        self::assertSame('provisioned', $this->repo->careSites[70]['state'] ?? null, 'provisioned as a priced one is');
        self::assertSame([
            'Demo Client (client 540), https://site70.example (Provisioned): set to free in WHMCS',
            'Demo Client (client 540), https://site70.example (Provisioned): waiting for the client to connect the site',
        ], array_column($result['lines'], 'text'));
    }

    public function testCheckNowListsTheFreeLineAndSendsNothingAndTheSitesPageShowsItBesideTheOtherFinding(): void
    {
        $this->waiting(70);
        $this->addon(70, 'Active', 16, true);

        $page = $this->night(self::NIGHT, false);

        self::assertSame([], $this->emails());
        self::assertSame('Demo Client (client 540), https://site70.example (Provisioned): set to free in WHMCS', $page['lines'][0]['text']);
        $rows = SitesList::rows($this->repo, Settings::load($this->repo));
        self::assertCount(1, $rows);
        self::assertSame('Set to free in WHMCS. Waiting for the client to connect the site.', $rows[0]['issue']);
    }

    // The closing link

    public function testTheClosingLinkToTheModuleQueueIsThereOnlyWhenAFailedCommandIsListed(): void
    {
        $this->waiting(69);
        $this->night(self::NIGHT);
        self::assertStringNotContainsString('Module Queue', $this->lastEmailBody());

        $this->addon(72, 'Suspended');
        $this->queued(1, 72);
        $this->repo->adminFolder = 'staff-area';
        $this->night(self::NEXT_NIGHT);
        $body = $this->lastEmailBody();
        self::assertStringEndsWith('<p>To retry a failed command, open the Module Queue in WHMCS: <a href="https://billing.example.com/staff-area/modulequeue.php">https://billing.example.com/staff-area/modulequeue.php</a></p>', $body);
        self::assertSame(1, substr_count($body, 'modulequeue.php">'), 'one link, not one a line');
    }

    public function testWithoutASystemUrlThePageIsNamedInWords(): void
    {
        $this->repo->systemUrl = '';
        $this->addon(72, 'Suspended');
        $this->queued(1, 72);
        $this->night(self::NIGHT);
        $body = $this->lastEmailBody();
        self::assertStringEndsWith('<p>To retry a failed command, open Utilities &gt; Module Queue in WHMCS.</p>', $body);
        self::assertStringNotContainsString('modulequeue.php', $body);
    }

    // The Daily Digest setting

    public function testOnEveryDayAQuietNightSendsTheShortEmailAndOnWhenSomethingIsNewNothing(): void
    {
        $this->night(self::NIGHT);
        self::assertSame([], $this->emails(), 'When something is new is the default');

        $this->repo->settings['report_by_email'] = 'daily';
        $this->night(self::NEXT_NIGHT);
        $emails = $this->emails();
        self::assertCount(1, $emails);
        self::assertSame('MainWP for WHMCS: nothing to report', $emails[0]['values']['customsubject']);
        self::assertSame('<p>Nothing to report today.</p>', $emails[0]['values']['custommessage']);
        self::assertSame('2026-11-17', $this->repo->settings['report_sent_on']);
    }

    public function testOnEveryDayANightWithOnlyOldLinesSaysHowManyAreStillOpen(): void
    {
        $this->repo->settings['report_by_email'] = 'daily';
        $this->waiting(69);
        $this->waiting(70);
        $this->addon(72, 'Suspended');
        $this->queued(1, 72);
        $this->night(self::NIGHT);
        self::assertSame('MainWP for WHMCS: 3 new of 3 to report', $this->emails()[0]['values']['customsubject'], 'with something new, as before');

        $this->night(self::NEXT_NIGHT);
        $emails = $this->emails();
        self::assertCount(2, $emails);
        self::assertSame('MainWP for WHMCS: 3 still open', $emails[1]['values']['customsubject']);
        self::assertStringContainsString('Still open', (string) $emails[1]['values']['custommessage']);
        self::assertStringContainsString('<p>The whole list is on the module\'s page in the WHMCS admin area, under Addons.</p>', (string) $emails[1]['values']['custommessage'], 'nothing was cut, so the page holds the whole list');
        self::assertStringContainsString('modulequeue.php', (string) $emails[1]['values']['custommessage']);
    }

    public function testOffSendsNothingEvenWithAFailedCommand(): void
    {
        $this->repo->settings['report_by_email'] = 'off';
        $this->addon(72, 'Suspended');
        $this->queued(1, 72);
        self::assertSame('', $this->night(self::NIGHT)['email']);
        self::assertSame([], $this->emails());
        self::assertArrayNotHasKey('report_sent_on', $this->repo->settings);
    }
}
