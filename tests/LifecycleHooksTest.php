<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\Hooks;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Tests\Support\FixtureHttp;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

/**
 * Suspend, resume and remove as WHMCS's events reach the module, with the parameters
 * the trial of 6 and 9 November recorded: an add-on event carries id (the add-on row), userid,
 * serviceid and addonid (the catalogue id), AddonDeleted only id; a service's module event carries
 * the module parameters in 'params', with addonId 0 for the service itself and an add-on's row id
 * for that add-on's own module commands. The dashboard's answers are the bench's recordings.
 */
final class LifecycleHooksTest extends TestCase
{
    private MemoryRepository $repo;
    private FixtureHttp $http;
    private bool $expectsTrouble = false;

    /** Add-on 67 (WordPress Care, catalogue id 16) on service 1176 of client 540 (trial run log). */
    private const ADDON = ['id' => 67, 'userid' => 540, 'serviceid' => 1176, 'addonid' => 16];
    /** AfterModuleSuspend of service 1176 itself (trial log row 8, 6 November); the status is still the one before the command. */
    private const SERVICE = ['params' => ['serviceid' => 1176, 'userid' => 540, 'pid' => 116, 'serverid' => 5, 'addonId' => 0, 'status' => 'Active']];
    /** AfterModuleSuspend of another add-on's own module command on the same service (trial log row 44, 9 November). */
    private const OTHER_ADDON = ['params' => ['serviceid' => 1176, 'userid' => 540, 'pid' => 17, 'serverid' => 10, 'addonId' => 68, 'status' => 'Active']];

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        $this->repo->settings = ['dry_run' => '', 'dashboard_url' => 'https://dash.example.com', 'api_key' => (new WhmcsEncrypter())->encrypt('bench-key-0123456789')];
        $this->repo->addonModules = [16 => 'mainwp'];
        $this->careSite(67, 1176, 1, 'clientsite.example');
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
    }

    /** A hook swallows whatever goes wrong and writes it to WHMCS's activity log: a test that expects none says so by passing. */
    protected function assertPostConditions(): void
    {
        if (!$this->expectsTrouble) {
            self::assertSame([], WhmcsCalls::$activity, 'nothing went wrong inside a hook');
        }
    }

    /** A care add-on row with its care site, connected as dashboard site $dashboardSiteId. */
    private function careSite(int $addonRowId, int $serviceId, int $dashboardSiteId, string $domain, string $state = 'connected', string $addonStatus = 'Active', string $serviceStatus = 'Active'): void
    {
        $this->repo->addons[$addonRowId] = new AddonRow($addonRowId, $serviceId, 540, 16, $addonStatus, $serviceStatus, $domain, 'Demo Client', 'demo-client@example.com', 0);
        $this->repo->careSites[$addonRowId] = [
            'addon_row_id' => $addonRowId, 'service_id' => $serviceId, 'client_id' => 540, 'site_url' => 'https://' . $domain, 'dashboard_client_id' => 1,
            'dashboard_site_id' => $dashboardSiteId, 'state' => $state, 'retry_count' => 0, 'last_sync' => '2026-11-09 15:55:13', 'last_error' => null, 'retry_after' => null,
            'login_encrypted' => (new WhmcsEncrypter())->encrypt('{"username":"owner","password":"Secret1"}'),
        ];
    }

    /** @return list<string> "METHOD path" of every request the dashboard was sent */
    private function sent(): array
    {
        return array_map(static fn (array $r): string => $r['method'] . ' ' . (string) preg_replace('~^.*/wp-json/mainwp/v2/~', '', $r['url']), $this->http->sent);
    }

    /** @return list<array{0: mixed, 1: mixed, 2: mixed}> action, target and outcome of the module's own log rows (not the dashboard calls) */
    private function logged(): array
    {
        $rows = array_filter($this->repo->log, static fn (array $r): bool => in_array($r['action'], ['suspend', 'resume', 'remove'], true));
        return array_values(array_map(static fn (array $r): array => [$r['action'], $r['target'], $r['outcome']], $rows));
    }

    public function testTheHooksFileRegistersTheEventsThatSuspendResumeAndRemove(): void
    {
        require __DIR__ . '/../modules/addons/mainwp/hooks.php';
        $names = array_column(WhmcsCalls::$hooks, 'hook');
        foreach (['AddonSuspended', 'AddonActivated', 'AddonUnsuspended', 'AddonTerminated', 'AddonCancelled', 'AddonDeleted', 'AfterModuleSuspend', 'AfterModuleUnsuspend', 'AfterModuleTerminate'] as $hook) {
            self::assertContains($hook, $names);
        }
    }

    public function testEveryRegisteredEventReachesItsHandler(): void
    {
        require __DIR__ . '/../modules/addons/mainwp/hooks.php';
        $fire = static function (string $hook, array $vars): void {
            foreach (WhmcsCalls::$hooks as $registered) {
                if ($registered['hook'] === $hook) {
                    ($registered['callable'])($vars);
                }
            }
        };
        $this->http->on('POST', 'sites/1/suspend', 'site-suspend', 'site-suspend', 'site-suspend')->on('POST', 'sites/1/unsuspend', 'site-unsuspend', 'site-unsuspend', 'site-unsuspend')
            ->on('DELETE', 'sites/1/remove', 'site-remove', 'site-remove', 'site-remove', 'site-remove');

        $fire('AddonSuspended', self::ADDON);
        self::assertSame('suspended', $this->repo->careSites[67]['state']);
        $fire('AddonActivated', self::ADDON);
        self::assertSame('connected', $this->repo->careSites[67]['state']);
        $fire('AddonSuspended', self::ADDON);
        // WHMCS documents this event for an unsuspension; the trial never saw it fire, and it is heard in case it does.
        $fire('AddonUnsuspended', self::ADDON);
        self::assertSame('connected', $this->repo->careSites[67]['state']);
        $fire('AfterModuleSuspend', self::SERVICE);
        self::assertSame('suspended', $this->repo->careSites[67]['state']);
        $fire('AfterModuleUnsuspend', self::SERVICE);
        self::assertSame('connected', $this->repo->careSites[67]['state']);
        foreach (['AddonTerminated', 'AddonCancelled', 'AddonDeleted'] as $hook) {
            $this->careSite(67, 1176, 1, 'clientsite.example');
            $fire($hook, $hook === 'AddonDeleted' ? ['id' => 67] : self::ADDON);
            self::assertSame('removed', $this->repo->careSites[67]['state'], $hook);
        }
        $this->careSite(67, 1176, 1, 'clientsite.example');
        $fire('AfterModuleTerminate', self::SERVICE);
        self::assertSame('removed', $this->repo->careSites[67]['state']);
    }

    public function testAddonSuspendedSuspendsTheSiteOnTheDashboard(): void
    {
        $this->http->on('POST', 'sites/1/suspend', 'site-suspend');

        Hooks::addonSuspended(self::ADDON);

        self::assertSame(['POST sites/1/suspend'], $this->sent());
        self::assertSame('suspended', $this->repo->careSites[67]['state']);
        self::assertSame([['suspend', 'add-on 67', 'done']], $this->logged());
        self::assertSame('whmcs:AddonSuspended', $this->repo->log[array_key_last($this->repo->log)]['actor']);
        self::assertNotNull($this->repo->careSites[67]['login_encrypted'], 'the login stays until the care site is removed');
    }

    public function testAddonActivatedResumesASuspendedSiteWhenTheAddonAndItsServiceAreActive(): void
    {
        $this->careSite(67, 1176, 1, 'clientsite.example', 'suspended');
        $this->http->on('POST', 'sites/1/unsuspend', 'site-unsuspend');

        Hooks::addonEvent('AddonActivated', self::ADDON);

        self::assertSame(['POST sites/1/unsuspend'], $this->sent());
        self::assertSame('connected', $this->repo->careSites[67]['state']);
        self::assertSame([['resume', 'add-on 67', 'done']], $this->logged());
    }

    public function testAddonActivatedResumesAlthoughTheRowStillReadsSuspended(): void
    {
        // Whether WHMCS has written the new status when the event fires was not seen in the trial: the event itself says the add-on is Active.
        $this->careSite(67, 1176, 1, 'clientsite.example', 'suspended', 'Suspended');
        $this->http->on('POST', 'sites/1/unsuspend', 'site-unsuspend');

        Hooks::addonEvent('AddonActivated', self::ADDON);

        self::assertSame('connected', $this->repo->careSites[67]['state']);
    }

    public function testAddonActivatedOnASiteThatIsNotSuspendedSendsNothing(): void
    {
        // The event fires at every return to Active and when an order is accepted.
        Hooks::addonEvent('AddonActivated', self::ADDON);

        self::assertSame([], $this->sent());
        self::assertSame('connected', $this->repo->careSites[67]['state']);
        self::assertSame([], $this->logged());
    }

    public function testAddonActivatedDoesNotResumeWhileTheHostingServiceIsSuspended(): void
    {
        $this->careSite(67, 1176, 1, 'clientsite.example', 'suspended', 'Active', 'Suspended');

        Hooks::addonEvent('AddonActivated', self::ADDON);

        self::assertSame([], $this->sent());
        self::assertSame('suspended', $this->repo->careSites[67]['state']);
        self::assertSame([['resume', 'add-on 67', 'waiting']], $this->logged());
        self::assertStringContainsString('its hosting service is Suspended, not Active', (string) $this->repo->log[array_key_last($this->repo->log)]['detail']);
    }

    public function testAPaidInvoiceResumesASuspendedSiteWhoseAddonIsActiveAgain(): void
    {
        // Which event WHMCS fires when a payment lifts a suspension was not seen in the trial: both lead to the same decision.
        $this->careSite(67, 1176, 1, 'clientsite.example', 'suspended');
        $this->repo->invoiceAddons[60500] = [67];
        $this->http->on('POST', 'sites/1/unsuspend', 'site-unsuspend');

        Hooks::invoicePaid(['invoiceid' => 60500]);

        self::assertSame('connected', $this->repo->careSites[67]['state']);
    }

    public function testAPaidInvoiceDoesNotResumeAnAddonThatIsStillSuspended(): void
    {
        $this->careSite(67, 1176, 1, 'clientsite.example', 'suspended', 'Suspended');
        $this->repo->invoiceAddons[60500] = [67];

        Hooks::invoicePaid(['invoiceid' => 60500]);

        self::assertSame([], $this->sent());
        self::assertSame('suspended', $this->repo->careSites[67]['state']);
    }

    /** @return iterable<string, array{string, array<string, int>}> */
    public static function endings(): iterable
    {
        yield 'AddonTerminated' => ['AddonTerminated', self::ADDON];
        yield 'AddonCancelled' => ['AddonCancelled', self::ADDON];
        yield 'AddonDeleted carries only the row id' => ['AddonDeleted', ['id' => 67]];
    }

    /**
     * @param array<string, int> $vars
     * @dataProvider endings
     */
    public function testAnAddonThatEndsIsRemovedFromTheDashboardWithItsStoredLoginAndItsRetry(string $hook, array $vars): void
    {
        $this->repo->careSites[67]['retry_after'] = '2026-11-11 09:00:00';
        $this->repo->careSites[67]['retry_count'] = 3;
        if ($hook === 'AddonDeleted') {
            unset($this->repo->addons[67]); // WHMCS has deleted the row by the time the event fires
        }
        $this->http->on('DELETE', 'sites/1/remove', 'site-remove');

        Hooks::addonEnded($hook, $vars);

        self::assertSame(['DELETE sites/1/remove'], $this->sent());
        $site = $this->repo->careSites[67];
        self::assertSame(['removed', null, null, 0, null], [$site['state'], $site['login_encrypted'], $site['retry_after'], $site['retry_count'], $site['dashboard_site_id']]);
        self::assertSame([['remove', 'add-on 67', 'done']], $this->logged());
        self::assertSame('whmcs:' . $hook, $this->repo->log[array_key_last($this->repo->log)]['actor']);
    }

    public function testADashboardAddressThatAnswersWithoutMainWpDoesNotMakeTheModuleBelieveTheSiteHasGone(): void
    {
        $noRoute = new \MainWpForWhmcs\Engine\HttpResponse(404, '{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}');
        $this->http->on('DELETE', 'sites/1/remove', $noRoute);

        Hooks::addonEnded('AddonCancelled', self::ADDON);

        $site = $this->repo->careSites[67];
        self::assertSame(['connected', 1], [$site['state'], $site['dashboard_site_id']], 'the site may well still be on the dashboard: nothing is recorded as removed');
        self::assertNotNull($site['login_encrypted'], 'and the login it would take to act on it again is kept');
        self::assertSame([['remove', 'add-on 67', 'failed']], $this->logged());
    }

    public function testAStagingSiteIsRemovedAsMainWpRemovesItAndTheLogSaysSo(): void
    {
        // The dashboard's recorded answer to removing a site flagged as staging (bench, sixth run).
        $this->http->on('DELETE', 'sites/1/remove', 'site-remove-staging');

        Hooks::addonEnded('AddonCancelled', self::ADDON);

        self::assertSame('removed', $this->repo->careSites[67]['state']);
        self::assertStringContainsString('MainWP did not switch its plugin off on the site, as for a site it has flagged as staging, and says: "The site has been removed. Please make sure that the MainWP Child plugin has been deactivated properly"', (string) $this->repo->log[array_key_last($this->repo->log)]['detail']);
    }

    public function testASiteWhoseConnectingAdministratorIsGoneIsStillRemoved(): void
    {
        // MainWP answers the removal with an error from the site and drops the row all the same (bench, sixth run).
        $this->http->on('DELETE', 'sites/1/remove', 'site-remove-dead')->on('GET', 'sites/1', 'site-get-unknown');

        Hooks::addonEnded('AddonCancelled', self::ADDON);

        self::assertSame(['DELETE sites/1/remove', 'GET sites/1'], $this->sent());
        $site = $this->repo->careSites[67];
        self::assertSame(['removed', null], [$site['state'], $site['login_encrypted']]);
        self::assertSame([['remove', 'add-on 67', 'done']], $this->logged());
        self::assertStringContainsString('MainWP could not switch its plugin off on the site', (string) $this->repo->log[array_key_last($this->repo->log)]['detail']);
    }

    public function testAnEventForAnAddonWithoutACareSiteDoesNothing(): void
    {
        // Another product's add-on, or a care add-on that was never provisioned.
        $other = ['id' => 68, 'userid' => 540, 'serviceid' => 1176, 'addonid' => 17];

        Hooks::addonSuspended($other);
        Hooks::addonEvent('AddonActivated', $other);
        Hooks::addonEnded('AddonCancelled', $other);
        Hooks::addonEnded('AddonDeleted', ['id' => 68]);

        self::assertSame([[], []], [$this->sent(), $this->repo->log]);
    }

    public function testAServiceSuspendedByItsModuleSuspendsEveryCareSiteUnderItAndNoOther(): void
    {
        $this->careSite(69, 1176, 2, 'shop.clientsite.example');
        $this->careSite(70, 1200, 3, 'elsewhere.example');
        $this->http->on('POST', 'sites/1/suspend', 'site-suspend')->on('POST', 'sites/2/suspend', 'site-suspend');

        Hooks::serviceEvent('AfterModuleSuspend', self::SERVICE);

        self::assertSame(['POST sites/1/suspend', 'POST sites/2/suspend'], $this->sent());
        self::assertSame(['suspended', 'suspended', 'connected'], [$this->repo->careSites[67]['state'], $this->repo->careSites[69]['state'], $this->repo->careSites[70]['state']]);
        self::assertSame('whmcs:AfterModuleSuspend', $this->repo->log[array_key_last($this->repo->log)]['actor']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function notTheServiceItself(): iterable
    {
        yield 'another add-on\'s own module command' => [self::OTHER_ADDON];
        yield 'another add-on, its row id as text' => [['params' => ['serviceid' => 1176, 'addonId' => '68']]];
        yield 'a service without care sites that names no add-on' => [['params' => ['serviceid' => 1300, 'userid' => 540, 'pid' => 116]]];
    }

    /**
     * @param array<string, mixed> $vars
     * @dataProvider notTheServiceItself
     */
    public function testAModuleEventThatIsNotAboutTheServiceItselfIsIgnored(array $vars): void
    {
        foreach (['AfterModuleSuspend', 'AfterModuleUnsuspend', 'AfterModuleTerminate'] as $hook) {
            Hooks::serviceEvent($hook, $vars);
        }

        self::assertSame([[], []], [$this->sent(), $this->repo->log]);
        self::assertSame('connected', $this->repo->careSites[67]['state']);
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function doesNotSayWhatItIsAbout(): iterable
    {
        // WHMCS fired a module event with no parameters when a command was retried from its Module Queue (trial log rows 30 and 32, 9 November).
        $queue = 'AfterModuleSuspend did not say which service or add-on it was for (WHMCS does this when it retries a command from its Module Queue), so no care site was changed.';
        yield 'no parameters at all' => [[], 'a hosting service', $queue];
        yield 'parameters without ids' => [['params' => ['model' => null]], 'a hosting service', $queue];
        $named = 'AfterModuleSuspend named service 1176 but not whether it was for the service itself or one of its add-ons, so no care site was changed. Check the care add-ons of service 1176.';
        yield 'a service with care sites, but no addonId' => [['params' => ['serviceid' => 1176, 'userid' => 540, 'pid' => 116]], 'service 1176', $named];
        yield 'an addonId that is empty' => [['params' => ['serviceid' => 1176, 'addonId' => '']], 'service 1176', $named];
        yield 'the service itself, but no service id' => [['params' => ['addonId' => 0]], 'a hosting service', 'AfterModuleSuspend did not say which service it was for, so no care site was changed.'];
    }

    /**
     * @param array<string, mixed> $vars
     * @dataProvider doesNotSayWhatItIsAbout
     */
    public function testAModuleEventThatDoesNotSayWhatItIsAboutChangesNothingAndLeavesATrace(array $vars, string $target, string $detail): void
    {
        foreach (['AfterModuleSuspend', 'AfterModuleUnsuspend', 'AfterModuleTerminate'] as $hook) {
            Hooks::serviceEvent($hook, $vars);
        }

        self::assertSame([], $this->sent());
        self::assertSame('connected', $this->repo->careSites[67]['state']);
        self::assertSame([['suspend', $target, 'failed'], ['resume', $target, 'failed'], ['remove', $target, 'failed']], $this->logged(), 'a suspension or an ending that may not have reached a care site must not vanish');
        self::assertStringStartsWith($detail, (string) $this->repo->log[0]['detail'], 'the log says what did arrive, and blames the Module Queue only for an event with no ids at all');
        self::assertSame('whmcs:AfterModuleSuspend', $this->repo->log[0]['actor']);
    }

    public function testAServiceUnsuspendedByItsModuleResumesOnlyTheSitesWhoseOwnAddonIsActive(): void
    {
        $this->careSite(67, 1176, 1, 'clientsite.example', 'suspended', 'Active', 'Suspended');
        $this->careSite(69, 1176, 2, 'shop.clientsite.example', 'suspended', 'Suspended', 'Suspended');
        $this->http->on('POST', 'sites/1/unsuspend', 'site-unsuspend');

        // The event's own status is still the one before the command, and so may the service's row be.
        Hooks::serviceEvent('AfterModuleUnsuspend', ['params' => ['serviceid' => 1176, 'userid' => 540, 'pid' => 116, 'serverid' => 5, 'addonId' => 0, 'status' => 'Suspended']]);

        self::assertSame(['POST sites/1/unsuspend'], $this->sent());
        self::assertSame(['connected', 'suspended'], [$this->repo->careSites[67]['state'], $this->repo->careSites[69]['state']]);
        self::assertSame([['resume', 'add-on 67', 'done'], ['resume', 'add-on 69', 'waiting']], $this->logged());
        self::assertStringContainsString('the add-on is Suspended, not Active', (string) $this->repo->log[array_key_last($this->repo->log)]['detail']);
    }

    public function testAServiceTerminatedByItsModuleRemovesItsCareSites(): void
    {
        $this->careSite(69, 1176, 2, 'shop.clientsite.example', 'suspended');
        $this->careSite(70, 1200, 3, 'elsewhere.example');
        $this->http->on('DELETE', 'sites/1/remove', 'site-remove')->on('DELETE', 'sites/2/remove', 'site-remove');

        Hooks::serviceEvent('AfterModuleTerminate', self::SERVICE);

        self::assertSame(['DELETE sites/1/remove', 'DELETE sites/2/remove'], $this->sent());
        self::assertSame(['removed', 'removed', 'connected'], [$this->repo->careSites[67]['state'], $this->repo->careSites[69]['state'], $this->repo->careSites[70]['state']]);
        self::assertSame([null, null], [$this->repo->careSites[67]['login_encrypted'], $this->repo->careSites[69]['login_encrypted']]);
    }

    public function testOneSiteTheDashboardRefusesDoesNotStopTheOthersOfTheService(): void
    {
        $this->careSite(69, 1176, 2, 'shop.clientsite.example');
        $this->http->on('POST', 'sites/1/suspend', 'site-suspend-unknown')->on('POST', 'sites/2/suspend', 'site-suspend');

        Hooks::serviceEvent('AfterModuleSuspend', self::SERVICE);

        self::assertSame(['connected', 'suspended'], [$this->repo->careSites[67]['state'], $this->repo->careSites[69]['state']]);
        self::assertSame([['suspend', 'add-on 67', 'failed'], ['suspend', 'add-on 69', 'done']], $this->logged());
    }

    public function testInDryRunTheEventsAreLoggedAndNothingIsSentOrChanged(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->careSite(69, 1176, 2, 'shop.clientsite.example', 'suspended');
        $before = $this->repo->careSites;

        Hooks::addonSuspended(self::ADDON);
        Hooks::addonEvent('AddonActivated', ['id' => 69, 'userid' => 540, 'serviceid' => 1176, 'addonid' => 16]);
        Hooks::addonEnded('AddonCancelled', self::ADDON);
        Hooks::serviceEvent('AfterModuleSuspend', self::SERVICE);
        Hooks::serviceEvent('AfterModuleTerminate', self::SERVICE);

        self::assertSame([], $this->sent());
        self::assertSame($before, $this->repo->careSites);
        self::assertSame(
            [['suspend', 'add-on 67', 'would run'], ['resume', 'add-on 69', 'would run'], ['remove', 'add-on 67', 'would run'], ['suspend', 'add-on 67', 'would run'], ['remove', 'add-on 67', 'would run'], ['remove', 'add-on 69', 'would run']],
            $this->logged(),
        );
        self::assertSame([1], array_values(array_unique(array_column($this->repo->log, 'dry_run'))));
    }

    public function testNoEventChangesAnythingInWhmcsOrDeletesADashboardClient(): void
    {
        $this->careSite(69, 1176, 2, 'shop.clientsite.example', 'suspended');
        $statements = count($this->repo->executed);
        $this->http->on('POST', 'sites/1/suspend', 'site-suspend')->on('POST', 'sites/1/unsuspend', 'site-unsuspend')->on('POST', 'sites/2/unsuspend', 'site-unsuspend')
            ->on('DELETE', 'sites/1/remove', 'site-remove')->on('DELETE', 'sites/2/remove', 'site-remove');

        Hooks::addonSuspended(self::ADDON);
        Hooks::addonEvent('AddonActivated', self::ADDON);
        Hooks::serviceEvent('AfterModuleUnsuspend', self::SERVICE);
        Hooks::addonEnded('AddonCancelled', self::ADDON);
        Hooks::serviceEvent('AfterModuleTerminate', self::SERVICE);

        self::assertSame(['removed', 'removed'], [$this->repo->careSites[67]['state'], $this->repo->careSites[69]['state']]);
        self::assertSame([], array_values(array_diff(array_column(WhmcsCalls::$api, 'command'), ['EncryptPassword', 'DecryptPassword'])), 'no WHMCS API command but its own encryption: no status, price or date is changed');
        self::assertSame([], array_values(array_filter(array_slice($this->repo->executed, $statements), static fn (string $sql): bool => preg_match('/\btbl[a-z_]+/i', $sql) === 1)), 'no statement touches a table of WHMCS');
        foreach ($this->sent() as $request) {
            self::assertStringNotContainsString('clients', $request, 'the dashboard client stays, whatever happens to its sites');
        }
    }

    public function testAnEventNeverStopsWhmcsWhateverGoesWrongInTheModule(): void
    {
        $this->expectsTrouble = true;
        Module::$repositoryFactory = static function (): MemoryRepository {
            throw new \RuntimeException('the database went away at /var/www/whmcs/vendor/x.php');
        };

        Hooks::addonSuspended(self::ADDON);
        Hooks::addonEnded('AddonDeleted', ['id' => 67]);
        Hooks::serviceEvent('AfterModuleTerminate', self::SERVICE);

        self::assertCount(3, WhmcsCalls::$activity);
        self::assertStringContainsString('MainWP for WHMCS: AddonSuspended for add-on 67 failed', WhmcsCalls::$activity[0]);
        self::assertStringNotContainsString('/var/www', implode(' ', WhmcsCalls::$activity), 'no file path reaches the activity log');
    }

    /** The care add-on's own module command: the provisioning module acts, and the hook that follows names the add-on and does nothing more. */
    public function testACareAddonsOwnCommandIsNotActedOnAgainByTheHook(): void
    {
        require_once __DIR__ . '/../modules/servers/mainwp/mainwp.php';
        $this->careSite(82, 1176, 36, 'demo.example.test');
        $this->http->on('POST', 'sites/36/suspend', 'site-suspend');

        self::assertSame('success', \mainwp_SuspendAccount(['addonId' => 82, 'serviceid' => 1176, 'userid' => 540, 'pid' => 16, 'serverid' => 0, 'status' => 'Active']));
        Hooks::serviceEvent('AfterModuleSuspend', ['params' => ['addonId' => 82, 'serviceid' => 1176, 'userid' => 540, 'pid' => 16, 'serverid' => 0, 'status' => 'Active']]);

        self::assertSame(['POST sites/36/suspend'], $this->sent());
        self::assertSame([['suspend', 'add-on 82', 'done']], $this->logged());
    }

    /** @return iterable<string, array{bool}> */
    public static function ourCallSucceeded(): iterable
    {
        yield 'our call succeeded' => [true];
        yield 'our call failed' => [false];
    }

    /**
     * A Module Queue Retry of our own command may fire its hooks with no parameters (seen for Create,
     * 9 November). Nothing hides the hook's "failed" row: a false alarm after a Retry staff pressed is
     * accepted, a parent's retried suspend that left care sites running is not.
     *
     * @dataProvider ourCallSucceeded
     */
    public function testAnEmptyHookAfterOurCommandStillLogsFailed(bool $succeeds): void
    {
        require_once __DIR__ . '/../modules/servers/mainwp/mainwp.php';
        $this->careSite(82, 1176, 36, 'demo.example.test');
        $this->http->on('POST', 'sites/36/suspend', $succeeds ? 'site-suspend' : new \MainWpForWhmcs\Engine\HttpResponse(500, '{"message":"Internal Server Error"}'));

        $answer = \mainwp_SuspendAccount(['addonId' => 82, 'serviceid' => 1176, 'userid' => 540, 'pid' => 16, 'serverid' => 0, 'status' => 'Active']);
        Hooks::serviceEvent('AfterModuleSuspend', ['params' => []]);

        self::assertSame($succeeds, $answer === 'success');
        self::assertSame([['suspend', 'add-on 82', $succeeds ? 'done' : 'failed'], ['suspend', 'a hosting service', 'failed']], $this->logged());
    }

    public function testAQueueRetryOfTheServicesOwnCommandStillLogsFailed(): void
    {
        // No function of ours ran: the parent's (Plesk) retried command may have left care sites running.
        Hooks::serviceEvent('AfterModuleSuspend', []);

        self::assertSame([], $this->sent());
        self::assertSame('connected', $this->repo->careSites[67]['state']);
        self::assertSame([['suspend', 'a hosting service', 'failed']], $this->logged());
    }
}
