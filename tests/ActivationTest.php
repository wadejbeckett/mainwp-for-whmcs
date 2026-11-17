<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;

/** mainwp_activate and mainwp_deactivate, as WHMCS calls them from System Settings > Addon Modules. */
final class ActivationTest extends TestCase
{
    private MemoryRepository $repo;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../modules/addons/mainwp/mainwp.php';
    }

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Module::$repositoryFactory = fn (): MemoryRepository => $this->repo;
    }

    protected function tearDown(): void
    {
        Module::$repositoryFactory = null;
    }

    public function testActivationCreatesTheThreeTablesAndStartsInDryRun(): void
    {
        $result = \mainwp_activate();

        self::assertSame('success', $result['status'], $result['description']);
        self::assertSame(['mod_mainwp_sites', 'mod_mainwp_tokens', 'mod_mainwp_log'], array_keys($this->repo->tables));
        self::assertSame('on', $this->repo->settings['dry_run']);
        self::assertArrayNotHasKey('care_addon_ids', $this->repo->settings, 'activation stores no care add-on');
        self::assertSame([], \MainWpForWhmcs\Settings::load($this->repo)->careAddonIds, 'no product add-on names the module yet');
        self::assertStringContainsString('dry-run', $result['description']);
    }

    public function testTheTablesHoldWhatTheSpecLists(): void
    {
        \mainwp_activate();
        self::assertSame(['addon_row_id', 'service_id', 'client_id', 'site_url', 'dashboard_client_id', 'dashboard_site_id', 'state', 'retry_count', 'last_sync', 'last_error', 'retry_after', 'login_encrypted', 'other_admins', 'created_at', 'updated_at'], $this->repo->tables['mod_mainwp_sites']);
        self::assertSame(['id', 'token_hash', 'addon_row_id', 'expires_at', 'used_at', 'key_hash', 'key_encrypted', 'created_at'], $this->repo->tables['mod_mainwp_tokens']);
        self::assertSame(['id', 'created_at', 'actor', 'action', 'target', 'outcome', 'dry_run', 'detail'], $this->repo->tables['mod_mainwp_log']);
    }

    public function testReactivationKeepsTheDataAndAStoredLiveMode(): void
    {
        \mainwp_activate();
        $this->repo->saveCareSite(['addon_row_id' => 68, 'state' => 'connected']);
        $this->repo->settings['dry_run'] = '';
        \mainwp_activate();
        self::assertSame('', $this->repo->settings['dry_run']);
        self::assertNotNull($this->repo->careSite(68));
    }

    public function testReactivationAfterTheSettingsWereRemovedStoresNoCareAddOnAndAStoredListIsKept(): void
    {
        \mainwp_activate();
        $this->repo->settings = [];
        \mainwp_activate();
        self::assertArrayNotHasKey('care_addon_ids', $this->repo->settings);
        self::assertSame([], \MainWpForWhmcs\Settings::load($this->repo)->careAddonIds);

        $this->repo->settings['care_addon_ids'] = '16';
        \mainwp_activate();
        self::assertSame('16', $this->repo->settings['care_addon_ids'], 'an install that has a stored list keeps it');
    }

    public function testActivationOverTablesKeptFromAnEarlierVersionBringsThemUpToDate(): void
    {
        // Deactivated without deleting its data at version 0.1.0, the files replaced, activated again: WHMCS calls
        // no upgrade for a module that is not active, so activation runs the steps itself.
        \mainwp_activate();
        $this->repo->execute('ALTER TABLE mod_mainwp_sites DROP COLUMN other_admins');
        $this->repo->settings = ['care_email_domain' => 'care.example.com', 'dry_run' => ''];
        WhmcsCalls::reset();

        $result = \mainwp_activate();

        self::assertSame('success', $result['status'], $result['description']);
        self::assertTrue($this->repo->hasColumn('mod_mainwp_sites', 'other_admins'));
        self::assertSame('wordpress@care.example.com', $this->repo->settings['care_email']);
        self::assertSame('', $this->repo->settings['dry_run'], 'a stored Live mode is kept');
        self::assertSame([
            'MainWP for WHMCS activated over the tables of an earlier version: the setting Care Account Email Domain replaced by Care Account Email',
            'MainWP for WHMCS activated over the tables of an earlier version: column other_admins added to mod_mainwp_sites',
        ], WhmcsCalls::$activity);
    }

    public function testAFreshActivationHasNothingToBringUpToDate(): void
    {
        \mainwp_activate();

        self::assertSame([], WhmcsCalls::$activity);
    }

    public function testDeactivationKeepsTheTablesUnlessDeleteIsTicked(): void
    {
        \mainwp_activate();
        $result = \mainwp_deactivate();
        self::assertSame('success', $result['status']);
        self::assertCount(3, $this->repo->tables);
        self::assertStringContainsString('were kept', $result['description']);

        $this->repo->settings['delete_data'] = 'on';
        $result = \mainwp_deactivate();
        self::assertSame('success', $result['status']);
        self::assertSame([], $this->repo->tables);
        self::assertSame(array_map(static fn (string $t): string => 'DROP TABLE IF EXISTS ' . $t, Schema::TABLES), array_slice($this->repo->executed, -3));
    }

    public function testDeactivationSaysWhatIsKeptAndWhatWhmcsRemoves(): void
    {
        \mainwp_activate();

        $kept = \mainwp_deactivate()['description'];

        self::assertStringContainsString('They hold the care accounts\' logins, encrypted.', $kept);
        self::assertStringContainsString('WHMCS removes the module\'s settings on deactivation: enter them again if you activate it again.', $kept);
    }

    public function testAFailureIsReportedWithoutAFilePath(): void
    {
        Module::$repositoryFactory = static function (): never {
            throw new \RuntimeException('SQLSTATE[42000] in /var/www/whmcs/vendor/illuminate/Connection.php:801');
        };
        $result = \mainwp_activate();
        self::assertSame('error', $result['status']);
        self::assertSame('MainWP for WHMCS could not create its tables: SQLSTATE[42000] in (path)', $result['description']);
    }

    public function testTheConfigureFormHoldsOnlyTheDeleteSwitch(): void
    {
        $config = \mainwp_config();
        self::assertSame('MainWP for WHMCS', $config['name'], 'the add-on module is named for what it is, not for the service it provisions');
        self::assertSame(Module::VERSION, $config['version']);
        self::assertSame(['delete_data'], array_keys($config['fields']));
        self::assertSame('Delete module data on deactivate', $config['fields']['delete_data']['FriendlyName']);
        self::assertArrayNotHasKey('Default', $config['fields']['delete_data']);
    }
}
