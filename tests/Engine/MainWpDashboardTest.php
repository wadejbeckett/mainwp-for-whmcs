<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\DashboardFailure;
use MainWpForWhmcs\Engine\HttpResponse;
use MainWpForWhmcs\Engine\DashboardSite;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\MainWpDashboard;
use MainWpForWhmcs\Tests\Support\FixtureHttp;

/** The dashboard client against answers recorded on the bench (MainWP Dashboard 6.4.1, 9 November 2026). */
final class MainWpDashboardTest extends TestCase
{
    private const KEY = 'bench-key-0123456789';

    private FixtureHttp $http;

    protected function setUp(): void
    {
        $this->http = new FixtureHttp();
    }

    private function dashboard(string $url = 'https://dash.example.com/'): MainWpDashboard
    {
        return new MainWpDashboard($url, self::KEY, $this->http, 'WHMCS client');
    }

    public function testAKeyIsTwoRunsOfLettersAndDigitsJoinedByTwoEqualsSigns(): void
    {
        // MainWP Dashboard 6.4 makes a key as secret . '==' . key, 40 hexadecimal digits each, and its
        // server splits the bearer value at "==": a value with no "==" gets "Invalid OAuth parameter", and one
        // with an empty half fails as an unknown key ("The REST API Key are disabled.").
        $half = str_repeat('0f', 20);
        self::assertTrue(MainWpDashboard::isKey($half . '==' . $half));
        self::assertTrue(MainWpDashboard::isKey('Ab12==Cd34'), 'the length and the case are MainWP\'s to change');
        foreach (['', $half, $half . '==', '==' . $half, $half . '=' . $half, $half . '===' . $half, $half . '==' . $half . '==' . $half, ' ' . $half . '==' . $half, $half . '==' . $half . "\n", $half . '==' . $half . ' ', 'ck_' . $half . '==' . $half, 'https://dash.example.com/wp-admin/admin.php?page=RESTAPI'] as $notAKey) {
            self::assertFalse(MainWpDashboard::isKey($notAKey), var_export($notAKey, true));
        }
    }

    public function testCountsAreReadWithTheBearerKeyOverHttps(): void
    {
        $this->http->on('GET', 'clients/count', 'clients-count')->on('GET', 'sites/count', 'sites-count');

        self::assertSame(['clients' => 1, 'sites' => 1], $this->dashboard()->counts());
        self::assertSame('https://dash.example.com/wp-json/mainwp/v2/clients/count', $this->http->sent[0]['url']);
        self::assertSame('Bearer ' . self::KEY, $this->http->sent[0]['headers']['Authorization']);
    }

    public function testClientsAreListedWithTheBillingClientIdReadFromTheirNote(): void
    {
        $this->http->on('GET', 'clients?page=1&per_page=100', 'clients-list');

        $clients = $this->dashboard()->clients();

        self::assertCount(1, $clients);
        self::assertSame([1, 'Demo Client', 'demo-client@example.com', 540], [$clients[0]->id, $clients[0]->name, $clients[0]->email, $clients[0]->billingClientId]);
    }

    public function testAListIsReadToItsEndThoughEveryPageCallsItsOwnRowsTheTotal(): void
    {
        // MainWP's clients route answers "total" as the number of rows on that page (bench, 16 November 2026: with 5
        // clients, page 2 of 1 a page says total 1), and so does its sites route on a WordPress without the Abilities API.
        $row = json_decode(FixtureHttp::fixture('clients-list')->body, true, 512, JSON_THROW_ON_ERROR)['data'][0];
        foreach ([1 => 20, 2 => 20, 3 => 5, 4 => 0] as $page => $count) {
            $rows = [];
            for ($i = 1; $i <= $count; $i++) {
                $id = ($page - 1) * 20 + $i;
                $rows[] = ['id' => (string) $id, 'note' => 'WHMCS client ' . (1000 + $id)] + $row;
            }
            $this->http->on('GET', 'clients?page=' . $page . '&per_page=100', new HttpResponse(200, json_encode(['success' => 1, 'total' => $count, 'data' => $rows], JSON_THROW_ON_ERROR)));
        }

        $clients = $this->dashboard()->clients();

        self::assertCount(45, $clients, 'every client, not only the first page');
        self::assertSame([45, 1045], [$clients[44]->id, $clients[44]->billingClientId]);
        self::assertSame(['clients?page=1&per_page=100', 'clients?page=2&per_page=100', 'clients?page=3&per_page=100', 'clients?page=4&per_page=100'], $this->asked());
    }

    public function testClientsAreReadAHundredAtATimeAndAFullPageIsFollowedByTheNext(): void
    {
        // The pages MainWP gives at 100 a page (bench, 16 November 2026, 601 clients: 100 rows six times, then 1, then none).
        $row = json_decode(FixtureHttp::fixture('clients-list')->body, true, 512, JSON_THROW_ON_ERROR)['data'][0];
        foreach ([1 => 100, 2 => 100, 3 => 1, 4 => 0] as $page => $count) {
            $rows = [];
            for ($i = 1; $i <= $count; $i++) {
                $id = ($page - 1) * 100 + $i;
                $rows[] = ['id' => (string) $id, 'note' => 'WHMCS client ' . (1000 + $id)] + $row;
            }
            $this->http->on('GET', 'clients?page=' . $page . '&per_page=100', new HttpResponse(200, json_encode(['success' => 1, 'total' => $count, 'data' => $rows], JSON_THROW_ON_ERROR)));
        }

        $clients = $this->dashboard()->clients();

        self::assertCount(201, $clients, 'a full page whose total is its own 100 rows is not the end');
        self::assertSame([201, 1201], [$clients[200]->id, $clients[200]->billingClientId]);
        self::assertSame(['clients?page=1&per_page=100', 'clients?page=2&per_page=100', 'clients?page=3&per_page=100', 'clients?page=4&per_page=100'], $this->asked());
    }

    public function testAClientPastTheFirstPageIsFoundSoThatItIsNotCreatedTwice(): void
    {
        $row = json_decode(FixtureHttp::fixture('clients-list')->body, true, 512, JSON_THROW_ON_ERROR)['data'][0];
        foreach ([1 => 20, 2 => 1, 3 => 0] as $page => $count) {
            $rows = [];
            for ($i = 1; $i <= $count; $i++) {
                $id = ($page - 1) * 20 + $i;
                $rows[] = ['id' => (string) $id, 'note' => 'WHMCS client ' . (1000 + $id)] + $row;
            }
            $this->http->on('GET', 'clients?page=' . $page . '&per_page=100', new HttpResponse(200, json_encode(['success' => 1, 'total' => $count, 'data' => $rows], JSON_THROW_ON_ERROR)));
        }

        self::assertSame(21, $this->dashboard()->findClient(1021));
    }

    public function testAListThatFillsItsOnePageExactlyIsAskedOnceMoreAndEndsThere(): void
    {
        $row = json_decode(FixtureHttp::fixture('sites-list')->body, true, 512, JSON_THROW_ON_ERROR)['data'][0];
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = ['id' => $i] + $row;
        }
        $this->http->on('GET', 'sites?page=1&per_page=20', new HttpResponse(200, json_encode(['success' => 1, 'total' => 20, 'data' => $rows], JSON_THROW_ON_ERROR)))
            ->on('GET', 'sites?page=2&per_page=20', 'sites-list-empty');

        self::assertCount(20, $this->dashboard()->sites());
        self::assertSame(['sites?page=1&per_page=20', 'sites?page=2&per_page=20'], $this->asked());
    }

    public function testATotalOfTheWholeListEndsTheReadingWithoutAnotherCall(): void
    {
        // MainWP's sites route gives the whole list's total where WordPress has the Abilities API: a total larger than the page is one.
        $row = json_decode(FixtureHttp::fixture('sites-list')->body, true, 512, JSON_THROW_ON_ERROR)['data'][0];
        foreach ([1 => 20, 2 => 5] as $page => $count) {
            $rows = [];
            for ($i = 1; $i <= $count; $i++) {
                $rows[] = ['id' => ($page - 1) * 20 + $i] + $row;
            }
            $this->http->on('GET', 'sites?page=' . $page . '&per_page=20', new HttpResponse(200, json_encode(['success' => 1, 'total' => 25, 'data' => $rows], JSON_THROW_ON_ERROR)));
        }

        self::assertCount(25, $this->dashboard()->sites());
        self::assertSame(['sites?page=1&per_page=20', 'sites?page=2&per_page=20'], $this->asked());
    }

    public function testARouteThatIgnoresThePageAndAnswersEverythingEachTimeIsReadOnce(): void
    {
        $row = json_decode(FixtureHttp::fixture('sites-list')->body, true, 512, JSON_THROW_ON_ERROR)['data'][0];
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['id' => $i] + $row;
        }
        $all = new HttpResponse(200, json_encode(['success' => 1, 'total' => 30, 'data' => $rows], JSON_THROW_ON_ERROR));
        $this->http->on('GET', 'sites?page=1&per_page=20', $all)->on('GET', 'sites?page=2&per_page=20', $all);

        self::assertCount(30, $this->dashboard()->sites(), 'no row twice');
    }

    public function testAListWhosePagesComeShorterThanAskedForIsStillReadToItsEnd(): void
    {
        // A dashboard that honours the page but gives fewer rows a page than asked for: a short page is not the last one.
        $row = json_decode(FixtureHttp::fixture('clients-list')->body, true, 512, JSON_THROW_ON_ERROR)['data'][0];
        foreach ([1 => 10, 2 => 10, 3 => 10, 4 => 10, 5 => 5, 6 => 0] as $page => $count) {
            $rows = [];
            for ($i = 1; $i <= $count; $i++) {
                $rows[] = ['id' => (string) (($page - 1) * 10 + $i)] + $row;
            }
            $this->http->on('GET', 'clients?page=' . $page . '&per_page=100', new HttpResponse(200, json_encode(['success' => 1, 'total' => $count, 'data' => $rows], JSON_THROW_ON_ERROR)));
        }

        self::assertCount(45, $this->dashboard()->clients());
    }

    public function testALaterPageThatFailsGivesNoListAtAll(): void
    {
        // A part of the list must never pass for the whole: sites that are not in it would be looked for one by one.
        $row = json_decode(FixtureHttp::fixture('sites-list')->body, true, 512, JSON_THROW_ON_ERROR)['data'][0];
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $rows[] = ['id' => $i] + $row;
        }
        $this->http->on('GET', 'sites?page=1&per_page=20', new HttpResponse(200, json_encode(['success' => 1, 'total' => 45, 'data' => $rows], JSON_THROW_ON_ERROR)))
            ->on('GET', 'sites?page=2&per_page=20', new HttpResponse(500, '{"code":"internal_server_error","message":"There has been a critical error on this website."}'));

        $failure = $this->failure(fn () => $this->dashboard()->sites());

        self::assertSame(DashboardFailure::BAD_ANSWER, $failure->kind);
    }

    public function testAListThatNeverEndsIsAFailureNotAPartOfTheList(): void
    {
        $row = json_decode(FixtureHttp::fixture('clients-list')->body, true, 512, JSON_THROW_ON_ERROR)['data'][0];
        for ($page = 1; $page <= 1001; $page++) {
            $this->http->on('GET', 'clients?page=' . $page . '&per_page=100', new HttpResponse(200, json_encode(['success' => 1, 'total' => 1, 'data' => [['id' => (string) $page] + $row]], JSON_THROW_ON_ERROR)));
        }

        $failure = $this->failure(fn () => $this->dashboard()->clients());

        self::assertSame(DashboardFailure::BAD_ANSWER, $failure->kind);
        self::assertStringContainsString('did not come to an end', $failure->getMessage());
        self::assertCount(1000, $this->http->sent);
    }

    /** @return list<string> the routes asked for, in order */
    private function asked(): array
    {
        return array_map(static fn (array $sent): string => (string) preg_replace('~^.*/wp-json/mainwp/v2/~', '', $sent['url']), $this->http->sent);
    }

    public function testFindClientMatchesTheNoteAndReturnsNullOnAnEmptyDashboard(): void
    {
        $this->http->on('GET', 'clients?page=1&per_page=100', 'clients-list', 'clients-list', 'clients-list-empty');

        self::assertSame(1, $this->dashboard()->findClient(540));
        self::assertNull($this->dashboard()->findClient(541));
        self::assertNull($this->dashboard()->findClient(540));
    }

    public function testAClientIsCreatedWithTheBillingClientIdInItsNote(): void
    {
        $this->http->on('POST', 'clients/add', 'clients-add');

        self::assertSame(1, $this->dashboard()->createClient(540, 'Demo Client', 'demo-client@example.com'));
        self::assertSame(['name' => 'Demo Client', 'client_email' => 'demo-client@example.com', 'note' => 'WHMCS client 540'], $this->http->sent[0]['form']);
    }

    public function testCreatingAClientWhoseEmailIsTakenFailsWithMainWpsReason(): void
    {
        $this->http->on('POST', 'clients/add', 'clients-add-duplicate-email');

        $failure = $this->failure(fn () => $this->dashboard()->createClient(540, 'Demo Client', 'demo-client@example.com'));

        self::assertSame(DashboardFailure::REFUSED, $failure->kind);
        self::assertSame('The dashboard refused to create the client: Client email exists. Please try again.', $failure->getMessage());
    }

    public function testCreatingAClientWithoutANameFailsWithMainWpsReason(): void
    {
        $this->http->on('POST', 'clients/add', 'clients-add-no-name');

        $failure = $this->failure(fn () => $this->dashboard()->createClient(540, '', 'nobody@example.com'));

        self::assertSame(DashboardFailure::REFUSED, $failure->kind);
        self::assertSame('The dashboard refused to create the client: Client name field is required! Please enter a Client name.', $failure->getMessage());
    }

    public function testSitesAreListedWithClientStateAndLastSync(): void
    {
        $this->http->on('GET', 'sites?page=1&per_page=20', 'sites-list');

        self::assertEquals([new DashboardSite(1, 'https://mainwp-bench-site.test/', 1, 'connected', '2026-11-09T15:55:13', '')], $this->dashboard()->sites());
    }

    public function testAFullSiteListOfLargeRowsIsReadInPagesThatEachStayUnderTheCap(): void
    {
        // A site with dozens of plugins and themes makes a row of about 30 KB in MainWP's list.
        $row = json_decode(FixtureHttp::fixture('sites-list')->body, true, 512, JSON_THROW_ON_ERROR)['data'][0];
        $row['note'] = str_repeat('x', 30 * 1024);
        for ($page = 1; $page <= 5; $page++) {
            $rows = [];
            for ($i = 1; $i <= 20; $i++) {
                $rows[] = ['id' => ($page - 1) * 20 + $i] + $row;
            }
            $body = json_encode(['success' => 1, 'total' => 100, 'data' => $rows], JSON_THROW_ON_ERROR);
            self::assertLessThan(\MainWpForWhmcs\Engine\CurlHttp::MAX_BODY, strlen($body), 'page ' . $page);
            $this->http->on('GET', 'sites?page=' . $page . '&per_page=20', new HttpResponse(200, $body));
        }

        self::assertCount(100, $this->dashboard()->sites());
    }

    public function testAnEmptySiteListIsEmpty(): void
    {
        $this->http->on('GET', 'sites?page=1&per_page=20', 'sites-list-empty');

        self::assertSame([], $this->dashboard()->sites());
    }

    public function testASiteIsReadWithItsConnectionStateAndLastSync(): void
    {
        $this->http->on('GET', 'sites/1', 'site-get', 'site-get-suspended');

        $site = $this->dashboard()->site(1);
        self::assertTrue($site->isConnected());
        self::assertSame('2026-11-09T15:55:13', $site->lastSync);
        self::assertSame(1, $site->clientId);
        self::assertTrue($this->dashboard()->site(1)->isSuspended());
    }

    public function testTheShorterRowMainWpGivesForAStagingSiteIsStillRead(): void
    {
        // A site flagged as staging answers with other fields and numeric ids, and is missing from the list (bench, sixth run).
        $this->http->on('GET', 'sites/5', 'site-get-staging')->on('GET', 'sites?page=1&per_page=20', 'sites-list-staging');

        $site = $this->dashboard()->site(5);
        self::assertSame([5, 'https://mainwp-bench-site.test/', 1, true, ''], [$site->id, $site->url, $site->clientId, $site->isConnected(), $site->syncErrors]);
        self::assertSame([], $this->dashboard()->sites());
    }

    public function testRemovingASiteGivesBackWhatMainWpSaidAboutItsPlugin(): void
    {
        $this->http->on('DELETE', 'sites/1/remove', 'site-remove')->on('DELETE', 'sites/5/remove', 'site-remove-staging');

        self::assertSame('The site has been removed and the MainWP Child plugin has been disabled', $this->dashboard()->removeSite(1));
        self::assertSame('The site has been removed. Please make sure that the MainWP Child plugin has been deactivated properly', $this->dashboard()->removeSite(5));
    }

    public function testARemovalMainWpAnswersWithAnErrorIsARefusalWithItsReason(): void
    {
        $this->http->on('DELETE', 'sites/4/remove', 'site-remove-dead');

        $failure = $this->failure(fn () => $this->dashboard()->removeSite(4));

        self::assertSame(DashboardFailure::REFUSED, $failure->kind);
        self::assertSame('The dashboard refused to remove site 4: Unexisting administrator user. Please verify that it is an existing administrator.', $failure->getMessage());
    }

    public function testAnAddTheSitesOwnPluginRefusesWithWordsMainWpHidesIsItsOwnKind(): void
    {
        // The site's MainWP Child still holds the keys of an earlier connection (bench, sixth run): only clearing them on the site helps.
        $this->http->on('POST', 'sites/add', 'sites-add-after-dead-remove');

        $failure = $this->failure(fn () => $this->dashboard()->addSite(1, 'https://mainwp-bench-site.test/', new Login('owner', 'x')));

        self::assertSame(DashboardFailure::EARLIER_CONNECTION, $failure->kind, 'not a refused login: nothing the person who typed it can put right by typing');
        self::assertSame('The dashboard refused to connect https://mainwp-bench-site.test/: MainWP Child on the site refused it, and MainWP hid the reason. When the site was connected to a dashboard before, MainWP Child still holds that connection: clear it on the site (Settings, MainWP Child, Disconnect) and connect again.', $failure->getMessage());
        self::assertStringNotContainsString('172.', $failure->getMessage(), 'none of MainWP\'s diagnostics');
    }

    public function testAnUnknownSiteIsNotFound(): void
    {
        $this->http->on('GET', 'sites/1', 'site-get-unknown');

        $failure = $this->failure(fn () => $this->dashboard()->site(1));

        self::assertSame(DashboardFailure::NOT_FOUND, $failure->kind);
        self::assertSame('The dashboard could not read site 1: it has no such item (HTTP 404: Invalid or not found ID).', $failure->getMessage());
    }

    public function testASiteIsAddedUnderTheClientWithThePasswordSlashedAndEncodedOnce(): void
    {
        $this->http->on('POST', 'sites/add', 'sites-add-with-client');

        $id = $this->dashboard()->addSite(2, 'https://mainwp-bench-site.test/', new Login('owner', "a'b\"c\\d%e"));

        self::assertSame(1, $id);
        self::assertSame([
            'url' => 'https://mainwp-bench-site.test/',
            'name' => 'mainwp-bench-site.test',
            'admin' => 'owner',
            'adminpassword' => 'a%5C%27b%5C%22c%5C%5Cd%25e',
            'client_id' => '2',
        ], $this->http->sent[0]['form']);
    }

    public function testAWrongPasswordAtAddSiteFailsWithTheFirstLineOfMainWpsReasonOnly(): void
    {
        $this->http->on('POST', 'sites/add', 'sites-add-wrong-password');

        $failure = $this->failure(fn () => $this->dashboard()->addSite(2, 'https://mainwp-bench-site.test/', new Login('owner', 'wrong')));

        self::assertSame(DashboardFailure::REFUSED, $failure->kind);
        self::assertSame('The dashboard refused to connect https://mainwp-bench-site.test/: Unable to connect to the site. Please verify that your Admin Username and Password are correct and try again.', $failure->getMessage());
        self::assertStringNotContainsString('172.18', $failure->getMessage());
    }

    public function testAddingASiteTheDashboardAlreadyHoldsFails(): void
    {
        $this->http->on('POST', 'sites/add', 'sites-add-already-there');

        $failure = $this->failure(fn () => $this->dashboard()->addSite(2, 'https://mainwp-bench-site.test/', new Login('owner', 'x')));

        self::assertSame(DashboardFailure::ALREADY_HELD, $failure->kind, 'not a refusal of the login: nothing the person who typed it can put right');
        self::assertSame('The dashboard refused to connect https://mainwp-bench-site.test/: it already holds a site at that address (dashboard site 1). When that site is connected and filed under this client, the next retry takes it over; otherwise remove it on the dashboard.', $failure->getMessage());
    }

    public function testTheAlreadyHeldRefusalIsKnownByItsWordsWhenMainWpGivesNoSiteId(): void
    {
        // MainWP leaves found_id out when the site it holds is outside what the key may see.
        $this->http->on('POST', 'sites/add', new HttpResponse(200, '{"success":0,"error":"The site is already connected to your MainWP Dashboard"}'));

        $failure = $this->failure(fn () => $this->dashboard()->addSite(2, 'https://mainwp-bench-site.test/', new Login('owner', 'x')));

        self::assertSame(DashboardFailure::ALREADY_HELD, $failure->kind);
        self::assertStringContainsString('it already holds a site at that address. When', $failure->getMessage());
    }

    public function testAWrongLoginIsStillARefusalWithMainWpsReason(): void
    {
        $this->http->on('POST', 'sites/add', 'sites-add-wrong-password');

        self::assertSame(DashboardFailure::REFUSED, $this->failure(fn () => $this->dashboard()->addSite(2, 'https://mainwp-bench-site.test/', new Login('owner', 'x')))->kind);
    }

    public function testASyncReturnsTheSiteStateAfterIt(): void
    {
        $this->http->on('POST', 'sites/1/sync', 'site-sync', 'site-sync-suspended');

        self::assertEquals(new DashboardSite(1, 'https://mainwp-bench-site.test/', 1, 'connected', '2026-11-09T15:55:14', ''), $this->dashboard()->syncSite(1));
        self::assertTrue($this->dashboard()->syncSite(1)->isSuspended());
    }

    public function testADisconnectedSiteIsReadWithMainWpsReasonInOneLineAndNoneOfItsDiagnostics(): void
    {
        // MainWP Child was switched off on the site (bench, sixth run): sync_errors holds HTML, IP addresses and the site's raw answer.
        $this->http->on('GET', 'sites/1', 'site-get-disconnected', 'site-get-admin-gone')->on('GET', 'sites?page=1&per_page=20', 'sites-list-disconnected');

        $site = $this->dashboard()->site(1);
        self::assertSame(['disconnected', true, false], [$site->status, $site->isDisconnected(), $site->isConnected()]);
        self::assertStringStartsWith('MainWP Child plugin not detected or could not be reached!', $site->syncErrors);
        self::assertStringEndsWith('check the MainWP Community for help', $site->syncErrors);
        self::assertSame(0, preg_match('/[<>\n]|\d+\.\d+\.\d+\.\d+|User-Agent/', $site->syncErrors), 'one plain line: no markup, no IP address, none of the dashboard\'s diagnostics');
        self::assertSame('ERROR: Unexisting administrator user. Please verify that it is an existing administrator', $this->dashboard()->site(1)->syncErrors);
        self::assertSame($site->syncErrors, $this->dashboard()->sites()[0]->syncErrors);
    }

    public function testASuspendedSiteWhoseLastSyncFailedIsSuspendedAndKeepsTheReason(): void
    {
        $this->http->on('GET', 'sites/5', 'site-get-suspended-disconnected');

        $site = $this->dashboard()->site(5);
        self::assertSame([true, false], [$site->isSuspended(), $site->isDisconnected()], 'MainWP reports suspended over disconnected');
        self::assertStringStartsWith('MainWP Child plugin not detected', $site->syncErrors);
    }

    public function testASyncThatFailsIsARefusalWithoutAReason(): void
    {
        $this->http->on('POST', 'sites/1/sync', 'site-sync-child-off');

        $failure = $this->failure(fn () => $this->dashboard()->syncSite(1));

        self::assertSame(DashboardFailure::REFUSED, $failure->kind);
        self::assertSame('The dashboard refused to sync site 1: it gave no reason.', $failure->getMessage());
    }

    public function testReconnectingASiteCallsMainWpsOwnRouteAndSendsNoLogin(): void
    {
        $this->http->on('POST', 'sites/1/reconnect', 'site-reconnect-rekeyed');

        $this->dashboard()->reconnectSite(1);

        self::assertSame(['POST', 'https://dash.example.com/wp-json/mainwp/v2/sites/1/reconnect', []], [$this->http->sent[0]['method'], $this->http->sent[0]['url'], $this->http->sent[0]['form']]);
    }

    public function testAReconnectMainWpCannotDoIsItsRefusalInOneLine(): void
    {
        // MainWP answers these with HTTP 500 and its own error code (bench, sixth run).
        $this->http->on('POST', 'sites/2/reconnect', 'site-reconnect-admin-gone')->on('POST', 'sites/1/reconnect', 'site-reconnect-child-off');

        $gone = $this->failure(fn () => $this->dashboard()->reconnectSite(2));
        self::assertSame(DashboardFailure::REFUSED, $gone->kind);
        self::assertSame('The dashboard refused to reconnect site 2: Reconnect Site "2" error:: Failed to reconnect to the site. Please remove the site and add it again.', $gone->getMessage());
        $off = $this->failure(fn () => $this->dashboard()->reconnectSite(1));
        self::assertSame(DashboardFailure::REFUSED, $off->kind);
        self::assertSame(0, preg_match('/[<>\n]|\d+\.\d+\.\d+\.\d+/', $gone->getMessage() . $off->getMessage()));
    }

    public function testPointingASiteAtAnotherAdministratorSendsOnlyTheLoginName(): void
    {
        $this->http->on('POST', 'sites/2/edit', 'site-edit-admin');

        $this->dashboard()->setSiteAdmin(2, 'owner');

        self::assertSame(['POST', 'https://dash.example.com/wp-json/mainwp/v2/sites/2/edit', ['admin' => 'owner']], [$this->http->sent[0]['method'], $this->http->sent[0]['url'], $this->http->sent[0]['form']]);
    }

    public function testTheDashboardAsTheCareAccountIsReadAsMainWpRecordedItGoneAndMadeAgain(): void
    {
        // Bench, eighth run: pointed at the care account and synced (A4); the account deleted on the site (A6); made again and synced.
        $this->http->on('POST', 'sites/1/edit', 'site-edit-admin-care')
            ->on('POST', 'sites/1/sync', 'site-sync-as-care', 'site-sync-care-gone', 'site-sync-care-remade')
            ->on('GET', 'sites/1', 'site-get-care-gone');

        $this->dashboard()->setSiteAdmin(1, 'wpcareetec6urx');
        self::assertTrue($this->dashboard()->syncSite(1)->isConnected());
        self::assertSame(DashboardFailure::REFUSED, $this->failure(fn () => $this->dashboard()->syncSite(1))->kind, 'the sync that finds the care account gone is a refusal without a reason');
        self::assertSame('ERROR: Unexisting administrator user. Please verify that it is an existing administrator', $this->dashboard()->site(1)->syncErrors);
        self::assertTrue($this->dashboard()->syncSite(1)->isConnected(), 'connected again as the account made again under the same login');
    }

    // A site's administrators and the care account (bench, eighth run)

    /** A recorded answer of users/create as MainWP gives it: the bench removed the password it repeats, this puts it back. */
    private static function echoing(string $fixture, string $password): HttpResponse
    {
        $answer = json_decode(FixtureHttp::fixture($fixture)->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('[removed: ', $answer['data']['user_info']['user_pass']);
        $answer['data']['user_info']['user_pass'] = $password;
        return new HttpResponse(200, json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private const CARE = 'wpcareetec6urx';
    private const CARE_PASSWORD = 'Ab3dEf7h-Jk9mNp2q-Rs4tUv6w-Xy8zAb1c';

    public function testTheAdministratorsOfOneNamedSiteAreTheirLoginsAsMainWpListsThem(): void
    {
        $this->http->on('GET', 'users?websites=1&roles=administrator', 'users-list-admins')->on('GET', 'users?websites=2&roles=administrator', 'users-list-admins-host-route');

        self::assertSame(['owner'], $this->dashboard()->administrators(1));
        self::assertSame(['owner', 'wpcareq6h7l7w3'], $this->dashboard()->administrators(2), 'every administrator, in MainWP\'s order, care accounts too');
        self::assertSame('https://dash.example.com/wp-json/mainwp/v2/users?websites=1&roles=administrator', $this->http->sent[0]['url'], 'always for one named site: without one MainWP answers for none or every site');
        self::assertSame('GET', $this->http->sent[0]['method']);
    }

    public function testTheLoginIsTheUsernameNotTheNicename(): void
    {
        // Not recorded on the bench, where every login is also its nicename: a login with capitals and a dot.
        $this->http->on('GET', 'users?websites=1&roles=administrator', new HttpResponse(200, '{"success":1,"data":{"https://mainwp-bench-site.test/":[{"id":2,"website_id":1,"username":"Jane.Doe","name":"jane-doe","email":"jane@site.bench.test","role":"administrator","posts":"0"}]}}'));

        self::assertSame(['Jane.Doe'], $this->dashboard()->administrators(1));
    }

    public function testTheAdministratorsOfASiteTheDashboardCannotReachAreARefusal(): void
    {
        // MainWP drops a site whose last sync failed, or that is suspended, before it asks the site (HTTP 500 no_website_found).
        $this->http->on('GET', 'users?websites=1&roles=administrator', 'users-list-disconnected');

        $failure = $this->failure(fn () => $this->dashboard()->administrators(1));

        self::assertSame(DashboardFailure::REFUSED, $failure->kind, 'the site, not a fault of the dashboard');
        self::assertSame('The dashboard refused to list the administrators of site 1: No website found (MainWP answers so for a site whose last sync failed or that is suspended).', $failure->getMessage());
    }

    public function testAnAdministratorIsMadeThroughMainWpWithAJsonBodyAndNoPasswordMail(): void
    {
        $this->http->on('POST', 'users/create', self::echoing('users-create-care', self::CARE_PASSWORD));

        $this->dashboard()->createAdministrator(1, new Login(self::CARE, self::CARE_PASSWORD), 'wordpress@bench.test', 'WordPress', 'Care');

        $sent = $this->http->sent[0];
        self::assertSame(['POST', 'https://dash.example.com/wp-json/mainwp/v2/users/create'], [$sent['method'], $sent['url']]);
        self::assertSame('application/json', $sent['headers']['Content-Type'], 'MainWP refuses a form-encoded body');
        self::assertSame('Bearer ' . self::KEY, $sent['headers']['Authorization']);
        self::assertIsString($sent['form']);
        self::assertSame([
            'websites' => '1',
            'username' => self::CARE,
            'email' => 'wordpress@bench.test',
            'role' => 'administrator',
            'password' => self::CARE_PASSWORD,
            'first_name' => 'WordPress',
            'last_name' => 'Care',
        ], json_decode($sent['form'], true), 'the role named (a missing one makes a subscriber); no send_password, which mails the password in plain text; no display_name, which MainWP drops');
    }

    public function testTheSameLoginMadeAgainAfterItWasDeletedIsMadeLikeAnyOther(): void
    {
        $this->http->on('POST', 'users/create', self::echoing('users-create-care-again', self::CARE_PASSWORD));

        $this->dashboard()->createAdministrator(1, new Login(self::CARE, self::CARE_PASSWORD), 'wordpress@bench.test', 'WordPress', 'Care');

        self::assertCount(1, $this->http->sent);
    }

    public function testASiteThatRefusesTheAccountIsARefusalInItsOwnWordsThoughMainWpSaysSuccess(): void
    {
        $this->http->on('POST', 'users/create', self::echoing('users-create-refused-login-exists', self::CARE_PASSWORD), self::echoing('users-create-refused-email-exists', self::CARE_PASSWORD));

        $login = $this->failure(fn () => $this->dashboard()->createAdministrator(1, new Login(self::CARE, self::CARE_PASSWORD), 'wordpress+dup@bench.test', 'WordPress', 'Care'));
        $email = $this->failure(fn () => $this->dashboard()->createAdministrator(1, new Login('wpcare8er1ciyz', self::CARE_PASSWORD), 'owner@site.bench.test', 'WordPress', 'Care'));

        self::assertSame(DashboardFailure::REFUSED, $login->kind);
        self::assertSame('The dashboard refused to make administrator wpcareetec6urx on site 1: ERROR: Sorry, that username already exists!', $login->getMessage());
        self::assertSame(DashboardFailure::REFUSED, $email->kind);
        self::assertSame('The dashboard refused to make administrator wpcare8er1ciyz on site 1: ERROR: Sorry, that email address is already used!', $email->getMessage());
    }

    public function testMakingAnAdministratorOnASiteTheDashboardCannotReachIsARefusal(): void
    {
        $this->http->on('POST', 'users/create', 'users-create-refused-disconnected');

        $failure = $this->failure(fn () => $this->dashboard()->createAdministrator(1, new Login(self::CARE, self::CARE_PASSWORD), 'wordpress@bench.test', 'WordPress', 'Care'));

        self::assertSame(DashboardFailure::REFUSED, $failure->kind);
        self::assertSame('The dashboard refused to make administrator wpcareetec6urx on site 1: No website found (MainWP answers so for a site whose last sync failed or that is suspended).', $failure->getMessage());
    }

    public function testOtherRefusalsOfUsersCreateAreBadAnswersWithMainWpsWords(): void
    {
        $this->http->on('POST', 'users/create', 'users-create-refused-unknown-site', 'users-create-refused-form');

        $unknown = $this->failure(fn () => $this->dashboard()->createAdministrator(99999, new Login(self::CARE, self::CARE_PASSWORD), 'wordpress@bench.test', 'WordPress', 'Care'));
        $form = $this->failure(fn () => $this->dashboard()->createAdministrator(1, new Login(self::CARE, self::CARE_PASSWORD), 'wordpress@bench.test', 'WordPress', 'Care'));

        self::assertSame(DashboardFailure::BAD_ANSWER, $unknown->kind);
        self::assertSame('The dashboard answered "make administrator wpcareetec6urx on site 99999" with HTTP 400 and this: Invalid parameter(s): websites.', $unknown->getMessage());
        self::assertSame(DashboardFailure::BAD_ANSWER, $form->kind);
        self::assertSame('The dashboard answered "make administrator wpcareetec6urx on site 1" with HTTP 500 and this: Invalid content type. Expected application/json.', $form->getMessage());
    }

    public function testAnAnswerThatDoesNotSayTheAccountWasMadeIsABadAnswer(): void
    {
        $answer = json_decode(self::echoing('users-create-care', self::CARE_PASSWORD)->body, true, 512, JSON_THROW_ON_ERROR);
        $answer['data']['created_sites'] = [];
        $this->http->on('POST', 'users/create', new HttpResponse(200, json_encode($answer, JSON_THROW_ON_ERROR)));

        $failure = $this->failure(fn () => $this->dashboard()->createAdministrator(1, new Login(self::CARE, self::CARE_PASSWORD), 'wordpress@bench.test', 'WordPress', 'Care'));

        self::assertSame(DashboardFailure::BAD_ANSWER, $failure->kind);
        self::assertSame('The dashboard\'s answer to "make administrator wpcareetec6urx on site 1" did not say the administrator was made.', $failure->getMessage());
    }

    public function testThePasswordMainWpRepeatsNeverReachesAMessage(): void
    {
        // The site's own words could hold it too: a password policy plugin may quote what it refused.
        $answer = json_decode(self::echoing('users-create-refused-login-exists', self::CARE_PASSWORD)->body, true, 512, JSON_THROW_ON_ERROR);
        $answer['data']['failed_sites'][1]['message'] = 'ERROR: the password ' . self::CARE_PASSWORD . ' is too weak.';
        $answer['message'] = 'User creation failed for ' . self::CARE_PASSWORD;
        $bad = $answer;
        $bad['data']['total_failed'] = 'x';
        $bad['data']['failed_sites'] = [];
        $this->http->on('POST', 'users/create', new HttpResponse(200, json_encode($answer, JSON_THROW_ON_ERROR)), new HttpResponse(200, json_encode($bad, JSON_THROW_ON_ERROR)), new HttpResponse(500, json_encode(['code' => 'x', 'message' => 'failed for ' . self::CARE_PASSWORD], JSON_THROW_ON_ERROR)), new \RuntimeException('timed out sending ' . self::CARE_PASSWORD));

        $messages = [];
        for ($i = 0; $i < 4; $i++) {
            $messages[] = $this->failure(fn () => $this->dashboard()->createAdministrator(1, new Login(self::CARE, self::CARE_PASSWORD), 'wordpress@bench.test', 'WordPress', 'Care'))->getMessage();
        }

        self::assertSame('The dashboard refused to make administrator wpcareetec6urx on site 1: ERROR: the password (hidden) is too weak.', $messages[0]);
        foreach ($messages as $message) {
            self::assertStringNotContainsString(self::CARE_PASSWORD, $message);
        }
    }

    public function testSuspendResumeAndRemoveCallMainWpsOwnRoutes(): void
    {
        $this->http->on('POST', 'sites/1/suspend', 'site-suspend', 'site-suspend-again')
            ->on('POST', 'sites/1/unsuspend', 'site-unsuspend')
            ->on('DELETE', 'sites/1/remove', 'site-remove');

        $dashboard = $this->dashboard();
        $dashboard->suspendSite(1);
        $dashboard->suspendSite(1);
        $dashboard->resumeSite(1);
        $dashboard->removeSite(1);

        self::assertSame(['POST', 'POST', 'POST', 'DELETE'], array_column($this->http->sent, 'method'));
    }

    public function testSuspendingOrRemovingASiteTheDashboardNoLongerHoldsIsNotFound(): void
    {
        $this->http->on('POST', 'sites/1/suspend', 'site-suspend-unknown')->on('DELETE', 'sites/1/remove', 'site-remove-unknown');

        self::assertSame(DashboardFailure::NOT_FOUND, $this->failure(fn () => $this->dashboard()->suspendSite(1))->kind);
        $failure = $this->failure(fn () => $this->dashboard()->removeSite(1));
        self::assertSame(DashboardFailure::NOT_FOUND, $failure->kind);
        self::assertSame('The dashboard could not remove site 1: it has no such item (HTTP 404: Invalid or not found ID).', $failure->getMessage());
    }

    public function testA404ThatIsNotMainWpsOwnAnswerDoesNotMeanTheSiteHasGone(): void
    {
        // A WordPress where MainWP's REST API is switched off, or a dashboard address that points elsewhere, answers every route so.
        $noRoute = new HttpResponse(404, '{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}');
        $this->http->on('GET', 'sites/1', $noRoute)->on('DELETE', 'sites/1/remove', new HttpResponse(404, '<html><body>Not Found</body></html>'))->on('POST', 'sites/1/suspend', $noRoute);

        $read = $this->failure(fn () => $this->dashboard()->site(1));
        self::assertSame(DashboardFailure::BAD_ANSWER, $read->kind);
        self::assertSame('The dashboard at dash.example.com does not answer as MainWP\'s REST API when asked to read site 1 (HTTP 404: No route was found matching the URL and request method). Check the dashboard address in the module\'s settings and that MainWP Dashboard is active there; the dashboard\'s permalinks must not be set to Plain.', $read->getMessage());
        self::assertSame(DashboardFailure::BAD_ANSWER, $this->failure(fn () => $this->dashboard()->removeSite(1))->kind);
        self::assertSame(DashboardFailure::BAD_ANSWER, $this->failure(fn () => $this->dashboard()->suspendSite(1))->kind);
    }

    public function testAWrongKeyIsRefusedInPlainWords(): void
    {
        $this->http->on('GET', 'clients/count', 'key-wrong');

        $failure = $this->failure(fn () => $this->dashboard()->counts());

        self::assertSame(DashboardFailure::KEY_REFUSED, $failure->kind);
        self::assertSame('The dashboard refused the API key (HTTP 401: Invalid OAuth parameter). Make a key on the dashboard under API Access and enter it in the module\'s settings. If the key is right, the dashboard\'s web server may not be passing the Authorization header on to WordPress.', $failure->getMessage());
    }

    public function testAMissingKeyIsRefused(): void
    {
        $this->http->on('GET', 'clients/count', 'key-missing');

        self::assertSame(DashboardFailure::KEY_REFUSED, $this->failure(fn () => $this->dashboard()->counts())->kind);
    }

    public function testAnHttpAddressIsRefusedBeforeAnythingIsSent(): void
    {
        // The bench showed MainWP answering a key over plain HTTP (key-over-http.json), so the module refuses it itself.
        $failure = $this->failure(fn () => $this->dashboard('http://dash.example.com')->counts());

        self::assertSame(DashboardFailure::NOT_HTTPS, $failure->kind);
        self::assertSame([], $this->http->sent);
        self::assertSame(200, FixtureHttp::fixture('key-over-http')->status);
    }

    public function testAnUnsetAddressOrKeyIsNotConfigured(): void
    {
        self::assertSame(DashboardFailure::NOT_CONFIGURED, $this->failure(fn () => (new MainWpDashboard('', self::KEY, $this->http))->counts())->kind);
        self::assertSame(DashboardFailure::NOT_CONFIGURED, $this->failure(fn () => (new MainWpDashboard('https://dash.example.com', '', $this->http))->counts())->kind);
        self::assertSame([], $this->http->sent);
    }

    public function testAnUnreachableDashboardSaysSoWithoutTheKey(): void
    {
        $this->http->on('GET', 'clients/count', new \RuntimeException('Could not resolve host: dash.example.com (key ' . self::KEY . ')'));

        $failure = $this->failure(fn () => $this->dashboard()->counts());

        self::assertSame(DashboardFailure::UNREACHABLE, $failure->kind);
        self::assertSame('The dashboard at dash.example.com could not be reached to count the clients: Could not resolve host: dash.example.com (key (hidden)).', $failure->getMessage());
    }

    public function testACallTakenAndNotAnsweredInTimeSaysTheDashboardDidNotAnswerInTime(): void
    {
        $this->http->on('POST', 'sites/74/sync', new \MainWpForWhmcs\Engine\HttpTimeout('Operation timed out after 30001 milliseconds with 0 bytes received'));

        $failure = $this->failure(fn () => $this->dashboard()->syncSite(74));

        self::assertSame(DashboardFailure::TIMED_OUT, $failure->kind);
        self::assertSame('The dashboard at dash.example.com did not answer in time to sync site 74: Operation timed out after 30001 milliseconds with 0 bytes received.', $failure->getMessage());
    }

    public function testAnAnswerTooLargeIsAFailedCallThatSaysSo(): void
    {
        $this->http->on('GET', 'clients/count', new \MainWpForWhmcs\Engine\HttpTooLarge('the answer was larger than 2 MB, so it was not read'));

        $failure = $this->failure(fn () => $this->dashboard()->counts());

        self::assertSame(DashboardFailure::UNREACHABLE, $failure->kind, 'as any failed call');
        self::assertSame('The dashboard at dash.example.com sent an answer larger than 2 MB when asked to count the clients, so it was not read.', $failure->getMessage(), 'it answered: it was reached');
    }

    public function testAServerErrorPageIsABadAnswer(): void
    {
        $http = new class () implements \MainWpForWhmcs\Engine\Http {
            public function request(string $method, string $url, array $headers, array|string $form = []): \MainWpForWhmcs\Engine\HttpResponse
            {
                return new \MainWpForWhmcs\Engine\HttpResponse(500, '<html>Internal Server Error</html>');
            }
        };

        $failure = $this->failure(fn () => (new MainWpDashboard('https://dash.example.com', self::KEY, $http))->site(1));

        self::assertSame(DashboardFailure::BAD_ANSWER, $failure->kind);
        self::assertSame('The dashboard answered "read site 1" with HTTP 500 and no answer MainWP gives.', $failure->getMessage());
    }

    private function failure(callable $call): DashboardFailure
    {
        try {
            $call();
        } catch (DashboardFailure $failure) {
            self::assertStringNotContainsString(self::KEY, $failure->getMessage());
            return $failure;
        }
        self::fail('Expected a DashboardFailure.');
    }
}
