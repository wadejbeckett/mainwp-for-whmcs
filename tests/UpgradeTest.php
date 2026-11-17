<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\Upgrade;

/** WHMCS calls mainwp_upgrade when the Addon Modules page is opened after newer files were copied. */
final class UpgradeTest extends TestCase
{
    private MemoryRepository $repo;

    /** Two steps of imagined later versions, the shape real steps will have. */
    private const STEPS = [
        ['version' => '0.2.0', 'sentence' => 'column note added to mod_mainwp_sites', 'statements' => ['ALTER TABLE mod_mainwp_sites ADD COLUMN note VARCHAR(255) NULL'], 'unless' => ['mod_mainwp_sites', 'note']],
        ['version' => '0.3.0', 'sentence' => 'column tag added to mod_mainwp_sites', 'statements' => ['ALTER TABLE mod_mainwp_sites ADD COLUMN tag VARCHAR(32) NULL'], 'unless' => ['mod_mainwp_sites', 'tag']],
    ];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../modules/addons/mainwp/mainwp.php';
    }

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        Module::$repositoryFactory = fn (): MemoryRepository => $this->repo;
    }

    protected function tearDown(): void
    {
        Module::$repositoryFactory = null;
    }

    public function testStepsNewerThanTheInstalledVersionRunInOrder(): void
    {
        self::assertSame(['column note added to mod_mainwp_sites', 'column tag added to mod_mainwp_sites'], Upgrade::run($this->repo, '0.1.0', self::STEPS));
        self::assertTrue($this->repo->hasColumn('mod_mainwp_sites', 'tag'));
    }

    public function testAStepAtOrBelowTheInstalledVersionIsSkipped(): void
    {
        self::assertSame(['column tag added to mod_mainwp_sites'], Upgrade::run($this->repo, '0.2.0', self::STEPS));
        self::assertFalse($this->repo->hasColumn('mod_mainwp_sites', 'note'));
    }

    public function testARepeatedUpgradeAddsNothingTwice(): void
    {
        Upgrade::run($this->repo, '0.1.0', self::STEPS);
        self::assertSame([], Upgrade::run($this->repo, '0.1.0', self::STEPS), 'WHMCS repeats the call when an earlier one failed before the version row was written');
    }

    public function testAnInstalledVersionThatIsNotANumberChangesNothing(): void
    {
        $before = count($this->repo->executed);
        $this->expectException(\InvalidArgumentException::class);
        try {
            Upgrade::run($this->repo, 'unknown', self::STEPS);
        } finally {
            self::assertCount($before, $this->repo->executed);
        }
    }

    /** An install of 0.1.0: its sites table has no other_admins column yet (the memory repository reads the DROP). */
    private function installOf010(array $settings): void
    {
        $this->repo->execute('ALTER TABLE mod_mainwp_sites DROP COLUMN other_admins');
        $this->repo->settings = $settings;
    }

    public function testVersion020AddsTheOtherAdministratorsColumnAndGivesTheCareAccountEmailFromTheDomain(): void
    {
        $this->installOf010(['care_email_domain' => 'care.example.com', 'dashboard_url' => 'https://dash.example.com']);

        self::assertSame(['the setting Care Account Email Domain replaced by Care Account Email', 'column other_admins added to mod_mainwp_sites'], Upgrade::run($this->repo, '0.1.0'));

        self::assertSame(['dashboard_url' => 'https://dash.example.com', 'care_email' => 'wordpress@care.example.com'], $this->repo->settings);
        self::assertTrue($this->repo->hasColumn('mod_mainwp_sites', 'other_admins'));
        self::assertSame([], Upgrade::run($this->repo, '0.1.0'), 'safe to run twice: WHMCS repeats the call when an earlier one failed before the version row was written');
        self::assertSame(['dashboard_url' => 'https://dash.example.com', 'care_email' => 'wordpress@care.example.com'], $this->repo->settings);
    }

    public function testAnEmptyCareAccountEmailDomainIsDeletedAndGivesNoAddress(): void
    {
        $this->installOf010(['care_email_domain' => '', 'dry_run' => 'on']);

        self::assertContains('the setting Care Account Email Domain replaced by Care Account Email', Upgrade::run($this->repo, '0.1.0'));

        self::assertSame(['dry_run' => 'on'], $this->repo->settings);
    }

    public function testAnInstallWithoutTheDomainSettingHasNoSettingToMove(): void
    {
        $this->installOf010(['dry_run' => 'on']);

        self::assertSame(['column other_admins added to mod_mainwp_sites'], Upgrade::run($this->repo, '0.1.0'));
        self::assertSame(['dry_run' => 'on'], $this->repo->settings);
    }

    public function testACareAccountEmailAlreadySavedIsKept(): void
    {
        $this->installOf010(['care_email_domain' => 'care.example.com', 'care_email' => 'care@example.com']);

        Upgrade::run($this->repo, '0.1.0');

        self::assertSame(['care_email' => 'care@example.com'], $this->repo->settings, 'what staff saved with the new files is not replaced; the old row goes');
    }

    public function testTheModuleIsAtVersion060(): void
    {
        self::assertSame('0.6.0', Module::VERSION);
        self::assertSame(['0.2.0'], array_values(array_unique(array_column(Schema::upgradeSteps(), 'version'))));
        self::assertSame([], Upgrade::run($this->repo, '0.2.0'), 'no schema change since 0.2.0');
        self::assertSame([], Upgrade::run($this->repo, '0.3.0'), 'none for 0.4.0 either: the Daily Digest reads the old tick box\'s value');
        self::assertSame([], Upgrade::run($this->repo, '0.4.0'), 'none for 0.4.1: files only');
        self::assertSame([], Upgrade::run($this->repo, '0.4.1'), 'none for 0.4.2: files only');
        self::assertSame([], Upgrade::run($this->repo, '0.4.2'), 'none for 0.5.0: files only');
        self::assertSame([], Upgrade::run($this->repo, '0.5.0'), 'none for 0.6.0: files only');
    }

    public function testTheModuleFunctionUpgradesAnInstallOf010(): void
    {
        $this->installOf010(['care_email_domain' => 'care.example.com']);

        $result = \mainwp_upgrade(['version' => '0.1.0']);

        self::assertSame('success', $result['status']);
        self::assertSame('MainWP for WHMCS upgraded from 0.1.0 to 0.6.0. The setting Care Account Email Domain replaced by Care Account Email; column other_admins added to mod_mainwp_sites.', $result['description']);
        self::assertSame('wordpress@care.example.com', $this->repo->settings['care_email']);
    }

    public function testTheModuleFunctionReportsAndLogsTheUpgrade(): void
    {
        $result = \mainwp_upgrade(['version' => '0.0.9']);
        self::assertSame('success', $result['status']);
        self::assertSame('MainWP for WHMCS upgraded from 0.0.9 to ' . Module::VERSION . '. No tables changed.', $result['description']);
        self::assertSame(['MainWP for WHMCS upgraded from 0.0.9 to ' . Module::VERSION . ' (no schema change)'], WhmcsCalls::$activity);

        $result = \mainwp_upgrade([]);
        self::assertSame('error', $result['status']);
        self::assertStringContainsString('"unknown" is not a version number', $result['description']);
    }
}
