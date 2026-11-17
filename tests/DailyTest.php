<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\Daily;
use MainWpForWhmcs\Hooks;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Tests\Engine\RecordingSite;
use MainWpForWhmcs\Tests\Support\FixtureHttp;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

require_once __DIR__ . '/Engine/Fakes.php';

/**
 * The daily check as WHMCS runs it: on DailyCronJob, from the add-on rows WHMCS holds,
 * with the result kept for the module's page and one email when there is news. The dashboard's
 * answers are the bench's recordings.
 */
final class DailyTest extends TestCase
{
    private MemoryRepository $repo;
    private FixtureHttp $http;
    private RecordingSite $site;

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        $this->repo->settings = ['dry_run' => '', 'dashboard_url' => 'https://dash.example.com', 'api_key' => (new WhmcsEncrypter())->encrypt('bench-key-0123456789')];
        $this->repo->addonModules = [16 => 'mainwp'];
        $this->http = new FixtureHttp();
        $this->site = new RecordingSite();
        $repo = $this->repo;
        $http = $this->http;
        $site = $this->site;
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

    private function addon(int $id, string $status = 'Active', string $serviceStatus = 'Active', int $unpaid = 0, string $domain = ''): void
    {
        $this->repo->addons[$id] = new AddonRow($id, 1100 + $id, 540, 16, $status, $serviceStatus, $domain !== '' ? $domain : 'site' . $id . '.example', 'Demo Client', 'demo-client@example.com', $unpaid);
    }

    private function careSite(int $id, string $state, ?int $dashboardSiteId = null, string $url = ''): void
    {
        $this->repo->careSites[$id] = [
            'addon_row_id' => $id, 'service_id' => 1100 + $id, 'client_id' => 540, 'site_url' => $url !== '' ? $url : 'https://site' . $id . '.example', 'dashboard_client_id' => 1,
            'dashboard_site_id' => $dashboardSiteId, 'state' => $state, 'retry_count' => 0, 'last_sync' => null, 'last_error' => null, 'retry_after' => null,
        ];
    }

    /** The dashboard as the bench recorded it: one client, one connected site (id 1) under it. */
    private function dashboard(string $sites = 'sites-list'): void
    {
        // The clients are read once for the check and once more for each add-on it provisions.
        $this->http->on('GET', 'sites?page=1&per_page=20', $sites)->on('GET', 'clients?page=1&per_page=100', 'clients-list', 'clients-list', 'clients-list');
    }

    /** @return list<array{command: string, values: array<string, mixed>}> the emails WHMCS was asked to send */
    private function emails(): array
    {
        return array_values(array_filter(WhmcsCalls::$api, static fn (array $call): bool => $call['command'] === 'SendAdminEmail'));
    }

    public function testTheCronsRunDeletesLogEntriesOlderThanTheMonthsInTheSettingsAndSaysSo(): void
    {
        $this->dashboard('sites-list-empty');
        foreach (['2026-05-10 23:59:59' => 'six months and a second', '2026-05-11 00:00:10' => 'six months to the second', '2026-11-10 12:00:00' => 'yesterday'] as $at => $detail) {
            $this->repo->addLog(['created_at' => $at, 'actor' => 'cron:daily', 'action' => 'daily check', 'target' => 'all care add-ons', 'outcome' => 'done', 'dry_run' => 0, 'detail' => $detail]);
        }
        $this->repo->settings['log_retention_months'] = '6';

        Daily::run($this->repo, 'cron:daily', true, '2026-11-11 00:00:10');

        $details = array_column($this->repo->log, 'detail');
        self::assertNotContains('six months and a second', $details);
        self::assertContains('six months to the second', $details, 'an entry as old as the limit to the second stays');
        self::assertContains('yesterday', $details);
        $row = end($this->repo->log);
        self::assertSame(['cron:daily', 'log', 'log', 'pruned', 0, '1 entry older than 6 months deleted'], [$row['actor'], $row['action'], $row['target'], $row['outcome'], $row['dry_run'], $row['detail']]);
    }

    public function testAMonthOfRetentionIsACalendarMonthAlsoAtTheEndOfALongerOne(): void
    {
        // PHP's own "31 March less one month" is 3 March: it would delete four weeks too many.
        foreach (['2026-02-27 23:00:00' => 'before the last day of February', '2026-02-28 00:00:10' => 'the last day of February, to the second', '2026-03-02 12:00:00' => 'early March'] as $at => $detail) {
            $this->repo->addLog(['created_at' => $at, 'actor' => 'cron:daily', 'action' => 'daily check', 'target' => 'all care add-ons', 'outcome' => 'done', 'dry_run' => 0, 'detail' => $detail]);
        }
        $this->repo->settings['log_retention_months'] = '1';
        $this->dashboard('sites-list-empty');

        Daily::run($this->repo, 'cron:daily', true, '2026-03-31 00:00:10');

        $details = array_column($this->repo->log, 'detail');
        self::assertNotContains('before the last day of February', $details);
        self::assertContains('the last day of February, to the second', $details);
        self::assertContains('early March', $details, 'four weeks old is not a month old');
    }

    public function testNothingIsDeletedFromTheLogAtNoughtMonthsOrByARunFromThePage(): void
    {
        $old = ['created_at' => '2020-01-01 00:00:00', 'actor' => 'cron:daily', 'action' => 'daily check', 'target' => 'all care add-ons', 'outcome' => 'done', 'dry_run' => 0, 'detail' => 'years ago'];
        $this->repo->addLog($old);

        $this->dashboard('sites-list-empty');
        Daily::run($this->repo, 'cron:daily', true, '2026-11-11 00:00:10');
        self::assertContains('years ago', array_column($this->repo->log, 'detail'), 'no number in the settings keeps every entry');

        $this->repo->settings['log_retention_months'] = '1';
        $this->dashboard('sites-list-empty');
        Daily::run($this->repo, 'admin:1', false, '2026-11-11 08:00:00');
        self::assertContains('years ago', array_column($this->repo->log, 'detail'), 'only the cron\'s run prunes');

        $this->dashboard('sites-list-empty');
        Daily::run($this->repo, 'cron:daily', true, '2026-11-12 00:00:10');
        self::assertNotContains('years ago', array_column($this->repo->log, 'detail'));
        self::assertNotContains('pruned', array_column(array_slice($this->repo->log, 0, -1), 'outcome'), 'said once, when entries went');

        $rows = count($this->repo->log);
        $this->dashboard('sites-list-empty');
        Daily::run($this->repo, 'cron:daily', true, '2026-11-13 00:00:10');
        self::assertSame(0, count(array_filter(array_slice($this->repo->log, $rows), static fn (array $row): bool => $row['outcome'] === 'pruned')), 'nothing went, nothing is said');
    }

    public function testTheHooksFileRegistersTheDailyCron(): void
    {
        require __DIR__ . '/../modules/addons/mainwp/hooks.php';
        self::assertContains('DailyCronJob', array_column(WhmcsCalls::$hooks, 'hook'));
        $this->dashboard('sites-list-empty');
        foreach (WhmcsCalls::$hooks as $registered) {
            if ($registered['hook'] === 'DailyCronJob') {
                ($registered['callable'])([]);
            }
        }
        self::assertNotNull(Daily::last($this->repo), 'the cron ran the check');
        self::assertSame([], WhmcsCalls::$activity);
    }

    public function testEveryCareAddonIsReadWithItsStandingAndTheResultIsKeptForThePage(): void
    {
        $this->addon(70);                                   // billed, no care site: provisioned
        $this->addon(71, 'Active', 'Active', 1);            // an unpaid invoice: not provisioned yet, and no line
        $this->addon(72, 'Suspended');                      // suspended in WHMCS, still connected on the dashboard
        $this->careSite(72, 'connected', 1, 'https://mainwp-bench-site.test');
        $this->addon(73, 'Cancelled', 'Suspended');         // ended wins over suspended
        $this->careSite(73, 'provisioned');
        $this->repo->addons[90] = new AddonRow(90, 1190, 540, 7, 'Active', 'Active', 'other.example', 'Demo Client', 'demo-client@example.com', 0); // another product's add-on
        $this->dashboard();

        $result = Daily::run($this->repo, 'cron:daily', true);

        self::assertSame('provisioned', $this->repo->careSites[70]['state'] ?? null);
        self::assertArrayNotHasKey(71, $this->repo->careSites);
        self::assertArrayNotHasKey(90, $this->repo->careSites);
        self::assertSame([
            'Demo Client (client 540), https://site70.example (Provisioned): waiting for the client to connect the site',
            'Demo Client (client 540), https://mainwp-bench-site.test (Connected): suspended in WHMCS (the add-on is Suspended, not Active) but connected on the dashboard',
            'Demo Client (client 540), https://site73.example (Provisioned): ended in WHMCS (the add-on is Cancelled, not Active) but its care site is not marked removed',
        ], array_column($result['lines'], 'text'));
        self::assertSame([false, null, 'cron:daily'], [$result['dry_run'], $result['fault'], $result['by']]);
        self::assertEquals($result, Daily::last($this->repo), 'what the module page shows until the next run');
    }

    public function testTheCheckTakesTheAddOnsWhoseModuleSettingsNameTheModuleAndNotTheOldStoredList(): void
    {
        // An install whose old stored list is "16" and whose product add-on 16 names the module.
        $this->repo->settings['care_addon_ids'] = '16,7';
        $this->addon(70);
        $this->repo->addons[90] = new AddonRow(90, 1190, 540, 7, 'Active', 'Active', 'other.example', 'Demo Client', 'demo-client@example.com', 0); // on the old list only
        $this->dashboard();

        Daily::run($this->repo, 'cron:daily', true);

        self::assertSame('provisioned', $this->repo->careSites[70]['state'] ?? null);
        self::assertArrayNotHasKey(90, $this->repo->careSites);
    }

    public function testOneEmailGoesToTheAdministratorsWhoReceiveSystemEmailsWhenThereIsNews(): void
    {
        $this->addon(70);
        $this->dashboard('sites-list-empty');
        $this->repo->systemEmailAdmins = ['Ada Admin', 'Bea'];

        $result = Daily::run($this->repo, 'cron:daily', true);

        $emails = $this->emails();
        self::assertCount(1, $emails);
        self::assertSame('system', $emails[0]['values']['type'], 'WHMCS documents no way to mail an address of the module\'s choosing; this goes to the roles that receive system emails');
        self::assertSame('MainWP for WHMCS: 1 new of 1 to report', $emails[0]['values']['customsubject']);
        $body = (string) $emails[0]['values']['custommessage'];
        self::assertStringContainsString("<li>Demo Client (client 540), https://site70.example (Provisioned): waiting for the client to connect the site (add-on 70)</li>\n", $body, 'the add-on is named, so that two add-ons on one site are told apart, and every item ends its line, so that the plain-text version WHMCS makes does not run together');
        self::assertStringNotContainsString('</p><', $body, 'every paragraph ends its line');
        self::assertStringNotContainsString('</li><', $body);
        self::assertStringNotContainsString('</ul><', $body);
        self::assertSame('sent to Ada Admin, Bea', $result['email']);
        $row = $this->repo->lastLogFor('daily check', 'daily digest');
        self::assertSame(['done', 'sent to Ada Admin, Bea'], [$row['outcome'] ?? null, $row['detail'] ?? null]);
    }

    /** Version 0.6.0: an add-on whose hosting service has no domain is in the digest, with why, and is not provisioned. */
    public function testAnAddonWhoseServiceHasNoDomainIsInTheDigestWithWhy(): void
    {
        $this->repo->addons[70] = new AddonRow(70, 1170, 540, 16, 'Active', 'Active', '', 'Demo Client', 'demo-client@example.com', 0);
        $this->dashboard('sites-list-empty');
        $this->repo->systemEmailAdmins = ['Ada Admin'];

        $result = Daily::run($this->repo, 'cron:daily', true);

        self::assertSame([], $this->repo->careSites);
        self::assertSame(['Demo Client (client 540) (Not provisioned): its hosting service has no domain, so no site can be connected'], array_column($result['lines'], 'text'));
        $body = (string) $this->emails()[0]['values']['custommessage'];
        self::assertStringContainsString("<li>Demo Client (client 540) (Not provisioned): its hosting service has no domain, so no site can be connected (add-on 70)</li>\n", $body);
        $waiting = array_values(array_filter($this->repo->log, static fn (array $r): bool => $r['action'] === 'provision'));
        self::assertSame([['add-on 70', 'waiting']], array_map(static fn (array $r): array => [$r['target'], $r['outcome']], $waiting));
    }

    public function testTheSameListTheNextDayIsNotMailedAgainAndANewLineIs(): void
    {
        $this->addon(70);
        $this->careSite(70, 'provisioned');
        $this->dashboard('sites-list-empty');
        Daily::run($this->repo, 'cron:daily', true, '2026-11-11 00:00:10');
        self::assertCount(1, $this->emails());

        // The next day nothing has changed: the page still lists it, and nobody is mailed.
        $this->dashboard('sites-list-empty');
        $second = Daily::run($this->repo, 'cron:daily', true, '2026-11-12 00:00:10');
        self::assertCount(1, $this->emails(), 'story 17: told, not flooded');
        self::assertSame('', $second['email']);
        self::assertCount(1, $second['lines']);
        self::assertSame([false], array_column($second['lines'], 'new'));

        // The day after, another site waits too: one email, the new line first.
        $this->addon(69);
        $this->careSite(69, 'provisioned');
        $this->dashboard('sites-list-empty');
        Daily::run($this->repo, 'cron:daily', true, '2026-11-13 00:00:10');
        $emails = $this->emails();
        self::assertCount(2, $emails);
        self::assertSame('MainWP for WHMCS: 1 new of 2 to report', $emails[1]['values']['customsubject']);
        $body = (string) $emails[1]['values']['custommessage'];
        self::assertLessThan(strpos($body, 'site70.example'), strpos($body, 'site69.example'), 'what is new comes first');
        self::assertStringContainsString('Still open', $body);
    }

    public function testNothingToReportSendsNoEmail(): void
    {
        $this->addon(72);
        $this->careSite(72, 'connected', 1, 'https://mainwp-bench-site.test');
        $this->dashboard();

        $result = Daily::run($this->repo, 'cron:daily', true);

        self::assertSame([[], null, ''], [$result['lines'], $result['fault'], $result['email']]);
        self::assertSame([], $this->emails());
    }

    public function testARunFromThePageSendsNoEmailAndNeitherDoesASwitchedOffReport(): void
    {
        $this->addon(70);
        $this->dashboard('sites-list-empty');
        self::assertSame('', Daily::run($this->repo, 'admin:1', false)['email']);

        $this->repo->settings['report_by_email'] = '';
        $this->addon(69);
        $this->dashboard('sites-list-empty');
        self::assertSame('', Daily::run($this->repo, 'cron:daily', true)['email']);

        self::assertSame([], $this->emails());
    }

    public function testAnEmailThatCouldNotBeSentIsSaidOnThePageAndInTheLog(): void
    {
        $this->addon(70);
        $this->dashboard('sites-list-empty');
        WhmcsCalls::$apiErrors['SendAdminEmail'] = 'Email Send Aborted By Hook';
        $result = Daily::run($this->repo, 'cron:daily', true);
        self::assertSame('not sent: Email Send Aborted By Hook', $result['email']);
        self::assertSame('failed', $this->repo->lastLogFor('daily check', 'daily digest')['outcome'] ?? null);

        // No administrator's role receives system emails: WHMCS would send it to nobody.
        WhmcsCalls::$apiErrors = [];
        WhmcsCalls::$api = [];
        $this->repo->systemEmailAdmins = [];
        $this->addon(69);
        $this->dashboard('sites-list-empty');
        $result = Daily::run($this->repo, 'cron:daily', true);
        self::assertSame('not sent: no administrator\'s role receives system emails', $result['email']);
        self::assertSame([], $this->emails());
    }

    public function testAnEmailThatCouldNotBeSentIsSentTheNextDay(): void
    {
        $this->addon(70);
        $this->careSite(70, 'provisioned');
        $this->dashboard('sites-list-empty');
        WhmcsCalls::$apiErrors['SendAdminEmail'] = 'Email Send Aborted By Hook';
        $first = Daily::run($this->repo, 'cron:daily', true);
        self::assertSame('not sent: Email Send Aborted By Hook', $first['email']);

        // The next day WHMCS sends again: the line nobody was told of is still news.
        WhmcsCalls::$apiErrors = [];
        WhmcsCalls::$api = [];
        $this->dashboard('sites-list-empty');
        $second = Daily::run($this->repo, 'cron:daily', true);
        self::assertSame([true], array_column($second['lines'], 'new'));
        $emails = $this->emails();
        self::assertCount(1, $emails);
        self::assertSame('MainWP for WHMCS: 1 new of 1 to report', $emails[0]['values']['customsubject']);
        self::assertSame('sent to Ada Admin', $second['email']);

        // Told once: the day after that it is no news.
        $this->dashboard('sites-list-empty');
        Daily::run($this->repo, 'cron:daily', true);
        self::assertCount(1, $this->emails());
    }

    public function testWhileNoEmailHasEverGoneOutALogEventStaysInTheReportUntilOneDoes(): void
    {
        $this->addon(70);
        $this->careSite(70, 'provisioned');
        $this->repo->systemEmailAdmins = [];
        $this->dashboard('sites-list-empty');
        Daily::run($this->repo, 'cron:daily', true, '2026-11-10 00:00:10');
        $this->repo->addLog(['created_at' => '2026-11-10 12:00:00', 'actor' => 'cron:daily', 'action' => 'key', 'target' => 'add-on 70', 'outcome' => 'given up', 'dry_run' => 0, 'detail' => 'the approved key could not be deleted for a week']);
        foreach (['2026-11-11 00:00:10', '2026-11-12 00:00:10'] as $day) {
            $this->dashboard('sites-list-empty');
            self::assertStringStartsWith('not sent', (string) Daily::run($this->repo, 'cron:daily', true, $day)['email']);
        }

        // An administrator's role receives system emails at last: the event of two days ago is still told.
        $this->repo->systemEmailAdmins = ['Ada Admin'];
        $this->dashboard('sites-list-empty');
        Daily::run($this->repo, 'cron:daily', true, '2026-11-13 00:00:10');

        $emails = $this->emails();
        self::assertCount(1, $emails);
        self::assertStringContainsString('the approved key could not be deleted for a week', (string) $emails[0]['values']['custommessage']);
    }

    public function testADashboardFaultThatIsNewsIsNamedInTheSubjectBesideTheCount(): void
    {
        $this->addon(70);
        $this->careSite(70, 'provisioned');
        $this->dashboard('sites-list-empty');
        Daily::run($this->repo, 'cron:daily', true, '2026-11-11 00:00:10');

        // The next day the dashboard cannot be read; the waiting site is still listed, from the records.
        $this->http->on('GET', 'sites?page=1&per_page=20', 'key-wrong');
        $second = Daily::run($this->repo, 'cron:daily', true, '2026-11-12 00:00:10');

        self::assertSame([false], array_column($second['lines'], 'new'));
        $emails = $this->emails();
        self::assertCount(2, $emails);
        self::assertSame('MainWP for WHMCS: dashboard fault; 0 new of 1 to report', $emails[1]['values']['customsubject'], 'not "0 new" alone, for an email whose news is the fault');
    }

    public function testALogRowWrittenWhileTheCheckRunsIsReportedOnceByTheNextRun(): void
    {
        $this->addon(72);
        $this->careSite(72, 'connected', 1, 'https://mainwp-bench-site.test');
        $this->dashboard();
        Daily::run($this->repo, 'cron:daily', true, '2026-11-10 00:00:10');
        // The check itself gives up on a key two seconds into its run of the 11th.
        $this->repo->addLog(['created_at' => '2026-11-11 00:00:12', 'actor' => 'cron:daily', 'action' => 'key', 'target' => 'add-on 72', 'outcome' => 'given up', 'dry_run' => 0, 'detail' => 'the approved key could not be deleted for a week']);
        $this->dashboard();
        self::assertSame([], Daily::run($this->repo, 'cron:daily', true, '2026-11-11 00:00:10')['lines'], 'not yet: it is later than the run counts as its own time');

        $this->dashboard();
        $next = Daily::run($this->repo, 'cron:daily', true, '2026-11-12 00:00:10');
        self::assertSame([true], array_column($next['lines'], 'new'));
        self::assertCount(1, $this->emails());

        $this->dashboard();
        self::assertSame([], Daily::run($this->repo, 'cron:daily', true, '2026-11-13 00:00:10')['lines'], 'and not a second time');
    }

    public function testALongDashboardFaultCannotTakeTheWholeRow(): void
    {
        $this->http->on('GET', 'sites?page=1&per_page=20', new \MainWpForWhmcs\Engine\HttpResponse(500, (string) json_encode(['code' => 'mainwp_rest_error', 'message' => str_repeat('The dashboard is unwell. ', 400)])));

        $result = Daily::run($this->repo, 'cron:daily', true);

        self::assertNotNull($result['fault']);
        self::assertLessThanOrEqual(601, preg_match_all('/./us', (string) $result['fault']));
        self::assertNotNull(Daily::last($this->repo));
    }

    public function testTheEmailHoldsNothingAClientCouldBreakItWithAndNoSecret(): void
    {
        $this->repo->addons[70] = new AddonRow(70, 1170, 540, 16, 'Active', 'Active', 'site70.example', 'Smith & {$Sons} <b>', 'demo-client@example.com', 0);
        $this->careSite(70, 'failed');
        $this->repo->careSites[70]['last_error'] = 'The login was refused.';
        $this->repo->careSites[70]['login_encrypted'] = (new WhmcsEncrypter())->encrypt('{"username":"owner","password":"Secret1"}');
        $this->dashboard('sites-list-empty');

        Daily::run($this->repo, 'cron:daily', true);

        $body = (string) $this->emails()[0]['values']['custommessage'];
        self::assertStringContainsString('Smith &amp; &#123;$Sons&#125; &lt;b&gt;', $body, 'escaped as HTML, and its braces cannot be read as template code');
        self::assertSame(0, preg_match('/[{}]/', $body));
        self::assertStringNotContainsString('Secret1', $body . json_encode(Daily::last($this->repo), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('bench-key-0123456789', $body);
    }

    public function testADashboardFaultIsMailedOnceAndTheRecordsLinesAreKept(): void
    {
        $this->addon(72, 'Suspended');
        $this->careSite(72, 'connected', 1, 'https://mainwp-bench-site.test');
        $this->http->on('GET', 'sites?page=1&per_page=20', 'key-wrong', 'key-wrong');

        $first = Daily::run($this->repo, 'cron:daily', true);
        $second = Daily::run($this->repo, 'cron:daily', true);

        self::assertStringStartsWith('The dashboard could not be read, so nothing was changed: The dashboard refused the API key', (string) $first['fault']);
        self::assertCount(1, $first['lines']);
        self::assertCount(1, $this->emails(), 'the fault is news on the first day only');
        self::assertStringContainsString('Dashboard fault', (string) $this->emails()[0]['values']['custommessage']);
        self::assertNotNull($second['fault']);
    }

    public function testADashboardFaultWithNothingElseToReportIsStillMailedOnce(): void
    {
        $this->http->on('GET', 'sites?page=1&per_page=20', 'key-wrong', 'key-wrong');

        $first = Daily::run($this->repo, 'cron:daily', true);
        Daily::run($this->repo, 'cron:daily', true);

        self::assertSame([], $first['lines']);
        $emails = $this->emails();
        self::assertCount(1, $emails, 'staff are told the dashboard cannot be read, once');
        self::assertSame('MainWP for WHMCS: dashboard fault', $emails[0]['values']['customsubject']);
        $body = (string) $emails[0]['values']['custommessage'];
        self::assertStringStartsWith('<p><strong>Dashboard fault:</strong> The dashboard could not be read, so nothing was changed: ', $body);
        self::assertStringNotContainsString('to report', $body, 'nothing was counted');
    }

    public function testAResultThatCannotBeKeptIsNotMailed(): void
    {
        $this->addon(70);
        $this->careSite(70, 'provisioned');
        $this->dashboard('sites-list-empty');
        $this->repo->saveRefuses = new \RuntimeException('Data too long for column value');

        try {
            Daily::run($this->repo, 'cron:daily', true);
            self::fail('the failure is the cron guard\'s to log');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->emails(), 'else the same list would be mailed again on every run');
    }

    public function testOneFindingCannotTakeTheWholeRow(): void
    {
        $this->addon(70);
        $this->careSite(70, 'failed');
        $this->repo->careSites[70]['last_error'] = 'The login was refused.';
        $this->repo->addLog(['created_at' => '2026-11-09 10:00:00', 'actor' => 'client:540', 'action' => 'connect', 'target' => 'add-on 70', 'outcome' => 'failed', 'dry_run' => 0, 'detail' => str_repeat('é', 2000)]);
        $this->dashboard('sites-list-empty');

        $problem = Daily::run($this->repo, 'cron:daily', true)['lines'][0]['problem'];

        self::assertSame(601, preg_match_all('/./us', $problem), 'cut on a whole character');
        self::assertStringEndsWith('é…', $problem);
    }

    public function testWithMoreLinesThanKeysAreKeptTheNextRunCountsNoneAsNew(): void
    {
        // The keys of 3000 lines are kept; a list longer than that must not be mailed every day for the lines past them.
        $this->repo->settings['dry_run'] = 'on';
        for ($id = 1; $id <= 3001; $id++) {
            $this->addon($id);
        }

        Daily::run($this->repo, 'cron:daily', true);
        $second = Daily::run($this->repo, 'cron:daily', true);

        self::assertSame('MainWP for WHMCS (dry run): 3001 new of 3001 to report', $this->emails()[0]['values']['customsubject']);
        self::assertCount(1, $this->emails());
        self::assertSame([3001, 0], [$second['total'], $second['new']]);
        self::assertLessThan(60000, strlen(htmlspecialchars($this->repo->settings['last_check'])));
    }

    public function testAFailedConnectIsReportedWithTheReasonStaffNeedNotTheSentenceTheClientReads(): void
    {
        $this->addon(70);
        $this->careSite(70, 'failed');
        $this->repo->careSites[70]['last_error'] = 'We could not finish connecting your site because of a fault on our side.';
        $this->repo->addLog(['created_at' => '2026-11-09 10:00:00', 'actor' => 'client:540', 'action' => 'connect', 'target' => 'add-on 70', 'outcome' => 'failed', 'dry_run' => 0, 'detail' => 'The dashboard refused to connect https://site70.example: it already holds a site at that address (dashboard site 9).']);
        $this->repo->addLog(['created_at' => '2026-11-09 11:00:00', 'actor' => 'cron:daily', 'action' => 'connect', 'target' => 'add-on 70', 'outcome' => 'ended', 'dry_run' => 0, 'detail' => 'no more retries']);
        $this->dashboard('sites-list-empty');

        $result = Daily::run($this->repo, 'cron:daily', true);

        self::assertStringEndsWith('Log: The dashboard refused to connect https://site70.example: it already holds a site at that address (dashboard site 9).', $result['lines'][0]['problem']);
    }

    public function testWhatHappenedSinceTheLastRunAndNothingElseWouldShowIsInTheReport(): void
    {
        $this->addon(72);
        $this->careSite(72, 'connected', 1, 'https://mainwp-bench-site.test');
        $this->dashboard();
        Daily::run($this->repo, 'cron:daily', true, '2026-11-10 00:00:10');
        $later = '2026-11-10 08:00:00';
        $this->repo->addLog(['created_at' => $later, 'actor' => 'cron:daily', 'action' => 'key', 'target' => 'add-on 72', 'outcome' => 'given up', 'dry_run' => 0, 'detail' => 'the approved key could not be deleted on https://mainwp-bench-site.test for a week; ask the client to delete the application password']);
        $this->repo->addLog(['created_at' => $later, 'actor' => 'whmcs:AfterModuleSuspend', 'action' => 'suspend', 'target' => 'a hosting service', 'outcome' => 'failed', 'dry_run' => 0, 'detail' => 'AfterModuleSuspend did not say which service or add-on it was for']);
        $this->repo->addLog(['created_at' => $later, 'actor' => 'whmcs:AddonSuspended', 'action' => 'suspend', 'target' => 'add-on 72', 'outcome' => 'failed', 'dry_run' => 0, 'detail' => 'the dashboard could not be reached']);
        $this->dashboard();

        $result = Daily::run($this->repo, 'cron:daily', true, '2026-11-11 00:00:10');

        self::assertSame([
            'add-on 72 (from the log): the approved key could not be deleted on https://mainwp-bench-site.test for a week; ask the client to delete the application password',
            'a hosting service (from the log): AfterModuleSuspend did not say which service or add-on it was for',
        ], array_column($result['lines'], 'text'), 'a failed event for a known add-on shows as a mismatch of its site instead');
        self::assertCount(1, $this->emails());

        // They are said once: the run after has nothing new.
        $this->dashboard();
        self::assertSame([], Daily::run($this->repo, 'cron:daily', true, '2026-11-12 00:00:10')['lines']);
    }

    public function testInDryRunTheCheckReadsItsOwnRecordsOnlyAndItsEmailSaysSo(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->addon(70);

        $result = Daily::run($this->repo, 'cron:daily', true);

        self::assertSame([], $this->http->sent);
        self::assertTrue($result['dry_run']);
        self::assertSame(['Demo Client (client 540), https://site70.example (Not provisioned): would be provisioned (dry run)'], array_column($result['lines'], 'text'));
        $emails = $this->emails();
        self::assertCount(1, $emails);
        self::assertSame('MainWP for WHMCS (dry run): 1 new of 1 to report', $emails[0]['values']['customsubject']);
        self::assertStringContainsString('Dry run: nothing was sent to the dashboard or to any site.', (string) $emails[0]['values']['custommessage']);
    }

    public function testARunThatFindsAnotherOneGoingKeepsTheLastResult(): void
    {
        $this->addon(70);
        $this->dashboard('sites-list-empty');
        $first = Daily::run($this->repo, 'cron:daily', true);
        $this->repo->lockedElsewhere = ['addon:0'];

        $second = Daily::run($this->repo, 'admin:1', false);

        self::assertTrue($second['running']);
        self::assertEquals($first, Daily::last($this->repo));
    }

    public function testALongListIsCutToWhatASettingsRowHoldsAndStillTellsWhatIsNew(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        for ($id = 1; $id <= 130; $id++) {
            $this->addon($id);
        }

        $first = Daily::run($this->repo, 'cron:daily', true, '2026-11-11 00:00:10');

        self::assertCount(100, $first['lines']);
        self::assertSame(30, $first['more']);
        self::assertLessThan(60000, strlen(htmlspecialchars($this->repo->settings['last_check'])), 'WHMCS keeps a setting in a TEXT column (64 KB) and HTML-encodes it');
        $emails = $this->emails();
        self::assertSame('MainWP for WHMCS (dry run): 130 new of 130 to report', $emails[0]['values']['customsubject'], 'the count is of all that was found, not of what was kept');
        self::assertStringContainsString("<p>And 30 more, not listed here.</p>\n<p>The module's page in the WHMCS admin area, under Addons, has the same lines and no more.</p>", (string) $emails[0]['values']['custommessage']);
        self::assertStringNotContainsString('The whole list', (string) $emails[0]['values']['custommessage'], 'the page keeps no more lines than the email');

        // Nothing changes: the lines that could not be kept are not news again the next day.
        $second = Daily::run($this->repo, 'cron:daily', true, '2026-11-12 00:00:10');
        self::assertCount(1, $this->emails(), 'no email a day for a long list');
        self::assertSame([], array_filter(array_column($second['lines'], 'new')));

        // One more add-on, behind all the others: its line is kept, first, and mailed.
        $this->addon(131);
        $third = Daily::run($this->repo, 'cron:daily', true, '2026-11-13 00:00:10');
        $emails = $this->emails();
        self::assertCount(2, $emails);
        self::assertSame('MainWP for WHMCS (dry run): 1 new of 131 to report', $emails[1]['values']['customsubject']);
        self::assertSame([131, true, 31], [$third['lines'][0]['addon'], $third['lines'][0]['new'], $third['more']]);
        self::assertStringContainsString("<p><strong>New</strong></p>\n<ul>\n<li>Demo Client (client 540), https://site131.example (Not provisioned)", (string) $emails[1]['values']['custommessage']);
    }

    public function testLongLinesAreCutByWhatTheSettingsRowHoldsNotByTheirNumber(): void
    {
        $reason = 'The site did not accept the login: ' . str_repeat('la connexion a été refusée par le site, ', 6) . 'see the site\'s own log.';
        for ($id = 1; $id <= 100; $id++) {
            $this->repo->addons[$id] = new AddonRow($id, 1100 + $id, 540, 16, 'Active', 'Active', 'ünïcödé-' . $id . '.example', 'Société d\'Électricité "Ångström" & Fils', 'demo-client@example.com', 0);
            $this->careSite($id, 'failed', null, 'https://ünïcödé-' . $id . '.example');
            $this->repo->careSites[$id]['last_error'] = $reason;
            $this->repo->addLog(['created_at' => '2026-11-09 10:00:00', 'actor' => 'client:540', 'action' => 'connect', 'target' => 'add-on ' . $id, 'outcome' => 'failed', 'dry_run' => 0, 'detail' => 'The login was refused at https://ünïcödé-' . $id . '.example/wp-login.php: ' . str_repeat('"Erreur" : l\'identifiant ou le mot de passe est incorrect. ', 4)]);
        }
        $this->dashboard('sites-list-empty');

        $result = Daily::run($this->repo, 'cron:daily', true);

        self::assertLessThan(60000, strlen(htmlspecialchars($this->repo->settings['last_check'])), 'a result WHMCS would cut could not be read back');
        self::assertGreaterThan(0, $result['more']);
        self::assertSame(100, count($result['lines']) + $result['more']);
        self::assertSame('MainWP for WHMCS: 100 new of 100 to report', $this->emails()[0]['values']['customsubject']);
        self::assertNotNull(Daily::last($this->repo));

        $this->dashboard('sites-list-empty');
        Daily::run($this->repo, 'cron:daily', true);
        self::assertCount(1, $this->emails(), 'what was not kept is not news again');
    }

    public function testTheFirstRunInAnotherModeReportsEverythingAsNew(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->addon(70);
        $this->careSite(70, 'failed');
        $this->repo->careSites[70]['last_error'] = 'The login was refused.';
        Daily::run($this->repo, 'cron:daily', true, '2026-11-11 00:00:10');
        self::assertCount(1, $this->emails());

        // Live for the first time: what the dry run listed from the records alone is now the real thing, and is said again.
        $this->repo->settings['dry_run'] = '';
        $this->dashboard('sites-list-empty');
        $live = Daily::run($this->repo, 'cron:daily', true, '2026-11-12 00:00:10');
        self::assertSame([true], array_column($live['lines'], 'new'));
        $emails = $this->emails();
        self::assertCount(2, $emails);
        self::assertSame('MainWP for WHMCS: 1 new of 1 to report', $emails[1]['values']['customsubject']);

        $this->dashboard('sites-list-empty');
        Daily::run($this->repo, 'cron:daily', true, '2026-11-13 00:00:10');
        self::assertCount(2, $this->emails(), 'and then no more');
    }

    public function testARunFromThePageDoesNotTakeTheNewsFromTheNextCronRun(): void
    {
        $this->addon(72);
        $this->careSite(72, 'connected', 1, 'https://mainwp-bench-site.test');
        $this->dashboard();
        Daily::run($this->repo, 'cron:daily', true, '2026-11-10 00:00:10');
        self::assertSame([], $this->emails());

        // During the day a site starts to wait and an event comes in that named no service; then staff press the button.
        $this->addon(70);
        $this->careSite(70, 'provisioned');
        $this->repo->addLog(['created_at' => '2026-11-10 08:00:00', 'actor' => 'whmcs:AfterModuleSuspend', 'action' => 'suspend', 'target' => 'a hosting service', 'outcome' => 'failed', 'dry_run' => 0, 'detail' => 'AfterModuleSuspend did not say which service or add-on it was for']);
        $this->dashboard();
        $page = Daily::run($this->repo, 'admin:1', false, '2026-11-10 10:00:00');
        self::assertSame([true, true], array_column($page['lines'], 'new'));
        self::assertSame([], $this->emails());

        // The cron that night still tells the administrators, and still has the event.
        $this->dashboard();
        $night = Daily::run($this->repo, 'cron:daily', true, '2026-11-11 00:00:10');
        self::assertCount(2, $night['lines']);
        $emails = $this->emails();
        self::assertCount(1, $emails);
        self::assertSame('MainWP for WHMCS: 2 new of 2 to report', $emails[0]['values']['customsubject']);

        // The day after, the event has been said and the waiting site is no news.
        $this->dashboard();
        $after = Daily::run($this->repo, 'cron:daily', true, '2026-11-12 00:00:10');
        self::assertSame(['Demo Client (client 540), https://site70.example (Provisioned): waiting for the client to connect the site'], array_column($after['lines'], 'text'));
        self::assertCount(1, $this->emails());
    }

    public function testTheEmailSaysHowManyAreNewAndNothingAboutAReportThatNeverWas(): void
    {
        $this->addon(70);
        $this->careSite(70, 'provisioned');
        $this->dashboard('sites-list-empty');

        Daily::run($this->repo, 'cron:daily', true);

        $body = (string) $this->emails()[0]['values']['custommessage'];
        self::assertStringContainsString('<p>The daily check of MainWP for WHMCS found 1 to report, 1 of them new.</p>', $body);
        self::assertStringNotContainsString('since the last report', $body, 'on the first run there was none');
    }

    public function testTheStaffLineForASiteWhoseRetriesEndedDoesNotSayItIsOnTheRetryList(): void
    {
        $this->addon(70);
        $this->careSite(70, 'failed');
        $this->repo->careSites[70]['last_error'] = 'The site could not be reached.';
        $this->repo->addLog(['created_at' => '2026-11-09 00:00:10', 'actor' => 'cron:daily', 'action' => 'connect', 'target' => 'add-on 70', 'outcome' => 'failed', 'dry_run' => 0, 'detail' => 'https://site70.example did not answer within 60 seconds (on the retry list: retry after 2026-11-10 00:00:10)']);
        $this->repo->addLog(['created_at' => '2026-11-09 00:00:11', 'actor' => 'cron:daily', 'action' => 'connect', 'target' => 'add-on 70', 'outcome' => 'ended', 'dry_run' => 0, 'detail' => 'no more retries for https://site70.example']);
        $this->dashboard('sites-list-empty');

        $result = Daily::run($this->repo, 'cron:daily', true);

        self::assertSame('The site could not be reached. Log: https://site70.example did not answer within 60 seconds', $result['lines'][0]['problem']);
    }

    public function testAfterAMendGaveUpStaffReadWhyFromTheMendsOwnLogRow(): void
    {
        $this->addon(72);
        $this->careSite(72, 'failed', 1, 'https://mainwp-bench-site.test');
        $this->repo->careSites[72]['last_error'] = 'Your site is no longer connected to care. Connect it again.';
        $this->repo->addLog(['created_at' => '2026-10-01 10:00:00', 'actor' => 'client:540', 'action' => 'connect', 'target' => 'add-on 72', 'outcome' => 'failed', 'dry_run' => 0, 'detail' => 'The login was refused (an attempt of weeks ago).']);
        $this->repo->addLog(['created_at' => '2026-11-09 00:00:10', 'actor' => 'cron:daily', 'action' => 'mend', 'target' => 'add-on 72', 'outcome' => 'failed', 'dry_run' => 0, 'detail' => 'dashboard site 1 (https://mainwp-bench-site.test) is disconnected and could not be mended (no login is stored); the client is asked to connect again.']);
        $this->dashboard('sites-list-disconnected');

        $result = Daily::run($this->repo, 'cron:daily', true);

        self::assertSame('Your site is no longer connected to care. Connect it again. Log: dashboard site 1 (https://mainwp-bench-site.test) is disconnected and could not be mended (no login is stored); the client is asked to connect again.', $result['lines'][0]['problem']);
    }

    public function testAConnectThatFailedAfterAMendIsTheReasonStaffRead(): void
    {
        $this->addon(72);
        $this->careSite(72, 'failed', 1, 'https://mainwp-bench-site.test');
        $this->repo->careSites[72]['last_error'] = 'The site could not be reached.';
        $this->repo->addLog(['created_at' => '2026-11-08 00:00:10', 'actor' => 'cron:daily', 'action' => 'mend', 'target' => 'add-on 72', 'outcome' => 'failed', 'dry_run' => 0, 'detail' => 'dashboard site 1 (https://mainwp-bench-site.test) is disconnected and could not be mended (no login is stored); the client is asked to connect again.']);
        $this->repo->addLog(['created_at' => '2026-11-09 14:00:00', 'actor' => 'client:540', 'action' => 'connect', 'target' => 'add-on 72', 'outcome' => 'failed', 'dry_run' => 0, 'detail' => 'https://mainwp-bench-site.test did not answer within 60 seconds']);
        $this->dashboard('sites-list-disconnected');

        $result = Daily::run($this->repo, 'cron:daily', true);

        self::assertSame('The site could not be reached. Log: https://mainwp-bench-site.test did not answer within 60 seconds', $result['lines'][0]['problem']);
    }

    public function testASiteWhoseMainWpChildWasSwitchedOffIsMendedOnTheDashboardsRecordedAnswers(): void
    {
        // Bench, sixth run: the sync fails, MainWP's reconnect answers HTTP 500, the site still shows as disconnected,
        // and once MainWP Child is on again one sync connects it.
        $this->addon(72);
        $this->careSite(72, 'connected', 1, 'https://mainwp-bench-site.test');
        $this->repo->careSites[72]['login_encrypted'] = (new WhmcsEncrypter())->encrypt((string) json_encode(['username' => 'owner', 'password' => 'Secret-1']));
        $this->dashboard('sites-list-disconnected');
        $this->http->on('POST', 'sites/1/sync', 'site-sync-child-off', 'site-sync-child-back')->on('POST', 'sites/1/reconnect', 'site-reconnect-child-off')->on('GET', 'sites/1', 'site-get-disconnected');

        $result = Daily::run($this->repo, 'cron:daily', true);

        self::assertSame('connected', $this->repo->careSites[72]['state']);
        self::assertSame(['installPlugin https://mainwp-bench-site.test owner'], $this->site->calls, 'the stored login switches MainWP Child on again');
        self::assertSame([], $result['lines']);
        self::assertSame([], $this->emails(), 'a site that was mended is nothing to report');
        $row = $this->repo->lastLogFor('mend', 'add-on 72');
        self::assertSame(['done', 'dashboard site 1 (https://mainwp-bench-site.test) was disconnected and is connected again by switching MainWP\'s plugin on again with the stored login'], [$row['outcome'] ?? null, $row['detail'] ?? null]);
        self::assertStringNotContainsString('Secret-1', (string) json_encode([$this->repo->log, $result]));
    }

    public function testADueRetryForASiteWithADeadDashboardSiteUsesThatSiteOnTheRecordedAnswers(): void
    {
        // Bench, sixth run: the dashboard's site is pointed at the administrator now logged in as (sites/{id}/edit) and one sync connects it.
        $this->addon(72);
        $this->careSite(72, 'failed', 1, 'https://mainwp-bench-site.test');
        $this->repo->careSites[72]['last_error'] = 'The site could not be reached. It is tried again by itself.';
        $this->repo->careSites[72]['retry_after'] = date('Y-m-d') . ' 00:00:00';
        $this->repo->careSites[72]['login_encrypted'] = (new WhmcsEncrypter())->encrypt((string) json_encode(['username' => 'owner', 'password' => 'Secret-1']));
        $this->dashboard('sites-list-disconnected');
        // A retry first looks whether the dashboard already holds the site, connected (an answer that was lost): it does not.
        $this->http->on('GET', 'sites?page=1&per_page=20', 'sites-list-disconnected')->on('POST', 'sites/1/edit', 'site-edit-admin')->on('POST', 'sites/1/sync', 'site-sync-after-edit');

        $result = Daily::run($this->repo, 'cron:daily', true);

        self::assertSame(['connected', 1, null], [$this->repo->careSites[72]['state'], (int) $this->repo->careSites[72]['dashboard_site_id'], $this->repo->careSites[72]['retry_after']]);
        self::assertSame([], $result['lines']);
        $posts = array_values(array_filter($this->http->sent, static fn (array $request): bool => $request['method'] === 'POST'));
        self::assertSame(['sites/1/edit', 'sites/1/sync'], array_map(static fn (array $request): string => (string) preg_replace('~^.*/mainwp/v2/~', '', $request['url']), $posts), 'no second site is added');
        self::assertSame(['admin' => 'owner'], $posts[0]['form']);
    }

    public function testASiteWhoseAdministratorIsGoneEndsFailedOnTheRecordedAnswersAndStaffReadWhy(): void
    {
        $this->addon(72);
        $this->careSite(72, 'connected', 1, 'https://mainwp-bench-site.test');
        $this->repo->careSites[72]['login_encrypted'] = (new WhmcsEncrypter())->encrypt((string) json_encode(['username' => 'wpcarek7m2x9ab', 'password' => 'Secret-1']));
        $this->site->refuses = new \MainWpForWhmcs\Engine\SiteFailure(\MainWpForWhmcs\Engine\SiteFailure::LOGIN_REFUSED, 'The site did not accept the username and password.');
        $this->dashboard('sites-list-disconnected');
        $this->http->on('POST', 'sites/1/sync', 'site-sync-admin-gone')->on('POST', 'sites/1/reconnect', 'site-reconnect-admin-gone')->on('GET', 'sites/1', 'site-get-admin-gone');

        $result = Daily::run($this->repo, 'cron:daily', true);

        self::assertSame(['failed', 'Your site is no longer connected to care. Connect it again.', null], [$this->repo->careSites[72]['state'], $this->repo->careSites[72]['last_error'], $this->repo->careSites[72]['retry_after']]);
        self::assertCount(1, $result['lines']);
        $problem = $result['lines'][0]['problem'];
        self::assertStringStartsWith('Your site is no longer connected to care. Connect it again. Log: dashboard site 1 (https://mainwp-bench-site.test) is disconnected and could not be mended (the stored login is of no use: The site did not accept the username and password); the client is asked to connect again. The dashboard says: ', $problem);
        self::assertStringNotContainsString('<', $problem, 'MainWP\'s diagnostics are HTML; only their first line is kept');
        self::assertLessThan(700, strlen($problem));
        self::assertStringNotContainsString('Secret-1', (string) json_encode([$this->repo->log, $result]));
        self::assertCount(1, $this->emails());
    }

    public function testTheCronNeverStopsWhmcs(): void
    {
        Module::$repositoryFactory = static function (): MemoryRepository {
            throw new \RuntimeException('the database went away');
        };

        Hooks::dailyCheck();

        self::assertCount(1, WhmcsCalls::$activity);
        self::assertStringContainsString('MainWP for WHMCS: the daily check failed', WhmcsCalls::$activity[0]);
    }
}
