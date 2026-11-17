<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\DashboardFailure;
use MainWpForWhmcs\Engine\HttpResponse;
use MainWpForWhmcs\Engine\LogEntry;
use MainWpForWhmcs\Engine\LoggedDashboard;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\MainWpDashboard;
use MainWpForWhmcs\Tests\Support\FixtureHttp;

/** Every dashboard call leaves one log line with its target and outcome, and never the key or a password. */
final class LoggedDashboardTest extends TestCase
{
    private const KEY = 'bench-key-0123456789';

    private FixtureHttp $http;
    private MemoryStorage $storage;

    protected function setUp(): void
    {
        $this->http = new FixtureHttp();
        $this->storage = new MemoryStorage();
    }

    private function dashboard(): LoggedDashboard
    {
        return new LoggedDashboard(new MainWpDashboard('https://dash.example.com', self::KEY, $this->http, 'WHMCS client'), $this->storage, new FixedClock());
    }

    /** @return list<array{string, string, string, string}> */
    private function lines(): array
    {
        return array_map(static fn (LogEntry $e): array => [$e->action, $e->target, $e->outcome, $e->detail], $this->storage->log);
    }

    public function testEachCallIsLoggedWithItsTargetAndOutcome(): void
    {
        $this->http->on('GET', 'clients/count', 'clients-count')->on('GET', 'sites/count', 'sites-count')
            ->on('POST', 'clients/add', 'clients-add')
            ->on('POST', 'sites/add', 'sites-add-with-client')
            ->on('POST', 'sites/1/sync', 'site-sync')
            ->on('POST', 'sites/1/suspend', 'site-suspend')
            ->on('POST', 'sites/1/unsuspend', 'site-unsuspend')
            ->on('DELETE', 'sites/1/remove', 'site-remove');
        $dashboard = $this->dashboard();

        $dashboard->counts();
        $dashboard->createClient(540, 'Demo Client', 'demo-client@example.com');
        $dashboard->addSite(2, 'https://mainwp-bench-site.test/', new Login('owner', 'Hunter2!'));
        $dashboard->syncSite(1);
        $dashboard->suspendSite(1);
        $dashboard->resumeSite(1);
        $dashboard->removeSite(1);

        self::assertSame([
            ['dashboard: counts', 'dashboard', 'done', '1 clients, 1 sites'],
            ['dashboard: create client', 'billing client 540', 'done', 'dashboard client 1'],
            ['dashboard: add site', 'https://mainwp-bench-site.test/ under dashboard client 2', 'done', 'dashboard site 1, as owner'],
            ['dashboard: sync site', 'dashboard site 1', 'done', 'connected, last sync 2026-11-09T15:55:14'],
            ['dashboard: suspend site', 'dashboard site 1', 'done', ''],
            ['dashboard: resume site', 'dashboard site 1', 'done', ''],
            ['dashboard: remove site', 'dashboard site 1', 'done', 'The site has been removed and the MainWP Child plugin has been disabled'],
        ], $this->lines());
        self::assertSame('module', $this->storage->log[0]->actor);
        self::assertFalse($this->storage->log[0]->dryRun);
        self::assertStringNotContainsString('Hunter2!', serialize($this->storage->log));
        self::assertStringNotContainsString(self::KEY, serialize($this->storage->log));
    }

    public function testAFailedCallIsLoggedWithItsReasonAndStillThrows(): void
    {
        $this->http->on('GET', 'clients/count', 'key-wrong')->on('GET', 'sites/1', new \RuntimeException('Connection timed out for ' . self::KEY));

        foreach ([fn () => $this->dashboard()->counts(), fn () => $this->dashboard()->site(1)] as $call) {
            try {
                $call();
                self::fail('expected a failure');
            } catch (DashboardFailure) {
            }
        }

        self::assertSame([
            ['dashboard: counts', 'dashboard', 'failed', 'key refused: The dashboard refused the API key (HTTP 401: Invalid OAuth parameter). Make a key on the dashboard under API Access and enter it in the module\'s settings. If the key is right, the dashboard\'s web server may not be passing the Authorization header on to WordPress.'],
            ['dashboard: read site', 'dashboard site 1', 'failed', 'unreachable: The dashboard at dash.example.com could not be reached to read site 1: Connection timed out for (hidden).'],
        ], $this->lines());
    }

    public function testReadsAreLoggedToo(): void
    {
        $this->http->on('GET', 'clients?page=1&per_page=100', 'clients-list', 'clients-list')->on('GET', 'sites?page=1&per_page=20', 'sites-list')->on('GET', 'sites/1', 'site-get');
        $dashboard = $this->dashboard();

        $dashboard->clients();
        $dashboard->findClient(540);
        $dashboard->sites();
        $dashboard->site(1);

        self::assertSame([
            ['dashboard: list clients', 'dashboard', 'done', '1 clients'],
            ['dashboard: find client', 'billing client 540', 'done', 'dashboard client 1'],
            ['dashboard: list sites', 'dashboard', 'done', '1 sites'],
            ['dashboard: read site', 'dashboard site 1', 'done', 'connected, last sync 2026-11-09T15:55:13'],
        ], $this->lines());
    }

    /** A recorded answer of users/create with the password MainWP repeats in it put back (the bench removed it). */
    private static function echoing(string $fixture, string $password): HttpResponse
    {
        $answer = json_decode(FixtureHttp::fixture($fixture)->body, true, 512, JSON_THROW_ON_ERROR);
        $answer['data']['user_info']['user_pass'] = $password;
        return new HttpResponse(200, json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function testThePasswordIsCleanedFromAMakeAdministratorFailureWhateverDashboardWasWrapped(): void
    {
        $password = 'Ab3dEf7h-Jk9mNp2q-Rs4tUv6w-Xy8zAb1c';
        $inner = new RecordingDashboard();
        $inner->createRefuses = [new DashboardFailure(DashboardFailure::REFUSED, 'The site said: ' . $password . ' is not allowed.')];
        $inner->admins[1] = [];

        try {
            (new LoggedDashboard($inner, $this->storage, new FixedClock()))->createAdministrator(1, new Login('wpcareetec6urx', $password), 'wordpress@bench.test', 'WordPress', 'Care');
            self::fail('expected a refusal');
        } catch (DashboardFailure) {
        }

        self::assertSame([['dashboard: make administrator', 'dashboard site 1', 'failed', 'refused: The site said: (hidden) is not allowed.']], $this->lines());
    }

    /**
     * Code review, 16 November 2026: client sites' administrator logins do not belong in the module's
     * log, which is kept without limit by default. The list is logged by how many; the care account
     * the module makes is still named.
     */
    public function testTheAdministratorsAreLoggedByHowManyAndTheCareAccountByLoginNameAndNeverThePassword(): void
    {
        $password = 'Ab3dEf7h-Jk9mNp2q-Rs4tUv6w-Xy8zAb1c';
        $refused = json_decode(self::echoing('users-create-refused-login-exists', $password)->body, true, 512, JSON_THROW_ON_ERROR);
        $refused['data']['failed_sites'][1]['message'] = 'ERROR: Sorry, ' . $password . ' is not allowed.';
        $this->http->on('GET', 'users?websites=2&roles=administrator', 'users-list-admins-host-route')
            ->on('GET', 'users?websites=1&roles=administrator', 'users-list-disconnected')
            ->on('POST', 'users/create', self::echoing('users-create-care', $password), new HttpResponse(200, json_encode($refused, JSON_THROW_ON_ERROR)));
        $dashboard = $this->dashboard();

        $dashboard->administrators(2);
        try {
            $dashboard->administrators(1);
            self::fail('expected a refusal');
        } catch (DashboardFailure) {
        }
        $dashboard->createAdministrator(1, new Login('wpcareetec6urx', $password), 'wordpress@bench.test', 'WordPress', 'Care');
        try {
            $dashboard->createAdministrator(1, new Login('wpcareetec6urx', $password), 'wordpress@bench.test', 'WordPress', 'Care');
            self::fail('expected a refusal');
        } catch (DashboardFailure $e) {
            self::assertStringNotContainsString($password, $e->getMessage());
        }

        self::assertSame([
            ['dashboard: list administrators', 'dashboard site 2', 'done', '2 administrators'],
            ['dashboard: list administrators', 'dashboard site 1', 'failed', 'refused: The dashboard refused to list the administrators of site 1: No website found (MainWP answers so for a site whose last sync failed or that is suspended).'],
            ['dashboard: make administrator', 'dashboard site 1', 'done', 'wpcareetec6urx'],
            ['dashboard: make administrator', 'dashboard site 1', 'failed', 'refused: The dashboard refused to make administrator wpcareetec6urx on site 1: ERROR: Sorry, (hidden) is not allowed.'],
        ], $this->lines());
        self::assertStringNotContainsString($password, serialize($this->storage->log));
        self::assertStringNotContainsString(self::KEY, serialize($this->storage->log));
        self::assertStringNotContainsString('owner', serialize($this->storage->log), 'no client administrator\'s login');
    }

    public function testOneAdministratorIsCountedInTheSingular(): void
    {
        $this->http->on('GET', 'users?websites=1&roles=administrator', 'users-list-admins');
        self::assertSame(['owner'], $this->dashboard()->administrators(1));
        self::assertSame([['dashboard: list administrators', 'dashboard site 1', 'done', '1 administrator']], $this->lines());
    }
}
