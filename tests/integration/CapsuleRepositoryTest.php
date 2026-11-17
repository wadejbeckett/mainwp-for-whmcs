<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Integration;

use Illuminate\Database\Capsule\Manager;
use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\CapsuleRepository;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;

/**
 * CapsuleRepository against a real MySQL or MariaDB, through Illuminate's Capsule Manager aliased
 * as WHMCS\Database\Capsule (what WHMCS itself wraps). Creates minimal versions of the WHMCS tables
 * the class reads, with only the columns it uses, and the module's own tables through
 * Schema::install(); drops all of them afterwards.
 *
 * Skipped unless MAINWP_TEST_DB_HOST, MAINWP_TEST_DB_NAME and MAINWP_TEST_DB_USER are set
 * (MAINWP_TEST_DB_PORT defaults to 3306, MAINWP_TEST_DB_PASSWORD to empty). Needs PHP's pdo_mysql.
 * Refuses to run unless the database name contains "test" and holds none of the tables it creates:
 * never point it at a WHMCS database.
 */
final class CapsuleRepositoryTest extends TestCase
{
    use \MainWpForWhmcs\Tests\Support\ListingContract;

    private const WHMCS_TABLES = [
        'tbladdonmodules' => 'CREATE TABLE tbladdonmodules (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, module VARCHAR(64) NOT NULL, setting VARCHAR(64) NOT NULL, value TEXT NOT NULL) DEFAULT CHARSET=utf8mb4',
        // Columns as read on WHMCS 9.1 (information_schema). DEFAULT (''): MySQL 8 refuses a literal default on TEXT.
        'tbladmins' => "CREATE TABLE tbladmins (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, roleid INT NOT NULL, username TEXT NOT NULL, firstname TEXT NOT NULL DEFAULT (''), lastname TEXT NOT NULL DEFAULT (''), email TEXT NOT NULL DEFAULT (''), disabled INT NOT NULL DEFAULT 0) DEFAULT CHARSET=utf8mb4",
        'tbladminroles' => 'CREATE TABLE tbladminroles (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name TEXT NOT NULL, systememails INT NOT NULL DEFAULT 0, accountemails INT NOT NULL DEFAULT 0, supportemails INT NOT NULL DEFAULT 0) DEFAULT CHARSET=utf8mb4',
        'tblconfiguration' => 'CREATE TABLE tblconfiguration (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, setting VARCHAR(64) NOT NULL, value TEXT NOT NULL) DEFAULT CHARSET=utf8mb4',
        // tbladdons.id and name as read on WHMCS 9.1 (information_schema): int(11), text. module is the
        // module its Module Settings name ("mainwp" on the care add-on, '' on the others, read on 15 November 2026).
        'tbladdons' => "CREATE TABLE tbladdons (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name TEXT NOT NULL, module TEXT NOT NULL DEFAULT ('')) DEFAULT CHARSET=utf8mb4",
        'tblclients' => 'CREATE TABLE tblclients (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, firstname TEXT NOT NULL, lastname TEXT NOT NULL, companyname TEXT NOT NULL, email TEXT NOT NULL) DEFAULT CHARSET=utf8mb4',
        'tblhosting' => "CREATE TABLE tblhosting (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, userid INT NOT NULL, domain TEXT NOT NULL, domainstatus ENUM('Pending','Active','Suspended','Terminated','Cancelled','Fraud','Completed') NOT NULL DEFAULT 'Pending') DEFAULT CHARSET=utf8mb4",
        // billingcycle and recurring as WHMCS types them; the defaults are a priced care add-on's, so that a row inserted without a price is priced.
        'tblhostingaddons' => "CREATE TABLE tblhostingaddons (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, hostingid INT NOT NULL, addonid INT NOT NULL, status ENUM('Pending','Active','Suspended','Terminated','Cancelled','Fraud','Completed') NOT NULL DEFAULT 'Pending', billingcycle VARCHAR(255) NOT NULL DEFAULT 'Monthly', recurring DECIMAL(16,2) NOT NULL DEFAULT 85.00) DEFAULT CHARSET=utf8mb4",
        'tblinvoices' => "CREATE TABLE tblinvoices (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, userid INT NOT NULL, status TEXT NOT NULL) DEFAULT CHARSET=utf8mb4",
        // Columns as read on WHMCS 9.1.
        'tblmodulequeue' => 'CREATE TABLE tblmodulequeue (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, service_type VARCHAR(20) NOT NULL, service_id INT NOT NULL, module_name VARCHAR(64) NOT NULL, module_action VARCHAR(64) NOT NULL, last_attempt TIMESTAMP NULL, last_attempt_error TEXT NOT NULL, num_retries SMALLINT NOT NULL DEFAULT 0, completed TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL) DEFAULT CHARSET=utf8mb4',
        'tblinvoiceitems' => 'CREATE TABLE tblinvoiceitems (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, invoiceid INT NOT NULL, userid INT NOT NULL, type VARCHAR(30) NOT NULL, relid INT NOT NULL, description TEXT NOT NULL) DEFAULT CHARSET=utf8mb4',
    ];

    private const NOW = '2026-11-10 12:00:00';
    private const LATER = '2026-11-10 13:00:00';
    private const EARLIER = '2026-11-10 11:00:00';

    /** @var list<string> tables this run created, dropped at the end */
    private static array $created = [];
    private static bool $connected = false;
    private static ?Manager $capsule = null;

    private CapsuleRepository $repo;

    public static function setUpBeforeClass(): void
    {
        $env = static fn (string $name): string => is_string(getenv($name)) ? (string) getenv($name) : '';
        if ($env('MAINWP_TEST_DB_HOST') === '' || $env('MAINWP_TEST_DB_NAME') === '' || $env('MAINWP_TEST_DB_USER') === '') {
            return; // each test is skipped in setUp()
        }
        if (!str_contains($env('MAINWP_TEST_DB_NAME'), 'test')) {
            self::fail('Refusing to run: the database name in MAINWP_TEST_DB_NAME must contain "test"');
        }
        if (!extension_loaded('pdo_mysql')) {
            self::fail('pdo_mysql is not loaded in this PHP (the integration test needs it)');
        }

        $config = [
            'driver' => 'mysql',
            'host' => $env('MAINWP_TEST_DB_HOST'),
            'port' => $env('MAINWP_TEST_DB_PORT') !== '' ? $env('MAINWP_TEST_DB_PORT') : '3306',
            'database' => $env('MAINWP_TEST_DB_NAME'),
            'username' => $env('MAINWP_TEST_DB_USER'),
            'password' => $env('MAINWP_TEST_DB_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ];
        $capsule = new Manager();
        $capsule->addConnection($config);
        $capsule->addConnection($config, 'second');
        $capsule->setAsGlobal();
        self::$capsule = $capsule;
        if (!class_exists('WHMCS\Database\Capsule')) {
            class_alias(Manager::class, 'WHMCS\Database\Capsule');
        }
        self::$connected = true;

        $tables = array_merge(array_keys(self::WHMCS_TABLES), Schema::TABLES);
        $present = array_values(array_filter($tables, static fn (string $t): bool => Manager::schema()->hasTable($t)));
        if ($present !== []) {
            self::fail('Refusing to run: the database already holds ' . implode(', ', $present));
        }
        try {
            foreach (self::WHMCS_TABLES as $table => $ddl) {
                Manager::connection()->statement($ddl);
                self::$created[] = $table;
            }
            self::$created = array_merge(self::$created, Schema::TABLES);
            Schema::install(new CapsuleRepository());
        } catch (\Throwable $e) {
            // A set-up that fails partway leaves nothing behind for the next run to refuse.
            self::dropCreated();
            throw $e;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (!self::$connected) {
            return;
        }
        self::dropCreated();
    }

    private static function dropCreated(): void
    {
        foreach (self::$created as $table) {
            Manager::connection()->statement('DROP TABLE IF EXISTS ' . $table);
        }
        self::$created = [];
    }

    protected function setUp(): void
    {
        if (!self::$connected) {
            self::markTestSkipped('Set MAINWP_TEST_DB_HOST, MAINWP_TEST_DB_PORT, MAINWP_TEST_DB_NAME, MAINWP_TEST_DB_USER and MAINWP_TEST_DB_PASSWORD to run against a throwaway MySQL or MariaDB database.');
        }
        foreach (self::$created as $table) {
            Manager::connection()->statement('TRUNCATE TABLE ' . $table);
        }
        Manager::connection()->statement('DROP TABLE IF EXISTS mainwp_itest_scratch');
        $this->useConnection('default');
        $this->repo = new CapsuleRepository();
    }

    protected function tearDown(): void
    {
        if (!self::$connected) {
            return;
        }
        Manager::connection('default')->selectOne('SELECT RELEASE_ALL_LOCKS()');
        Manager::connection('second')->selectOne('SELECT RELEASE_ALL_LOCKS()');
        Manager::connection()->statement('DROP TABLE IF EXISTS mainwp_itest_scratch');
        $this->useConnection('default');
    }

    // Schema, execute, hasColumn

    public function testSchemaInstallCreatesTheModuleTablesWithTheirColumns(): void
    {
        foreach (Schema::TABLES as $table) {
            self::assertTrue(Manager::schema()->hasTable($table), $table);
        }
        self::assertTrue($this->repo->hasColumn(Schema::TOKENS, 'key_encrypted'));
        self::assertTrue($this->repo->hasColumn(Schema::SITES, 'login_encrypted'));
        self::assertFalse($this->repo->hasColumn(Schema::SITES, 'no_such_column'));
        self::assertFalse($this->repo->hasColumn('no_such_table', 'id'));
    }

    public function testExecuteRunsAStatementAndHasColumnSeesTheColumnItAdds(): void
    {
        $this->repo->execute('CREATE TABLE mainwp_itest_scratch (id INT NOT NULL PRIMARY KEY) DEFAULT CHARSET=utf8mb4');
        self::assertTrue(Manager::schema()->hasTable('mainwp_itest_scratch'));
        self::assertFalse($this->repo->hasColumn('mainwp_itest_scratch', 'note'));
        $this->repo->execute('ALTER TABLE mainwp_itest_scratch ADD COLUMN note TEXT NULL');
        self::assertTrue($this->repo->hasColumn('mainwp_itest_scratch', 'note'));
        $this->repo->execute('DROP TABLE mainwp_itest_scratch');
        self::assertFalse(Manager::schema()->hasTable('mainwp_itest_scratch'));
    }

    public function testExecuteThrowsOnABadStatement(): void
    {
        $this->expectException(\Throwable::class);
        $this->repo->execute('ALTER TABLE no_such_table ADD COLUMN x INT');
    }

    // Settings and the system URL

    public function testSettingsRoundTripWithCharactersWhmcsEncodes(): void
    {
        $settings = ['dashboard_url' => 'https://dash.example.com/?a=1&b=2', 'note' => 'He said "hi" & <b>left</b>', 'quote' => "O'Brien", 'empty' => ''];
        $this->repo->saveSettings($settings);
        self::assertSame($settings, $this->repo->settings());
        self::assertSame('He said &quot;hi&quot; &amp; &lt;b&gt;left&lt;/b&gt;', Manager::table('tbladdonmodules')->where('module', Module::NAME)->where('setting', 'note')->value('value'), 'stored HTML-encoded, as WHMCS stores its Configure form');
    }

    public function testSavingASettingAgainReplacesItAndKeepsOneRow(): void
    {
        $this->repo->saveSettings(['note' => 'first', 'other' => 'kept']);
        $this->repo->saveSettings(['note' => 'second & last']);
        self::assertSame(['note' => 'second & last', 'other' => 'kept'], $this->repo->settings());
        self::assertSame(1, Manager::table('tbladdonmodules')->where('module', Module::NAME)->where('setting', 'note')->count());
    }

    public function testSettingsReadsOnlyThisModulesRowsAndDecodesValuesWhmcsStored(): void
    {
        Manager::table('tbladdonmodules')->insert([
            ['module' => 'othermodule', 'setting' => 'note', 'value' => 'not ours'],
            ['module' => Module::NAME, 'setting' => 'description', 'value' => 'Care &amp; Backups &#039;plus&#039;'],
        ]);
        self::assertSame(['description' => "Care & Backups 'plus'"], $this->repo->settings());
    }

    public function testSystemUrlLosesItsTrailingSlashAndIsDecoded(): void
    {
        Manager::table('tblconfiguration')->insert(['setting' => 'SystemURL', 'value' => ' https://billing.example.com/a&amp;b/ ']);
        self::assertSame('https://billing.example.com/a&b', $this->repo->systemUrl());
    }

    public function testSystemUrlIsEmptyWhenTheRowIsMissing(): void
    {
        Manager::table('tblconfiguration')->insert(['setting' => 'CompanyName', 'value' => 'Example Hosting']);
        self::assertSame('', $this->repo->systemUrl());
    }

    // Care sites

    public function testACareSiteIsInsertedAndReadBack(): void
    {
        $row = $this->site(68);
        $this->repo->saveCareSite($row);
        self::assertEquals($row, $this->repo->careSite(68));
        self::assertNull($this->repo->careSite(69));
    }

    public function testAPartialRowIsMergedIntoTheExistingSiteWithoutWipingOtherColumns(): void
    {
        $this->repo->saveCareSite($this->site(68));
        $this->repo->saveCareSite(['addon_row_id' => 68, 'state' => 'active', 'dashboard_site_id' => 9, 'last_error' => null, 'updated_at' => self::LATER]);
        $expected = ['state' => 'active', 'dashboard_site_id' => 9, 'last_error' => null, 'updated_at' => self::LATER] + $this->site(68);
        self::assertEquals($expected, $this->repo->careSite(68));
        $stored = $this->repo->careSite(68);
        self::assertIsArray($stored);
        self::assertArrayHasKey('last_error', $stored);
        self::assertNull($stored['last_error'], 'set to NULL, not left as it was');
        self::assertSame(1, Manager::table(Schema::SITES)->count());
    }

    public function testCareSitesAreListedByAddOnRowId(): void
    {
        foreach ([70, 12, 68] as $id) {
            $this->repo->saveCareSite($this->site($id));
        }
        self::assertSame([12, 68, 70], array_map(static fn (array $r): int => (int) $r['addon_row_id'], $this->repo->careSites()));
    }

    // The page's lists (tests/Support/ListingContract.php, run here against the database)

    protected function listingRepository(): \MainWpForWhmcs\Repository
    {
        return $this->repo;
    }

    protected function whmcsHas(array $productAddons, ?string $recordsPerPage): void
    {
        Manager::table('tbladdons')->delete();
        foreach ($productAddons as $id => $name) {
            Manager::table('tbladdons')->insert(['id' => $id, 'name' => $name]);
        }
        Manager::table('tblconfiguration')->where('setting', 'NumRecordstoDisplay')->delete();
        if ($recordsPerPage !== null) {
            Manager::table('tblconfiguration')->insert(['setting' => 'NumRecordstoDisplay', 'value' => $recordsPerPage]);
        }
    }

    protected function whmcsClient(int $id, string $first, string $last, string $company): void
    {
        Manager::table('tblclients')->insert(['id' => $id, 'firstname' => $first, 'lastname' => $last, 'companyname' => $company, 'email' => 'c' . $id . '@example.com']);
    }

    // Log

    public function testALogRowHasItsActorAndTargetCutToTheColumnWidths(): void
    {
        $this->repo->addLog($this->log('provision', str_repeat('é', 70), str_repeat('ü', 300)));
        $row = $this->repo->lastLog(1)[0];
        self::assertSame(str_repeat('é', 64), $row['actor']);
        self::assertSame(str_repeat('ü', 255), $row['target']);
        self::assertSame('provision', $row['action']);
    }

    public function testLastLogIsNewestFirstAndLimited(): void
    {
        foreach (['one', 'two', 'three'] as $action) {
            $this->repo->addLog($this->log($action, 'admin:1', 'addon:68'));
        }
        self::assertSame(['three', 'two'], array_column($this->repo->lastLog(2), 'action'));
        self::assertCount(3, $this->repo->lastLog(10));
    }

    public function testLastLogForIsTheNewestRowWithThatActionAndTarget(): void
    {
        $this->repo->addLog($this->log('sync', 'cron', 'addon:68', 'first'));
        $this->repo->addLog($this->log('sync', 'cron', 'addon:68', 'second'));
        $this->repo->addLog($this->log('sync', 'cron', 'addon:69', 'other target'));
        $this->repo->addLog($this->log('provision', 'cron', 'addon:68', 'other action'));
        self::assertSame('second', $this->repo->lastLogFor('sync', 'addon:68')['detail'] ?? null);
        self::assertNull($this->repo->lastLogFor('sync', 'addon:70'));
    }

    public function testLastLogForCanAskForOneOutcome(): void
    {
        $this->repo->addLog(['outcome' => 'failed'] + $this->log('connect', 'cron', 'add-on 68', 'the dashboard refused the key'));
        $this->repo->addLog(['outcome' => 'ended'] + $this->log('connect', 'cron', 'add-on 68', 'no more retries'));
        self::assertSame('no more retries', $this->repo->lastLogFor('connect', 'add-on 68')['detail'] ?? null);
        self::assertSame('the dashboard refused the key', $this->repo->lastLogFor('connect', 'add-on 68', 'failed')['detail'] ?? null, 'the reason staff need survives the row that ended the retries');
        self::assertNull($this->repo->lastLogFor('connect', 'add-on 68', 'done'));
    }

    public function testLogRowsSinceATimeAreTheLaterOnesWithTheActionsAskedForOldestFirst(): void
    {
        $this->repo->addLog(['created_at' => '2026-11-09 00:00:10'] + $this->log('key', 'cron', 'add-on 67', 'yesterday'));
        $this->repo->addLog(['created_at' => '2026-11-09 08:00:00'] + $this->log('key', 'cron', 'add-on 68', 'given up'));
        // Every dashboard call is a log row: on a busy day they must not push the rows asked for out of the read.
        $this->repo->addLog(['created_at' => '2026-11-09 08:30:00'] + $this->log('sync site 5', 'cron', 'dashboard', 'a call'));
        $this->repo->addLog(['created_at' => '2026-11-09 09:00:00'] + $this->log('suspend', 'whmcs:AfterModuleSuspend', 'a hosting service', 'no ids'));
        self::assertSame(['given up', 'no ids'], array_column($this->repo->logSince('2026-11-09 00:00:10', ['key', 'suspend']), 'detail'));
        self::assertSame(['a call'], array_column($this->repo->logSince('2026-11-09 00:00:10', ['sync site 5']), 'detail'));
        self::assertSame([], $this->repo->logSince('2026-11-09 09:00:00', ['key', 'suspend']));
    }

    // WHMCS rows

    public function testAnAddOnRowCarriesItsServiceClientAndCompanyName(): void
    {
        $this->whmcsAddon(68, 1176, 540, 'Active', 'Active', 'clientsite.example', ['companyname' => 'Client Site Co', 'firstname' => 'Demo', 'lastname' => 'Client']);
        $row = $this->repo->addonRow(68);
        self::assertEquals(new AddonRow(68, 1176, 540, 7, 'Active', 'Active', 'clientsite.example', 'Client Site Co', 'demo@example.com', 0), $row);
        self::assertTrue($row?->billed());
        self::assertNull($this->repo->addonRow(69));
    }

    /** Decision 025: whether a row is set to free is read from its own billing cycle and recurring amount. */
    public function testTheReaderTakesTheCycleAndTheRecurringAmountFromTheAddonsOwnRow(): void
    {
        $this->whmcsAddon(70, 1176, 540, 'Active', 'Active', 'clientsite.example');
        Manager::table('tblhostingaddons')->insert([
            ['id' => 71, 'hostingid' => 1176, 'addonid' => 7, 'status' => 'Active', 'billingcycle' => 'Monthly', 'recurring' => '0.00'],
            ['id' => 72, 'hostingid' => 1176, 'addonid' => 7, 'status' => 'Active', 'billingcycle' => 'Free Account', 'recurring' => '85.00'],
            ['id' => 73, 'hostingid' => 1176, 'addonid' => 7, 'status' => 'Active', 'billingcycle' => 'Annually', 'recurring' => '935.00'],
        ]);

        self::assertSame([70 => false, 71 => true, 72 => true, 73 => false], array_map(static fn (AddonRow $r): bool => $r->free, array_column($this->repo->careAddonRows([7]), null, 'id')));
        self::assertTrue($this->repo->addonRow(71)?->free);
        self::assertFalse($this->repo->addonRow(73)?->free);
    }

    public function testWithoutACompanyTheClientNameIsFirstAndLastName(): void
    {
        $this->whmcsAddon(68, 1176, 540, 'Active', 'Active', 'clientsite.example', ['companyname' => '  ', 'firstname' => 'Demo', 'lastname' => 'Client']);
        self::assertSame('Demo Client', $this->repo->addonRow(68)?->clientName);
    }

    public function testNamesWhmcsStoredEntityEncodedComeOutAsTheClientWroteThem(): void
    {
        $this->whmcsAddon(68, 1176, 540, 'Active', 'Active', 'clientsite.example', ['companyname' => 'Smith &amp; Sons &quot;Web&quot;', 'firstname' => 'Demo', 'lastname' => 'Client']);
        $this->whmcsAddon(69, 1177, 541, 'Active', 'Active', 'example.co.za', ['companyname' => '', 'firstname' => 'Se&aacute;n', 'lastname' => 'O&#039;Brien']);

        self::assertSame('Smith & Sons "Web"', $this->repo->addonRow(68)?->clientName);
        self::assertSame("Seán O'Brien", $this->repo->addonRow(69)?->clientName);
    }

    public function testOnlyUnpaidInvoicesThatItemiseTheAddOnAreCounted(): void
    {
        $this->whmcsAddon(68, 1176, 540, 'Active', 'Active', 'clientsite.example');
        $this->invoice(1, 'Unpaid', [['Addon', 68]]);
        $this->invoice(2, 'Unpaid', [['Addon', 68], ['Hosting', 1176]]);
        $this->invoice(3, 'Paid', [['Addon', 68]]);
        $this->invoice(4, 'Cancelled', [['Addon', 68]]);
        $this->invoice(5, 'Unpaid', [['Hosting', 68]]); // a service row with the same id is not the add-on
        $this->invoice(6, 'Unpaid', [['Addon', 69]]);
        $row = $this->repo->addonRow(68);
        self::assertSame(2, $row?->unpaidInvoices);
        self::assertFalse($row->billed());
    }

    /** Code review, 16 November 2026: an invoice not yet paid under another WHMCS status must not read as paid; a Draft is not a bill. */
    public function testUnpaidPaymentPendingAndCollectionsInvoicesCountAsUnpaid(): void
    {
        $this->whmcsAddon(68, 1176, 540, 'Active', 'Active', 'clientsite.example');
        $this->invoice(1, 'Payment Pending', [['Addon', 68]]);
        $this->invoice(2, 'Collections', [['Addon', 68]]);
        $this->invoice(3, 'Draft', [['Addon', 68]]);
        $this->invoice(4, 'Unpaid', [['Addon', 68]]);
        $this->invoice(5, 'Paid', [['Addon', 68]]);
        $this->invoice(6, 'Cancelled', [['Addon', 68]]);
        $this->invoice(7, 'Refunded', [['Addon', 68]]);
        self::assertSame(3, $this->repo->addonRow(68)?->unpaidInvoices);
    }

    public function testAnUnpaidInvoiceThatItemisesTheAddOnTwiceCountsAsOneInvoice(): void
    {
        $this->whmcsAddon(68, 1176, 540, 'Active', 'Active', 'clientsite.example');
        $this->invoice(1, 'Unpaid', [['Addon', 68], ['Addon', 68]]);
        self::assertSame(1, $this->repo->addonRow(68)?->unpaidInvoices, 'AddonRow: "how many unpaid invoices itemise it"');
    }

    public function testAnAddOnWhoseServiceOrClientIsGoneIsNotFound(): void
    {
        Manager::table('tblhostingaddons')->insert(['id' => 68, 'hostingid' => 9999, 'addonid' => 7, 'status' => 'Active']);
        self::assertNull($this->repo->addonRow(68));
    }

    public function testServiceAddOnRowsAreTheServicesRowsByRowId(): void
    {
        $this->whmcsAddon(70, 1176, 540, 'Pending', 'Active', 'clientsite.example');
        Manager::table('tblhostingaddons')->insert([
            ['id' => 68, 'hostingid' => 1176, 'addonid' => 7, 'status' => 'Active'],
            ['id' => 69, 'hostingid' => 1177, 'addonid' => 7, 'status' => 'Active'],
        ]);
        $this->invoice(1, 'Unpaid', [['Addon', 70]]);
        $rows = $this->repo->serviceAddonRows(1176);
        self::assertSame([68, 70], array_map(static fn (AddonRow $r): int => $r->id, $rows));
        self::assertSame(['Active', 'Pending'], array_map(static fn (AddonRow $r): string => $r->status, $rows));
        self::assertSame([0, 1], array_map(static fn (AddonRow $r): int => $r->unpaidInvoices, $rows));
        self::assertSame([], $this->repo->serviceAddonRows(4242));
    }

    public function testCareAddOnRowsAreEveryRowOfTheGivenAddOnsWhateverItsStatus(): void
    {
        // The daily check reads every care add-on: active, suspended and ended ones alike.
        $this->whmcsAddon(70, 1176, 540, 'Suspended', 'Active', 'clientsite.example');
        Manager::table('tblhostingaddons')->insert([
            ['id' => 68, 'hostingid' => 1176, 'addonid' => 7, 'status' => 'Cancelled'],
            ['id' => 69, 'hostingid' => 1176, 'addonid' => 16, 'status' => 'Active'],
            ['id' => 71, 'hostingid' => 1176, 'addonid' => 21, 'status' => 'Active'],
            ['id' => 72, 'hostingid' => 9999, 'addonid' => 7, 'status' => 'Active'],
        ]);
        $this->invoice(1, 'Unpaid', [['Addon', 71]]);

        $rows = $this->repo->careAddonRows([7, 21]);

        self::assertSame([68, 70, 71], array_map(static fn (AddonRow $r): int => $r->id, $rows), 'by row id; another product\'s add-on is left out, and so is a row whose service is gone');
        self::assertSame(['Cancelled', 'Suspended', 'Active'], array_map(static fn (AddonRow $r): string => $r->status, $rows));
        self::assertSame([0, 0, 1], array_map(static fn (AddonRow $r): int => $r->unpaidInvoices, $rows));
        self::assertSame([], $this->repo->careAddonRows([]), 'no care add-on configured: nothing is read');
        self::assertSame([], $this->repo->careAddonRows([999]));
    }

    public function testTheCareAddOnsAreTheProductAddOnsWhoseModuleColumnIsThisModulesName(): void
    {
        Manager::table('tbladdons')->insert([
            ['id' => 21, 'name' => 'Backups', 'module' => 'mainwp'],
            ['id' => 3, 'name' => 'Extra', 'module' => ''],
            ['id' => 16, 'name' => 'WordPress Care', 'module' => Module::NAME],
            ['id' => 5, 'name' => 'Hosting Panel', 'module' => 'cpanel'],
            ['id' => 7, 'name' => 'Other Spelling', 'module' => 'MainWP'],
            ['id' => 8, 'name' => 'Trailing Space', 'module' => 'mainwp '],
            ['id' => 9, 'name' => 'Leading Space', 'module' => ' mainwp'],
        ]);

        self::assertSame([16, 21], $this->repo->careAddonIds(), 'in ascending order, the module\'s system name exactly: MySQL\'s own comparison ignores case and trailing spaces');

        Manager::table('tbladdons')->delete();
        self::assertSame([], $this->repo->careAddonIds(), 'no product add-on names the module');
    }

    public function testFailedAddonCommandsAreTheOpenRowsOfThisModuleForCareAddonsButACreate(): void
    {
        Manager::table('tblhostingaddons')->insert([
            ['id' => 70, 'hostingid' => 1176, 'addonid' => 16, 'status' => 'Active'],
            ['id' => 71, 'hostingid' => 1176, 'addonid' => 16, 'status' => 'Suspended'],
            ['id' => 90, 'hostingid' => 1176, 'addonid' => 7, 'status' => 'Active'],
        ]);
        $row = static fn (int $id, int $serviceId, string $action, array $more = []): array => $more + ['id' => $id, 'service_type' => 'addon', 'service_id' => $serviceId, 'module_name' => 'mainwp', 'module_action' => $action, 'last_attempt' => '2026-11-15 00:05:12', 'last_attempt_error' => 'The dashboard could not be reached.', 'num_retries' => 2, 'completed' => 0, 'created_at' => '2026-11-14 00:05:00', 'updated_at' => '2026-11-15 00:05:12'];
        Manager::table('tblmodulequeue')->insert([
            $row(1, 70, 'SuspendAccount'),
            $row(2, 71, 'UnsuspendAccount', ['num_retries' => 0]),
            $row(3, 70, 'TerminateAccount', ['completed' => 1]),
            $row(4, 70, 'CreateAccount'),
            $row(5, 70, 'SuspendAccount', ['module_name' => 'cpanel']),
            $row(6, 70, 'SuspendAccount', ['service_type' => 'service']),
            $row(7, 90, 'SuspendAccount'),
            $row(8, 999, 'TerminateAccount'),
            $row(9, 70, 'createaccount'),
            $row(10, 70, 'CREATEACCOUNT'),
            $row(11, 70, 'ChangePackage'),
            $row(12, 71, 'suspendaccount'),
            $row(13, 71, 'SUSPENDACCOUNT'),
        ]);

        $found = $this->repo->failedAddonCommands([16]);
        self::assertSame([
            ['id' => 1, 'addon_row_id' => 70, 'action' => 'SuspendAccount', 'error' => 'The dashboard could not be reached.', 'retries' => 2, 'last_attempt' => '2026-11-15 00:05:12'],
            ['id' => 2, 'addon_row_id' => 71, 'action' => 'UnsuspendAccount', 'error' => 'The dashboard could not be reached.', 'retries' => 0, 'last_attempt' => '2026-11-15 00:05:12'],
        ], array_slice($found, 0, 2));
        self::assertSame([1, 2, 11, 12, 13], array_column($found, 'id'), 'every open row of a care add-on whatever its word, but a Create in any letter case');
        self::assertSame(['SuspendAccount', 'UnsuspendAccount', 'ChangePackage', 'suspendaccount', 'SUSPENDACCOUNT'], array_column($found, 'action'), 'the word as WHMCS stored it');
        self::assertSame([], $this->repo->failedAddonCommands([]));
        self::assertSame([13, 1], [Manager::table('tblmodulequeue')->count(), Manager::table('tblmodulequeue')->where('completed', 1)->count()], 'read only: nothing in the queue changed');
    }

    public function testAddOnRowsOnAnInvoiceAreTheAddonItemsOnceEach(): void
    {
        $this->invoice(1, 'Unpaid', [['Addon', 70], ['Hosting', 1176], ['Addon', 68], ['Addon', 70], ['Addon', 0], ['PromoHosting', 69]]);
        $this->invoice(2, 'Unpaid', [['Addon', 71]]);
        self::assertSame([70, 68], $this->repo->addonRowsOnInvoice(1));
        self::assertSame([], $this->repo->addonRowsOnInvoice(3));
    }

    public function testTheAdministratorsWhoReceiveSystemEmailsAreTheActiveOnesWhoseRoleDoes(): void
    {
        Manager::table('tbladminroles')->insert([['id' => 1, 'name' => 'Full Administrator', 'systememails' => 1], ['id' => 2, 'name' => 'Support', 'systememails' => 0]]);
        Manager::table('tbladmins')->insert([
            ['id' => 1, 'roleid' => 1, 'username' => 'a', 'firstname' => 'Ada', 'lastname' => 'Admin', 'disabled' => 0],
            ['id' => 2, 'roleid' => 2, 'username' => 'b', 'firstname' => 'Sam', 'lastname' => 'Support', 'disabled' => 0],
            ['id' => 3, 'roleid' => 1, 'username' => 'c', 'firstname' => 'Old', 'lastname' => 'Admin', 'disabled' => 1],
            ['id' => 4, 'roleid' => 1, 'username' => 'd', 'firstname' => 'Bea', 'lastname' => '', 'disabled' => 0],
        ]);
        self::assertSame(['Ada Admin', 'Bea'], $this->repo->systemEmailAdmins(), 'who WHMCS\'s SendAdminEmail of type "system" goes to');
    }

    public function testAnAdminsRoleIsReadAndAMissingAdminHasNone(): void
    {
        Manager::table('tbladmins')->insert(['id' => 3, 'roleid' => 2, 'username' => 'staff']);
        self::assertSame(2, $this->repo->adminRoleId(3));
        self::assertNull($this->repo->adminRoleId(4));
    }

    // Approvals

    public function testAnApprovalIsStoredAndFoundByItsTokenHash(): void
    {
        $this->repo->addApproval($this->approvalRow('a', 68, self::LATER));
        $row = $this->repo->approval($this->hash('a'));
        self::assertNotNull($row);
        self::assertSame(['token_hash' => $this->hash('a'), 'addon_row_id' => 68, 'expires_at' => self::LATER, 'used_at' => null, 'key_hash' => null, 'key_encrypted' => null, 'created_at' => self::EARLIER], array_diff_key($row, ['id' => true]));
        self::assertNull($this->repo->approval($this->hash('b')));
    }

    public function testApprovalsAreTheAddOnsRowsOldestFirst(): void
    {
        $this->repo->addApproval($this->approvalRow('a', 68, self::LATER));
        $this->repo->addApproval($this->approvalRow('b', 69, self::LATER));
        $this->repo->addApproval($this->approvalRow('c', 68, self::NOW));
        self::assertSame([$this->hash('a'), $this->hash('c')], array_column($this->repo->approvals(68), 'token_hash'));
        self::assertSame([], $this->repo->approvals(70));
    }

    public function testTheFirstClaimOfATokenWinsAndStoresTheKey(): void
    {
        $this->repo->addApproval($this->approvalRow('a', 68, self::LATER));
        self::assertTrue($this->repo->claimApproval($this->hash('a'), 'encrypted-1', $this->hash('key-1'), self::NOW));
        $row = $this->repo->approval($this->hash('a'));
        self::assertSame([self::NOW, $this->hash('key-1'), 'encrypted-1'], [$row['used_at'] ?? null, $row['key_hash'] ?? null, $row['key_encrypted'] ?? null]);
    }

    public function testASecondClaimOfATokenFailsAndChangesNothing(): void
    {
        $this->repo->addApproval($this->approvalRow('a', 68, self::LATER));
        self::assertTrue($this->repo->claimApproval($this->hash('a'), 'encrypted-1', $this->hash('key-1'), self::NOW));
        $before = $this->repo->approval($this->hash('a'));
        self::assertFalse($this->repo->claimApproval($this->hash('a'), 'encrypted-2', $this->hash('key-2'), '2026-11-10 12:30:00'));
        self::assertSame($before, $this->repo->approval($this->hash('a')));
    }

    public function testAClaimFailsWhenTheTokenDoesNotExpireAfterTheGivenTime(): void
    {
        $this->repo->addApproval($this->approvalRow('a', 68, self::NOW));
        $this->repo->addApproval($this->approvalRow('b', 68, self::EARLIER));
        self::assertFalse($this->repo->claimApproval($this->hash('a'), 'encrypted', $this->hash('key'), self::NOW), 'expires exactly at the time given');
        self::assertFalse($this->repo->claimApproval($this->hash('b'), 'encrypted', $this->hash('key'), self::NOW), 'expired before');
        self::assertFalse($this->repo->claimApproval($this->hash('none'), 'encrypted', $this->hash('key'), self::NOW), 'no such token');
        foreach ($this->repo->approvals(68) as $row) {
            self::assertNull($row['used_at']);
            self::assertNull($row['key_encrypted']);
        }
    }

    public function testLapsingChangesOnlyTheAddOnsUnusedRowsThatExpireLater(): void
    {
        $this->repo->addApproval($this->approvalRow('open', 68, self::LATER));
        $this->repo->addApproval($this->approvalRow('used', 68, self::LATER));
        $this->repo->claimApproval($this->hash('used'), 'encrypted', $this->hash('key'), self::EARLIER);
        $this->repo->addApproval($this->approvalRow('expired', 68, self::EARLIER));
        $this->repo->addApproval($this->approvalRow('other', 69, self::LATER));
        $this->repo->lapseApprovals(68, self::NOW);
        $expires = static fn (array $rows): array => array_column($rows, 'expires_at', 'token_hash');
        self::assertSame([$this->hash('open') => self::NOW, $this->hash('used') => self::LATER, $this->hash('expired') => self::EARLIER], $expires($this->repo->approvals(68)));
        self::assertSame([$this->hash('other') => self::LATER], $expires($this->repo->approvals(69)));
        self::assertFalse($this->repo->claimApproval($this->hash('open'), 'encrypted', $this->hash('key'), self::NOW), 'a lapsed link cannot be claimed');
    }

    public function testReleasingKeysClearsOnlyTheAddOnsKeys(): void
    {
        foreach (['a' => 68, 'b' => 68, 'c' => 69] as $token => $addon) {
            $this->repo->addApproval($this->approvalRow($token, $addon, self::LATER));
            $this->repo->claimApproval($this->hash($token), 'encrypted-' . $token, $this->hash('key-' . $token), self::NOW);
        }
        $this->repo->releaseApprovalKeys(68);
        self::assertSame([null, null], array_column($this->repo->approvals(68), 'key_encrypted'));
        self::assertSame([$this->hash('key-a'), $this->hash('key-b')], array_column($this->repo->approvals(68), 'key_hash'), 'the key hash stays');
        self::assertSame(['encrypted-c'], array_column($this->repo->approvals(69), 'key_encrypted'));
    }

    public function testPurgingDeletesExpiredRowsWithoutAKeyAndKeepsARowHoldingAKeyWhateverItsAge(): void
    {
        $this->repo->addApproval($this->approvalRow('old', 68, '2026-01-01 00:00:00'));
        $this->repo->addApproval($this->approvalRow('old-used', 68, '2026-01-01 00:00:00'));
        $this->repo->claimApproval($this->hash('old-used'), 'encrypted', $this->hash('key'), '2025-12-31 23:00:00');
        $this->repo->addApproval($this->approvalRow('old-released', 68, '2026-01-01 00:00:00'));
        $this->repo->claimApproval($this->hash('old-released'), 'encrypted', $this->hash('key'), '2025-12-31 23:00:00');
        Manager::table(Schema::TOKENS)->where('token_hash', $this->hash('old-released'))->update(['key_encrypted' => null]);
        $this->repo->addApproval($this->approvalRow('at-cutoff', 68, self::NOW));
        $this->repo->addApproval($this->approvalRow('fresh', 68, self::LATER));
        $this->repo->purgeApprovals(self::NOW);
        self::assertSame([$this->hash('old-used'), $this->hash('at-cutoff'), $this->hash('fresh')], array_column($this->repo->approvals(68), 'token_hash'));
    }

    // Locks

    public function testASecondConnectionCannotTakeAHeldLockUntilItIsReleased(): void
    {
        self::assertTrue($this->repo->lock('addon:68'));
        $this->useConnection('second');
        $start = microtime(true);
        self::assertFalse($this->repo->lock('addon:68'));
        self::assertLessThan(0.5, microtime(true) - $start, 'no wait');
        $this->useConnection('default');
        $this->repo->unlock('addon:68');
        $this->useConnection('second');
        self::assertTrue($this->repo->lock('addon:68'));
        $this->repo->unlock('addon:68');
    }

    public function testTheSameConnectionCanTakeItsOwnLockAgain(): void
    {
        self::assertTrue($this->repo->lock('addon:68'));
        self::assertTrue($this->repo->lock('addon:68'));
    }

    public function testAWaitingLockGivesUpAfterItsWait(): void
    {
        self::assertTrue($this->repo->lock('addon:68'));
        $this->useConnection('second');
        $start = microtime(true);
        self::assertFalse($this->repo->lock('addon:68', 1));
        $waited = microtime(true) - $start;
        self::assertGreaterThan(0.9, $waited);
        self::assertLessThan(3.0, $waited);
    }

    public function testANegativeWaitDoesNotWait(): void
    {
        self::assertTrue($this->repo->lock('addon:68'));
        $this->useConnection('second');
        $start = microtime(true);
        self::assertFalse($this->repo->lock('addon:68', -5));
        self::assertLessThan(0.5, microtime(true) - $start);
    }

    public function testDifferentNamesDoNotBlockEachOther(): void
    {
        self::assertTrue($this->repo->lock('addon:68'));
        $this->useConnection('second');
        self::assertTrue($this->repo->lock('addon:69'));
    }

    public function testTheLockNameMySqlSeesCarriesTheDatabaseAndIsAtMost64Characters(): void
    {
        $database = (string) Manager::connection()->getDatabaseName();
        $long = 'addon:' . str_repeat('9', 100);
        self::assertTrue($this->repo->lock($long));
        $expected = substr(Module::NAME . ':' . substr(sha1($database), 0, 12) . ':' . $long, 0, 64);
        self::assertSame(64, strlen($expected));
        $holder = Manager::connection()->selectOne('SELECT IS_USED_LOCK(?) AS holder, CONNECTION_ID() AS me', [$expected]);
        self::assertIsObject($holder);
        self::assertSame((int) $holder->me, (int) $holder->holder, 'the lock is held under the 64-character name');

        $short = Module::NAME . ':' . substr(sha1($database), 0, 12) . ':addon:68';
        self::assertTrue($this->repo->lock('addon:68'));
        $free = Manager::connection()->selectOne('SELECT IS_FREE_LOCK(?) AS free, IS_FREE_LOCK(?) AS bare', [$short, 'addon:68']);
        self::assertIsObject($free);
        self::assertSame([0, 1], [(int) $free->free, (int) $free->bare], 'held under the prefixed name, not the bare one');
        $this->repo->unlock('addon:68');
        $after = Manager::connection()->selectOne('SELECT IS_FREE_LOCK(?) AS free', [$short]);
        self::assertIsObject($after);
        self::assertSame(1, (int) $after->free, 'unlock releases it');
    }

    public function testLongNamesThatShareTheFirst64CharactersShareALock(): void
    {
        $prefix = 'addon:' . str_repeat('x', 60);
        self::assertTrue($this->repo->lock($prefix . 'a'));
        $this->useConnection('second');
        self::assertFalse($this->repo->lock($prefix . 'b'), 'cut to 64 characters, both names are the same lock');
    }

    // Helpers

    private function useConnection(string $name): void
    {
        self::$capsule?->getDatabaseManager()->setDefaultConnection($name);
    }

    /** @return array<string, mixed> */
    private function site(int $addonRowId): array
    {
        return [
            'addon_row_id' => $addonRowId,
            'service_id' => 1176,
            'client_id' => 540,
            'site_url' => 'https://clientsite.example',
            'dashboard_client_id' => 3,
            'dashboard_site_id' => null,
            'state' => 'connecting',
            'retry_count' => 2,
            'last_sync' => null,
            'last_error' => 'Dashboard said "no" & <stopped>',
            'retry_after' => self::LATER,
            'login_encrypted' => 'ciphertext',
            'other_admins' => '["owner"]',
            'created_at' => self::EARLIER,
            'updated_at' => self::NOW,
        ];
    }

    /** @return array{created_at: string, actor: string, action: string, target: string, outcome: string, dry_run: int, detail: string} */
    private function log(string $action, string $actor, string $target, string $detail = ''): array
    {
        return ['created_at' => self::NOW, 'actor' => $actor, 'action' => $action, 'target' => $target, 'outcome' => 'ok', 'dry_run' => 0, 'detail' => $detail];
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array{token_hash: string, addon_row_id: int, expires_at: string, created_at: string} */
    private function approvalRow(string $token, int $addonRowId, string $expiresAt): array
    {
        return ['token_hash' => $this->hash($token), 'addon_row_id' => $addonRowId, 'expires_at' => $expiresAt, 'created_at' => self::EARLIER];
    }

    /** @param array{companyname?: string, firstname?: string, lastname?: string} $client */
    private function whmcsAddon(int $id, int $serviceId, int $clientId, string $status, string $serviceStatus, string $domain, array $client = []): void
    {
        Manager::table('tblclients')->insert(['id' => $clientId, 'email' => 'demo@example.com'] + $client + ['companyname' => '', 'firstname' => 'Demo', 'lastname' => 'Client']);
        Manager::table('tblhosting')->insert(['id' => $serviceId, 'userid' => $clientId, 'domain' => $domain, 'domainstatus' => $serviceStatus]);
        Manager::table('tblhostingaddons')->insert(['id' => $id, 'hostingid' => $serviceId, 'addonid' => 7, 'status' => $status]);
    }

    /** @param list<array{string, int}> $items type and relid of each line */
    private function invoice(int $id, string $status, array $items): void
    {
        Manager::table('tblinvoices')->insert(['id' => $id, 'userid' => 540, 'status' => $status]);
        foreach ($items as [$type, $relid]) {
            Manager::table('tblinvoiceitems')->insert(['invoiceid' => $id, 'userid' => 540, 'type' => $type, 'relid' => $relid, 'description' => $type . ' ' . $relid]);
        }
    }
}
