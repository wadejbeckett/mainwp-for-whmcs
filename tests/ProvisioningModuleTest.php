<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\Daily;
use MainWpForWhmcs\Engine\HttpResponse;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Tests\Support\FixtureHttp;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

/**
 * The provisioning module (modules/servers/mainwp: WHMCS calls its
 * CreateAccount, SuspendAccount, UnsuspendAccount and TerminateAccount for the care add-on, with the
 * parameters the trial of 6 and 9 November recorded for an add-on's own module command: addonId (the
 * add-on row), serviceid (the parent service), userid, pid (the catalogue add-on id), serverid 0 and
 * status (the status before the action). Each answers 'success' or an error text; an error keeps the
 * add-on's status in WHMCS and holds the command in WHMCS's Module Queue. The dashboard's answers
 * are the bench's recordings.
 */
final class ProvisioningModuleTest extends TestCase
{
    private const KEY = 'bench-key-0123456789';

    private MemoryRepository $repo;
    private FixtureHttp $http;
    private bool $expectsTrouble = false;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../modules/servers/mainwp/mainwp.php';
    }

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        $this->repo->settings = ['dry_run' => '', 'dashboard_url' => 'https://dash.example.com', 'api_key' => (new WhmcsEncrypter())->encrypt(self::KEY)];
        $this->repo->addonModules = [16 => 'mainwp'];
        $this->http = new FixtureHttp();
        $repo = $this->repo;
        $http = $this->http;
        Module::$repositoryFactory = static fn (): MemoryRepository => $repo;
        Module::$httpFactory = static fn (): FixtureHttp => $http;
    }

    protected function tearDown(): void
    {
        Module::$repositoryFactory = null;
        Module::$httpFactory = null;
        Module::$siteFactory = null;
    }

    protected function assertPostConditions(): void
    {
        if (!$this->expectsTrouble) {
            self::assertSame([], WhmcsCalls::$activity, 'the module writes nothing to WHMCS\'s activity log: WHMCS writes its own row for a module command');
        }
    }

    /** Add-on row $id of client 540 on service 1176 (Demo Client 540's add-ons 81 and 82). */
    private function addon(int $id = 82, string $status = 'Active', string $serviceStatus = 'Active', int $unpaid = 0, int $catalogueId = 16): void
    {
        $this->repo->addons[$id] = new AddonRow($id, 1176, 540, $catalogueId, $status, $serviceStatus, 'demo.example.test', 'Demo Client', 'demo-client@example.com', $unpaid);
    }

    /** The care site of add-on row $id, on the dashboard as site $dashboardSiteId (null: never reached it). */
    private function careSite(int $id = 82, string $state = 'connected', ?int $dashboardSiteId = 36): void
    {
        $this->repo->careSites[$id] = [
            'addon_row_id' => $id, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://demo.example.test', 'dashboard_client_id' => 1,
            'dashboard_site_id' => $dashboardSiteId, 'state' => $state, 'retry_count' => 0, 'last_sync' => null, 'last_error' => null, 'retry_after' => null,
            'login_encrypted' => (new WhmcsEncrypter())->encrypt('{"username":"wpcare-demo","password":"Secret1"}'),
        ];
    }

    /**
     * The parameters WHMCS gives an add-on's module command (trial run log, 6 and 9 November 2026).
     *
     * @return array<string, mixed>
     */
    private static function params(int $addonRowId = 82, string $statusBefore = 'Active', int $catalogueId = 16): array
    {
        return ['addonId' => $addonRowId, 'serviceid' => 1176, 'userid' => 540, 'pid' => $catalogueId, 'serverid' => 0, 'status' => $statusBefore];
    }

    /** @return list<string> "METHOD path" of every request the dashboard was sent */
    private function sent(): array
    {
        return array_map(static fn (array $r): string => $r['method'] . ' ' . (string) preg_replace('~^.*/wp-json/mainwp/v2/~', '', $r['url']), $this->http->sent);
    }

    /** @return list<array{0: mixed, 1: mixed, 2: mixed, 3: mixed}> actor, action, target and outcome of the module's own log rows (not the dashboard calls) */
    private function logged(): array
    {
        $rows = array_filter($this->repo->log, static fn (array $r): bool => in_array($r['action'], ['provision', 'suspend', 'resume', 'remove'], true));
        return array_values(array_map(static fn (array $r): array => [$r['actor'], $r['action'], $r['target'], $r['outcome']], $rows));
    }

    private function lastDetail(): string
    {
        return (string) $this->repo->log[array_key_last($this->repo->log)]['detail'];
    }

    private static function serverError(): HttpResponse
    {
        return new HttpResponse(500, '{"code":"internal_server_error","message":"Internal Server Error"}');
    }

    // SuspendAccount

    public function testSuspendAccountSuspendsAConnectedCareSite(): void
    {
        // This shows the function works when WHMCS calls it. That WHMCS's cron calls it can only be shown on a real WHMCS.
        $this->addon();
        $this->careSite();
        $this->http->on('POST', 'sites/36/suspend', 'site-suspend');

        self::assertSame('success', \mainwp_SuspendAccount(self::params()));

        self::assertSame(['POST sites/36/suspend'], $this->sent());
        self::assertSame('suspended', $this->repo->careSites[82]['state']);
        self::assertSame([['whmcs:SuspendAccount', 'suspend', 'add-on 82', 'done']], $this->logged());
    }

    public function testSuspendAccountReturnsTheDashboardFailure(): void
    {
        $this->addon();
        $this->careSite();
        $this->http->on('POST', 'sites/36/suspend', self::serverError());

        $answer = \mainwp_SuspendAccount(self::params());

        self::assertStringStartsWith('Add-on 82 could not be suspended', $answer);
        self::assertStringNotContainsString(self::KEY, $answer);
        self::assertSame('connected', $this->repo->careSites[82]['state']);
        self::assertSame([['whmcs:SuspendAccount', 'suspend', 'add-on 82', 'failed']], $this->logged());
    }

    public function testSuspendAccountWhileBusyReturnsAnError(): void
    {
        $this->addon();
        $this->careSite();
        $this->repo->lockedElsewhere = ['addon:82'];

        $answer = \mainwp_SuspendAccount(self::params());

        self::assertSame('Add-on 82 is being connected right now; try again in a minute.', $answer);
        self::assertSame([], $this->sent());
        self::assertSame('connected', $this->repo->careSites[82]['state']);
        self::assertSame([['whmcs:SuspendAccount', 'suspend', 'add-on 82', 'failed']], $this->logged());
    }

    public function testSuspendAccountWithNothingToSuspendSucceedsSilently(): void
    {
        $this->addon();
        self::assertSame('success', \mainwp_SuspendAccount(self::params()), 'no care site');
        $this->careSite(82, 'provisioned', null);
        self::assertSame('success', \mainwp_SuspendAccount(self::params()), 'never on the dashboard');
        $this->careSite(82, 'suspended');
        self::assertSame('success', \mainwp_SuspendAccount(self::params()), 'already suspended');

        self::assertSame([[], []], [$this->sent(), $this->repo->log]);
    }

    public function testSuspendAccountInDryRunSucceedsAndOnlyLogs(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->addon();
        $this->careSite();

        self::assertSame('success', \mainwp_SuspendAccount(self::params()));

        self::assertSame([], $this->sent());
        self::assertSame('connected', $this->repo->careSites[82]['state']);
        self::assertSame([['whmcs:SuspendAccount', 'suspend', 'add-on 82', 'would run']], $this->logged());
    }

    // CreateAccount

    public function testCreateAccountProvisionsAPaidPendingCareAddon(): void
    {
        // WHMCS calls CreateAccount while the add-on row is still Pending (trial add-on 17, OBS 99-100).
        $this->addon(82, 'Pending');
        $this->http->on('GET', 'clients?page=1&per_page=100', 'clients-list-empty')->on('POST', 'clients/add', 'clients-add');

        self::assertSame('success', \mainwp_CreateAccount(self::params(82, 'Pending')));

        self::assertSame(['GET clients?page=1&per_page=100', 'POST clients/add'], $this->sent());
        self::assertSame(['provisioned', 'https://demo.example.test', 1176, 540], [$this->repo->careSites[82]['state'], $this->repo->careSites[82]['site_url'], $this->repo->careSites[82]['service_id'], $this->repo->careSites[82]['client_id']]);
        self::assertSame([['whmcs:CreateAccount', 'provision', 'add-on 82', 'done']], $this->logged());
    }

    /** Version 0.6.0: no site can be connected at no address; billing is not held up. */
    public function testCreateAccountOnAServiceWithNoDomainSucceedsAndWaits(): void
    {
        $this->repo->addons[82] = new AddonRow(82, 1176, 540, 16, 'Pending', 'Active', '', 'Demo Client', 'demo-client@example.com', 0);

        self::assertSame('success', \mainwp_CreateAccount(self::params(82, 'Pending')));

        self::assertSame([[], []], [$this->sent(), $this->repo->careSites]);
        self::assertSame([['whmcs:CreateAccount', 'provision', 'add-on 82', 'waiting']], $this->logged());
        self::assertSame('not provisioned: its hosting service has no domain, so no site can be connected', $this->lastDetail());
    }

    public function testCreateAccountWithAnUnpaidInvoiceSucceedsAndWaits(): void
    {
        $this->addon(82, 'Pending', 'Active', 1);

        self::assertSame('success', \mainwp_CreateAccount(self::params(82, 'Pending')));

        self::assertSame([[], []], [$this->sent(), $this->repo->careSites]);
        self::assertSame([['whmcs:CreateAccount', 'provision', 'add-on 82', 'waiting']], $this->logged());
        self::assertSame('not provisioned yet: it has an unpaid invoice', $this->lastDetail());
    }

    public function testCreateAccountOnAServiceThatIsNotActiveSucceedsAndWaits(): void
    {
        $this->addon(82, 'Pending', 'Pending');

        self::assertSame('success', \mainwp_CreateAccount(self::params(82, 'Pending')));

        self::assertSame([[], []], [$this->sent(), $this->repo->careSites]);
        self::assertSame([['whmcs:CreateAccount', 'provision', 'add-on 82', 'waiting']], $this->logged());
        self::assertSame('not provisioned yet: its hosting service is Pending, not Active', $this->lastDetail());
    }

    /** @return iterable<string, array{string}> */
    public static function modes(): iterable
    {
        yield 'live' => [''];
        yield 'dry run' => ['on'];
    }

    /** A Module Queue Retry of a create that went through, or a second accept. @dataProvider modes */
    public function testCreateAccountCalledAgainProvisionsOnce(string $dryRun): void
    {
        $this->addon(82, 'Pending');
        $this->http->on('GET', 'clients?page=1&per_page=100', 'clients-list-empty')->on('POST', 'clients/add', 'clients-add');
        self::assertSame('success', \mainwp_CreateAccount(self::params(82, 'Pending')));
        $this->repo->settings['dry_run'] = $dryRun;
        $sent = $this->sent();
        $log = $this->repo->log;

        self::assertSame('success', \mainwp_CreateAccount(self::params(82, 'Active')));

        self::assertSame($sent, $this->sent(), 'nothing more is sent');
        self::assertSame($log, $this->repo->log, 'nothing more is written, in dry run no "would run" row either');
    }

    public function testCreateAccountDashboardFailureSucceedsAndLogsFailed(): void
    {
        $this->addon(82, 'Pending');
        $this->http->on('GET', 'clients?page=1&per_page=100', self::serverError());

        self::assertSame('success', \mainwp_CreateAccount(self::params(82, 'Pending')), 'a sale is never left Pending');

        self::assertSame([], $this->repo->careSites);
        self::assertSame([['whmcs:CreateAccount', 'provision', 'add-on 82', 'failed']], $this->logged());

        // WHMCS sets the add-on Active; once the dashboard answers again the daily check provisions it.
        $this->addon(82, 'Active');
        $this->http->on('GET', 'sites?page=1&per_page=20', 'sites-list-empty')->on('GET', 'clients?page=1&per_page=100', 'clients-list', 'clients-list');
        Daily::run($this->repo, 'cron:daily', false);
        self::assertSame('provisioned', $this->repo->careSites[82]['state'] ?? null);
    }

    /** A fresh install starts in dry run: a paid care sale still goes Active in WHMCS. */
    public function testCreateAccountInDryRunSucceedsAndOnlyLogs(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->addon(82, 'Pending');

        self::assertSame('success', \mainwp_CreateAccount(self::params(82, 'Pending')));

        self::assertSame([[], []], [$this->sent(), $this->repo->careSites]);
        self::assertSame([['whmcs:CreateAccount', 'provision', 'add-on 82', 'would run']], $this->logged());
    }

    /** After a termination, a new create provisions the add-on again. */
    public function testCreateAccountProvisionsARemovedCareSiteAgain(): void
    {
        $this->addon(82, 'Pending');
        $this->careSite(82, 'removed', null);
        $this->http->on('GET', 'clients?page=1&per_page=100', 'clients-list-empty')->on('POST', 'clients/add', 'clients-add');

        self::assertSame('success', \mainwp_CreateAccount(self::params(82, 'Pending')));

        self::assertSame(['GET clients?page=1&per_page=100', 'POST clients/add'], $this->sent());
        self::assertSame('provisioned', $this->repo->careSites[82]['state']);
        self::assertSame([['whmcs:CreateAccount', 'provision', 'add-on 82', 'done']], $this->logged());
    }

    /** Settlement point 1: once the call is for a care add-on, a fault too leaves no sale Pending. */
    public function testCreateAccountFaultAfterTheCareCheckSucceedsAndLogsFailed(): void
    {
        $this->addon(82, 'Pending');
        Module::$siteFactory = static function (): never {
            throw new \RuntimeException('Cannot load /var/www/whmcs/modules/x.php:12');
        };

        self::assertSame('success', \mainwp_CreateAccount(self::params(82, 'Pending')), 'a sale is never left Pending');

        self::assertSame([[], []], [$this->sent(), $this->repo->careSites]);
        self::assertSame([['whmcs:CreateAccount', 'provision', 'add-on 82', 'failed']], $this->logged());
        self::assertSame('not provisioned: Cannot load (path)', $this->lastDetail());
    }

    public function testCreateAccountWithoutAddressOrKeySucceedsAndLogsFailed(): void
    {
        $this->repo->settings['dashboard_url'] = '';
        unset($this->repo->settings['api_key']);
        $this->addon(82, 'Pending');

        self::assertSame('success', \mainwp_CreateAccount(self::params(82, 'Pending')));

        self::assertSame([[], []], [$this->sent(), $this->repo->careSites]);
        self::assertSame([['whmcs:CreateAccount', 'provision', 'add-on 82', 'failed']], $this->logged());
    }

    // UnsuspendAccount

    public function testUnsuspendAccountResumesASuspendedSite(): void
    {
        // The row still reads Suspended when WHMCS calls: the call itself says it becomes Active.
        $this->addon(82, 'Suspended');
        $this->careSite(82, 'suspended');
        $this->http->on('POST', 'sites/36/unsuspend', 'site-unsuspend');

        self::assertSame('success', \mainwp_UnsuspendAccount(self::params(82, 'Suspended')));

        self::assertSame(['POST sites/36/unsuspend'], $this->sent());
        self::assertSame('connected', $this->repo->careSites[82]['state']);
        self::assertSame([['whmcs:UnsuspendAccount', 'resume', 'add-on 82', 'done']], $this->logged());
    }

    public function testUnsuspendAccountResumeFailureReturnsTheError(): void
    {
        $this->addon(82, 'Suspended');
        $this->careSite(82, 'suspended');
        $this->http->on('POST', 'sites/36/unsuspend', self::serverError());

        $answer = \mainwp_UnsuspendAccount(self::params(82, 'Suspended'));

        self::assertStringStartsWith('Add-on 82 could not be resumed', $answer, 'WHMCS keeps the add-on Suspended and holds the command for Retry');
        self::assertStringNotContainsString(self::KEY, $answer);
        self::assertSame('suspended', $this->repo->careSites[82]['state']);
        self::assertSame([['whmcs:UnsuspendAccount', 'resume', 'add-on 82', 'failed']], $this->logged());
    }

    public function testUnsuspendAccountWhileBusyReturnsAnError(): void
    {
        $this->addon(82, 'Suspended');
        $this->careSite(82, 'suspended');
        $this->repo->lockedElsewhere = ['addon:82'];

        $answer = \mainwp_UnsuspendAccount(self::params(82, 'Suspended'));

        self::assertSame('Add-on 82 is being connected right now; try again in a minute.', $answer);
        self::assertSame([], $this->sent());
        self::assertSame('suspended', $this->repo->careSites[82]['state']);
        self::assertSame([['whmcs:UnsuspendAccount', 'resume', 'add-on 82', 'failed']], $this->logged());
    }

    public function testUnsuspendAccountUnderASuspendedServiceWaits(): void
    {
        // The service's own AfterModuleUnsuspend resumes it later, through Lifecycle::service.
        $this->addon(82, 'Suspended', 'Suspended');
        $this->careSite(82, 'suspended');

        self::assertSame('success', \mainwp_UnsuspendAccount(self::params(82, 'Suspended')));

        self::assertSame([], $this->sent());
        self::assertSame('suspended', $this->repo->careSites[82]['state']);
        self::assertSame([['whmcs:UnsuspendAccount', 'resume', 'add-on 82', 'waiting']], $this->logged());
        self::assertSame('not resumed: its hosting service is Suspended, not Active', $this->lastDetail());
    }

    public function testUnsuspendAccountInDryRunSucceedsAndOnlyLogs(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->addon(82, 'Suspended');
        $this->careSite(82, 'suspended');

        self::assertSame('success', \mainwp_UnsuspendAccount(self::params(82, 'Suspended')));

        self::assertSame([], $this->sent());
        self::assertSame('suspended', $this->repo->careSites[82]['state']);
        self::assertSame([['whmcs:UnsuspendAccount', 'resume', 'add-on 82', 'would run']], $this->logged());
    }

    /** @return iterable<string, array{string}> */
    public static function serviceStatuses(): iterable
    {
        yield 'service Active' => ['Active'];
        yield 'service Suspended' => ['Suspended'];
    }

    /** @dataProvider serviceStatuses */
    public function testUnsuspendAccountOnASiteThatIsNotSuspendedDoesNothing(string $serviceStatus): void
    {
        $this->addon(82, 'Suspended', $serviceStatus);
        self::assertSame('success', \mainwp_UnsuspendAccount(self::params(82, 'Suspended')), 'no care site');
        $this->careSite();
        self::assertSame('success', \mainwp_UnsuspendAccount(self::params(82, 'Suspended')), 'connected');
        $this->careSite(82, 'removed', null);
        self::assertSame('success', \mainwp_UnsuspendAccount(self::params(82, 'Suspended')), 'removed');

        self::assertSame([[], []], [$this->sent(), $this->repo->log]);
    }

    // TerminateAccount

    public function testTerminateAccountRemovesTheSite(): void
    {
        $this->addon();
        $this->careSite();
        $this->http->on('DELETE', 'sites/36/remove', 'site-remove');

        self::assertSame('success', \mainwp_TerminateAccount(self::params()));

        self::assertSame(['DELETE sites/36/remove'], $this->sent());
        self::assertSame(['removed', null], [$this->repo->careSites[82]['state'], $this->repo->careSites[82]['login_encrypted']]);
        self::assertSame([['whmcs:TerminateAccount', 'remove', 'add-on 82', 'done']], $this->logged());
    }

    public function testTerminateAccountWithoutACareSiteSucceedsSilently(): void
    {
        $this->addon();

        self::assertSame('success', \mainwp_TerminateAccount(self::params()));

        self::assertSame([[], []], [$this->sent(), $this->repo->log]);
    }

    public function testTerminateAccountDashboardFailureReturnsTheErrorAndKeepsTheSiteAndLogin(): void
    {
        $this->addon();
        $this->careSite();
        $this->http->on('DELETE', 'sites/36/remove', self::serverError());

        $answer = \mainwp_TerminateAccount(self::params());

        self::assertStringStartsWith('Add-on 82 could not be removed', $answer, 'WHMCS keeps the add-on\'s status and holds the command for Retry');
        self::assertStringNotContainsString(self::KEY, $answer);
        self::assertSame('connected', $this->repo->careSites[82]['state']);
        self::assertNotNull($this->repo->careSites[82]['login_encrypted']);
        self::assertSame([['whmcs:TerminateAccount', 'remove', 'add-on 82', 'failed']], $this->logged());
    }

    public function testTerminateAccountWhoseLookupFailsReturnsTheErrorAndKeepsTheSiteAndLogin(): void
    {
        // A connect was tried and its answer never came: the dashboard is asked whether it holds the site.
        $this->addon();
        $this->careSite(82, 'failed', null);
        $this->http->on('GET', 'sites?page=1&per_page=20', self::serverError());

        $answer = \mainwp_TerminateAccount(self::params());

        self::assertStringStartsWith('Add-on 82 could not be removed', $answer);
        self::assertStringNotContainsString(self::KEY, $answer);
        self::assertSame('failed', $this->repo->careSites[82]['state']);
        self::assertNotNull($this->repo->careSites[82]['login_encrypted']);
        self::assertSame([['whmcs:TerminateAccount', 'remove', 'add-on 82', 'failed']], $this->logged());
    }

    public function testTerminateAccountInDryRunSucceedsAndOnlyLogs(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->addon();
        $this->careSite();

        self::assertSame('success', \mainwp_TerminateAccount(self::params()));

        self::assertSame([], $this->sent());
        self::assertSame('connected', $this->repo->careSites[82]['state']);
        self::assertNotNull($this->repo->careSites[82]['login_encrypted']);
        self::assertSame([['whmcs:TerminateAccount', 'remove', 'add-on 82', 'would run']], $this->logged());
    }

    public function testTerminateAccountWhileBusyReturnsAnError(): void
    {
        $this->addon();
        $this->careSite();
        $this->repo->lockedElsewhere = ['addon:82'];

        $answer = \mainwp_TerminateAccount(self::params());

        self::assertSame('Add-on 82 is being connected right now; try again in a minute.', $answer);
        self::assertSame([], $this->sent());
        self::assertSame('connected', $this->repo->careSites[82]['state']);
        self::assertNotNull($this->repo->careSites[82]['login_encrypted']);
        self::assertSame([['whmcs:TerminateAccount', 'remove', 'add-on 82', 'failed']], $this->logged());
    }

    // Refusals and faults, alike for all four

    /** @return iterable<string, array{string, string}> */
    public static function commands(): iterable
    {
        yield 'CreateAccount' => ['mainwp_CreateAccount', 'create'];
        yield 'SuspendAccount' => ['mainwp_SuspendAccount', 'suspend'];
        yield 'UnsuspendAccount' => ['mainwp_UnsuspendAccount', 'unsuspend'];
        yield 'TerminateAccount' => ['mainwp_TerminateAccount', 'terminate'];
    }

    /** The module set on a product by mistake: a service's own call carries addonId 0. @dataProvider commands */
    public function testEachCommandRefusesAProductsCall(string $function, string $verb): void
    {
        $this->addon();
        $this->careSite();
        $text = 'MainWP for WHMCS works only on a product add-on (the care add-on\'s Module Settings), not on a product.';

        self::assertSame($text, $function(['addonId' => 0] + self::params()));
        $missing = self::params();
        unset($missing['addonId']);
        self::assertSame($text, $function($missing));

        self::assertSame([[], []], [$this->sent(), $this->repo->log]);
    }

    /**
     * Deactivating the add-on module removes its settings rows and keeps its tables: each command then
     * answers an error, so that WHMCS keeps the status and holds the command in its Module Queue.
     * @dataProvider commands
     */
    public function testEachCommandAnswersAnErrorWhileTheAddOnModuleIsNotActive(string $function, string $verb): void
    {
        $this->addon();
        $this->careSite();
        $this->repo->settings = [];
        $text = 'The add-on module MainWP for WHMCS is not active.';

        self::assertSame($text, $function(self::params()));
        self::assertSame($text, $function(['addonId' => 0] + self::params()), 'before anything else is decided');
        self::assertSame($text, $function(self::params(99)));
        self::assertSame([[], []], [$this->sent(), $this->repo->log]);
        self::assertSame('connected', $this->repo->careSites[82]['state']);
        self::assertNotNull($this->repo->careSites[82]['login_encrypted']);
    }

    /** WHMCS calls the module only for a product add-on whose Module Settings name it. @dataProvider commands */
    public function testEachCommandWorksForAnAddonThatNamesTheModuleWithNoStoredList(string $function, string $verb): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->repo->addonModules = [7 => 'mainwp'];
        self::assertArrayNotHasKey('care_addon_ids', $this->repo->settings);
        $this->addon(83, 'Active', 'Active', 0, 7);
        if ($verb !== 'create') {
            $this->careSite(83, $verb === 'unsuspend' ? 'suspended' : 'connected');
        }

        self::assertSame('success', $function(self::params(83, 'Active', 7)));

        self::assertSame([], $this->sent(), 'dry run');
        self::assertSame(['would run'], array_column($this->repo->log, 'outcome'));
    }

    /** No refusal is left for an add-on the module does not count: WHMCS would not call it for one. @dataProvider commands */
    public function testNoCommandAnswersThatAnAddonIsNotACareAddon(string $function, string $verb): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->addon(83, 'Active', 'Active', 0, 12);
        $this->careSite(83, $verb === 'unsuspend' ? 'suspended' : 'connected');

        self::assertStringNotContainsString('is not a care add-on', $function(self::params(83, 'Active', 12)));
    }

    /** An install whose old stored list is "16" and whose product add-on 16 names the module. @dataProvider commands */
    public function testTheLiveInstallsCareAddonIsServedAsBefore(string $function, string $verb): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->repo->settings['care_addon_ids'] = '16';
        $this->repo->addonModules = [16 => 'mainwp'];
        $this->addon();
        if ($verb !== 'create') {
            $this->careSite(82, $verb === 'unsuspend' ? 'suspended' : 'connected');
        }

        self::assertSame('success', $function(self::params()));
        self::assertSame(['would run'], array_column($this->repo->log, 'outcome'));
    }

    /** @dataProvider commands */
    public function testEachCommandRefusesAnUnknownRow(string $function, string $verb): void
    {
        self::assertSame('Add-on 99 was not found in WHMCS.', $function(self::params(99)));
        self::assertSame([[], []], [$this->sent(), $this->repo->log]);
    }

    /** Tables missing because the add-on module is deactivated, the database, a fault in the code. @dataProvider commands */
    public function testAProblemBecomesAnErrorTextAndNeverEscapes(string $function, string $verb): void
    {
        Module::$repositoryFactory = static function (): MemoryRepository {
            throw new \RuntimeException('Table mod_mainwp_sites doesn\'t exist in /var/www/whmcs/vendor/x.php:12');
        };

        $answer = $function(self::params());

        self::assertSame('MainWP for WHMCS could not ' . $verb . ' care for add-on 82: Table mod_mainwp_sites doesn\'t exist in (path)', $answer);
        self::assertStringNotContainsString('/var/www', $answer, 'no file path reaches WHMCS');
    }

    /** @return iterable<string, array{string, string}> */
    public static function brokenAddonFolders(): iterable
    {
        yield 'missing' => ['missing', 'MainWP for WHMCS: the add-on module\'s files are missing; nothing was done.'];
        yield 'half copied (no lib/Daily.php)' => ['incomplete', 'MainWP for WHMCS: the add-on module\'s files are incomplete or of another version; nothing was done.'];
        yield 'an older version (no Provisioning)' => ['older', 'MainWP for WHMCS: the add-on module\'s files are incomplete or of another version; nothing was done.'];
    }

    /**
     * WHMCS loads the server file in the cron's Overdue Suspensions: an add-on folder that is missing,
     * half copied or of an older version beside it must give an error text, never a fatal error that
     * ends the cron run. Run in its own PHP process, as WHMCS would. @dataProvider brokenAddonFolders
     */
    public function testTheServerFileAnswersWhenTheAddonFolderIsBroken(string $case, string $text): void
    {
        $root = sys_get_temp_dir() . '/mainwp-server-' . bin2hex(random_bytes(6));
        try {
            mkdir($root . '/modules/servers/mainwp', 0700, true);
            copy(__DIR__ . '/../modules/servers/mainwp/mainwp.php', $root . '/modules/servers/mainwp/mainwp.php');
            if ($case !== 'missing') {
                self::copyFolder(__DIR__ . '/../modules/addons/mainwp', $root . '/modules/addons/mainwp');
                $lib = $root . '/modules/addons/mainwp/lib/';
                if ($case === 'incomplete') {
                    unlink($lib . 'Daily.php');
                } else {
                    unlink($lib . 'Provisioning.php');
                    file_put_contents($lib . 'Bootstrap.php', str_replace("require_once __DIR__ . '/Provisioning.php';\n", '', (string) file_get_contents($lib . 'Bootstrap.php')));
                }
            }
            foreach (['CreateAccount', 'SuspendAccount', 'UnsuspendAccount', 'TerminateAccount'] as $function) {
                // Each command in a process of its own: WHMCS's cron calls one and goes on to its next task.
                $code = 'define("WHMCS", true); require ' . var_export($root . '/modules/servers/mainwp/mainwp.php', true) . '; echo mainwp_' . $function . '(["addonId" => 5]); echo "|done";';
                $out = [];
                exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -d log_errors=0 -r ' . escapeshellarg($code), $out, $exit);

                self::assertSame([0, [$text . '|done']], [$exit, $out], $function . ': no fatal error, and the error text');
            }
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }

    private static function copyFolder(string $from, string $to): void
    {
        mkdir($to, 0700, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $file) {
            $target = $to . '/' . substr($file->getPathname(), strlen($from) + 1);
            $file->isDir() ? mkdir($target, 0700, true) : copy($file->getPathname(), $target);
        }
    }

    public function testMetaData(): void
    {
        self::assertSame(['DisplayName' => Module::DISPLAY_NAME, 'APIVersion' => '1.1', 'RequiresServer' => false], \mainwp_MetaData());
        self::assertSame('MainWP for WHMCS', \mainwp_MetaData()['DisplayName']);
    }
}
