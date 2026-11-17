<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Support;

use MainWpForWhmcs\Engine\CareSite;
use MainWpForWhmcs\Engine\State;
use MainWpForWhmcs\Repository;
use MainWpForWhmcs\RepositoryStorage;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Upgrade;
use MainWpForWhmcs\WhmcsEncrypter;

/**
 * What the admin page's lists ask of a repository: the log filtered, sorted and cut into pages,
 * cleared and pruned, WHMCS's product add-ons by name and its page size; and the upgrade of
 * version 0.2.0 with what it needs (a setting deleted, a care site's other administrators kept
 * through the engine's storage). The same tests run
 * against the in-memory repository of the unit tests and, with a database, against the real one,
 * so the page's tests rest on a stand-in that answers as MySQL does.
 */
trait ListingContract
{
    abstract protected function listingRepository(): Repository;

    /** Puts WHMCS's own rows in place: product add-ons (id => name) and a tblconfiguration value. */
    abstract protected function whmcsHas(array $productAddons, ?string $recordsPerPage): void;

    /** Puts a client in WHMCS's own table, the names as WHMCS stores them. */
    abstract protected function whmcsClient(int $id, string $first, string $last, string $company): void;

    public function testASettingIsDeletedAndNoOther(): void
    {
        $repo = $this->listingRepository();
        $repo->saveSettings(['care_email_domain' => 'care.example.com', 'dry_run' => 'on']);

        $repo->deleteSetting('care_email_domain');
        $repo->deleteSetting('no_such_setting');

        self::assertSame(['dry_run' => 'on'], $repo->settings());
    }

    public function testTheUpgradeTo020AddsTheOtherAdministratorsColumnAndMovesTheCareEmailOnce(): void
    {
        $repo = $this->listingRepository();
        Schema::install($repo);
        $repo->execute('ALTER TABLE mod_mainwp_sites DROP COLUMN other_admins'); // as an install of 0.1.0 has it
        $repo->saveSettings(['care_email_domain' => 'care.example.com', 'dry_run' => 'on']);
        self::assertFalse($repo->hasColumn(Schema::SITES, 'other_admins'));

        self::assertSame(['the setting Care Account Email Domain replaced by Care Account Email', 'column other_admins added to mod_mainwp_sites'], Upgrade::run($repo, '0.1.0'));
        self::assertSame([], Upgrade::run($repo, '0.1.0'), 'a second run changes nothing');

        self::assertTrue($repo->hasColumn(Schema::SITES, 'other_admins'));
        $settings = $repo->settings();
        ksort($settings);
        self::assertSame(['care_email' => 'wordpress@care.example.com', 'dry_run' => 'on'], $settings);
    }

    public function testACareSitesOtherAdministratorsAreKeptInTheirOrderAsAJsonList(): void
    {
        $repo = $this->listingRepository();
        $storage = new RepositoryStorage($repo, new WhmcsEncrypter(), static fn (): string => '2026-11-11 15:00:00');
        $site = static fn (int $id): CareSite => new CareSite($id, 1176, 540, 'https://clientsite.example', State::Connected, 100, 7, 0, '2026-11-11 15:00:00');

        $storage->saveSite($site(68)->withOtherAdmins(['owner', 'Jane O\'Brien', 'éditeur']));
        $storage->saveSite($site(69)->withOtherAdmins([]));
        $storage->saveSite($site(70));

        self::assertSame(['owner', 'Jane O\'Brien', 'éditeur'], $storage->site(68)?->otherAdmins);
        self::assertSame('["owner","Jane O\'Brien","éditeur"]', $repo->careSite(68)['other_admins'] ?? null, 'a JSON list in the column');
        self::assertSame([], $storage->site(69)?->otherAdmins, 'none recorded is not never recorded');
        self::assertNull($storage->site(70)?->otherAdmins);
        self::assertNull($repo->careSite(70)['other_admins'] ?? null);

        $storage->saveSite($storage->site(68)?->failed('reason') ?? $site(0));
        self::assertSame(['owner', 'Jane O\'Brien', 'éditeur'], $storage->site(68)?->otherAdmins, 'saving the site again keeps them');
    }

    public function testAClientIsNamedAsWhmcsListsThem(): void
    {
        $this->whmcsClient(540, 'Demo', 'Client', '');
        $this->whmcsClient(541, 'Ann', 'Other', 'Brown &amp; Sons');

        self::assertSame('Demo Client', $this->listingRepository()->clientName(540));
        self::assertSame('Brown & Sons', $this->listingRepository()->clientName(541), 'the company when there is one, decoded once');
        self::assertNull($this->listingRepository()->clientName(999));
    }

    private function logged(Repository $repo): Repository
    {
        foreach ([
            ['2026-11-09 08:00:00', 'cron:daily', 'provision', 'add-on 64', 'done', 'built WHMCS client 104 on the dashboard'],
            ['2026-11-10 09:15:00', 'client:107', 'connect', 'add-on 66', 'failed', 'The login page shows a captcha.'],
            ['2026-11-10 09:15:00', 'admin:1', 'connect', 'add-on 66', 'done', 'connected https://oldmillBAKERY.example'],
            ['2026-11-11 00:03:54', 'cron:daily', 'daily check', 'all care add-ons', 'done', '11 care add-ons: 100% in step'],
            ['2026-11-11 08:30:49', 'admin:1', 'test connection', 'dashboard', 'failed', 'The dashboard refused the API key.'],
        ] as [$at, $actor, $action, $target, $outcome, $detail]) {
            $repo->addLog(['created_at' => $at, 'actor' => $actor, 'action' => $action, 'target' => $target, 'outcome' => $outcome, 'dry_run' => 0, 'detail' => $detail]);
        }
        return $repo;
    }

    /** @param list<array<string, mixed>> $rows */
    private static function details(array $rows): array
    {
        return array_map(static fn (array $row): string => substr((string) $row['detail'], 0, 12), $rows);
    }

    public function testTheLogIsCountedAndCutIntoPagesNewestFirst(): void
    {
        $repo = $this->logged($this->listingRepository());

        self::assertSame(5, $repo->logCount([]));
        self::assertSame(['The dashboar', '11 care add-'], self::details($repo->logPage([], 'created_at', 'desc', 0, 2)));
        self::assertSame(['connected ht', 'The login pa'], self::details($repo->logPage([], 'created_at', 'desc', 2, 2)), 'two rows of the same second keep the order they were written in, newest first');
        self::assertSame(['built WHMCS '], self::details($repo->logPage([], 'created_at', 'desc', 4, 2)));
        self::assertSame([], $repo->logPage([], 'created_at', 'desc', 6, 2));
    }

    public function testTheLogSortsByAHeadingEitherWay(): void
    {
        $repo = $this->logged($this->listingRepository());

        self::assertSame(['admin:1', 'admin:1', 'client:107', 'cron:daily', 'cron:daily'], array_column($repo->logPage([], 'actor', 'asc', 0, 50), 'actor'));
        self::assertSame(['connected ht', 'The dashboar'], self::details(array_slice($repo->logPage([], 'actor', 'asc', 0, 50), 0, 2)), 'equal values stay in the order written');
        self::assertSame(['test connection', 'provision', 'daily check', 'connect', 'connect'], array_column($repo->logPage([], 'action', 'desc', 0, 50), 'action'));
        self::assertSame(['built WHMCS ', 'The login pa'], self::details($repo->logPage([], 'created_at', 'asc', 0, 2)));
        self::assertSame(self::details($repo->logPage([], 'created_at', 'desc', 0, 50)), self::details($repo->logPage([], 'id; DROP TABLE x', 'sideways', 0, 50)), 'anything but a known heading and direction is the default order');
    }

    public function testTheLogIsFilteredByWordsAndByOutcome(): void
    {
        $repo = $this->logged($this->listingRepository());

        self::assertSame(['connected ht'], self::details($repo->logPage(['search' => 'bakery'], 'created_at', 'desc', 0, 50)), 'in any case');
        self::assertSame(2, $repo->logCount(['search' => 'add-on 66']));
        self::assertSame(2, $repo->logCount(['search' => '2026-11-10']), 'a date finds its day');
        self::assertSame(3, $repo->logCount(['search' => 'ADMIN:1 ']) + $repo->logCount(['search' => 'client:107']), 'the words are trimmed');
        self::assertSame(2, $repo->logCount(['outcome' => 'failed']));
        self::assertSame(['The login pa'], self::details($repo->logPage(['search' => 'connect', 'outcome' => 'failed'], 'created_at', 'asc', 0, 1)));
        self::assertSame(2, $repo->logCount(['search' => 'connect', 'outcome' => 'failed']));
        self::assertSame(0, $repo->logCount(['search' => 'nothing like it']));
        self::assertSame(5, $repo->logCount(['search' => '', 'outcome' => '']));
    }

    public function testASignThatMeansAnythingToSqlIsSearchedForAsItIs(): void
    {
        $repo = $this->logged($this->listingRepository());

        self::assertSame(1, $repo->logCount(['search' => '100%']));
        self::assertSame(0, $repo->logCount(['search' => '1_0']));
        self::assertSame(0, $repo->logCount(['search' => '%']) - 1, 'only the row that holds the sign');
        self::assertSame(0, $repo->logCount(['search' => "' OR '1'='1"]));
        self::assertSame(0, $repo->logCount(['search' => 'back\\slash']));
    }

    public function testTheOutcomesInTheLogAreListedOnce(): void
    {
        self::assertSame([], $this->listingRepository()->logOutcomes());
        self::assertSame(['done', 'failed'], $this->logged($this->listingRepository())->logOutcomes());
    }

    public function testClearingTheLogRemovesEveryRowAndSaysHowMany(): void
    {
        $repo = $this->logged($this->listingRepository());

        self::assertSame(5, $repo->clearLog());
        self::assertSame(0, $repo->logCount([]));
        self::assertSame([], $repo->lastLog(10));
        self::assertSame(0, $repo->clearLog());
    }

    public function testPruningRemovesOnlyTheRowsWrittenBeforeATime(): void
    {
        $repo = $this->logged($this->listingRepository());

        self::assertSame(3, $repo->pruneLog('2026-11-11 00:00:00'));
        self::assertSame(['The dashboar', '11 care add-'], self::details($repo->logPage([], 'created_at', 'desc', 0, 50)));
        self::assertSame(0, $repo->pruneLog('2026-11-11 00:03:54'), 'a row written at the time itself stays');
    }

    public function testWhmcsProductAddonsAreListedByName(): void
    {
        $this->whmcsHas([16 => 'WordPress Care', 3 => 'Extra &amp; Storage', 21 => 'Backups'], null);

        self::assertSame([21 => 'Backups', 3 => 'Extra & Storage', 16 => 'WordPress Care'], $this->listingRepository()->productAddons());
    }

    public function testTheListsShowAsManyRecordsAsWhmcsItself(): void
    {
        $this->whmcsHas([], null);
        self::assertSame(50, $this->listingRepository()->recordsPerPage(), 'WHMCS\'s own default');
        foreach (['25' => 25, '100' => 100, '0' => 50, '-5' => 50, 'many' => 50, '5000' => 50] as $stored => $shown) {
            $this->whmcsHas([], (string) $stored);
            self::assertSame($shown, $this->listingRepository()->recordsPerPage(), 'stored as ' . $stored);
        }
    }
}
