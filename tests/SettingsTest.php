<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Settings;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

/** The settings on the module's page, saved where WHMCS keeps add-on module settings. */
final class SettingsTest extends TestCase
{
    /** The form MainWP gives a key: the secret, "==", the key, 40 hexadecimal digits each. */
    private const KEY = '9f8e7d6c5b4a32109f8e7d6c5b4a32109f8e7d6c==0a1b2c3d4e5f67890a1b2c3d4e5f67890a1b2c3d';

    private MemoryRepository $repo;

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        $this->repo->settings = ['dry_run' => 'on', 'access' => '1'];
        $this->repo->addonModules = [16 => 'mainwp'];
    }

    /** @return array<string, string> */
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'dashboard_url' => 'https://dash.example.com/',
            'api_key' => self::KEY,
            'report_by_email' => 'daily',
            'mode' => 'dry_run',
            'care_email' => 'WordPress@Care.Example.com',
        ];
    }

    public function testSettingsAreSavedAndReadBack(): void
    {
        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input()));

        $settings = Settings::load($this->repo);
        self::assertSame(['https://dash.example.com', Settings::DIGEST_DAILY, true, [16], 'wordpress@care.example.com'], [$settings->dashboardUrl, $settings->dailyDigest, $settings->dryRun, $settings->careAddonIds, $settings->careEmail]);
        self::assertSame('1', $this->repo->settings['access'], 'WHMCS\'s own rows are left alone');
    }

    public function testTheApiKeyIsStoredEncryptedByWhmcsAndReadBackWhole(): void
    {
        Settings::save($this->repo, new WhmcsEncrypter(), $this->input());

        $stored = $this->repo->settings['api_key'];
        self::assertStringNotContainsString(self::KEY, $stored);
        self::assertSame('EncryptPassword', WhmcsCalls::$api[0]['command']);
        $settings = Settings::load($this->repo);
        self::assertTrue($settings->hasApiKey());
        self::assertSame(self::KEY, $settings->apiKey(new WhmcsEncrypter()));
    }

    public function testABlankKeyFieldKeepsTheStoredKey(): void
    {
        Settings::save($this->repo, new WhmcsEncrypter(), $this->input());
        $stored = $this->repo->settings['api_key'];

        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['api_key' => '', 'mode' => 'live'])));
        self::assertSame($stored, $this->repo->settings['api_key']);
        self::assertFalse(Settings::load($this->repo)->dryRun);
        self::assertSame('', $this->repo->settings['dry_run']);
    }

    public function testAPastedKeyIsStoredWithoutTheWhiteSpaceAroundIt(): void
    {
        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['api_key' => "  " . self::KEY . "\n"])));
        self::assertSame(self::KEY, Settings::load($this->repo)->apiKey(new WhmcsEncrypter()));
    }

    /** @return array<string, array{string}> */
    public static function notKeys(): array
    {
        $half = str_repeat('a1', 20);
        return [
            'the address of the dashboard\'s page of keys' => ['https://dash.example.com/wp-admin/admin.php?page=RESTAPI'],
            'one half of a key' => [$half],
            'a key cut off after its "=="' => [$half . '=='],
            'a key without its first half' => ['==' . $half],
            'a consumer key of MainWP\'s older API' => ['ck_' . $half],
            'a key with a space pasted into it' => [$half . '== ' . $half],
            'a key with a line break in it' => [$half . "==\n" . $half],
            'two keys run together' => [$half . '==' . $half . '==' . $half],
            'a key joined by one "="' => [$half . '=' . $half],
            'a password' => ['Tr0ub4dor&3=horse'],
        ];
    }

    /**
     * The address of the dashboard's page may be pasted for the key:
     * the field hides what is typed, so the form has to say that it is not a key.
     *
     * @dataProvider notKeys
     */
    public function testWhatIsNotAKeyIsRefusedAndTheStoredKeyStays(string $entered): void
    {
        Settings::save($this->repo, new WhmcsEncrypter(), $this->input());
        $before = $this->repo->settings;
        WhmcsCalls::$api = [];

        $errors = Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['api_key' => $entered, 'mode' => 'live', 'dashboard_url' => 'https://other.example.com']));

        self::assertSame(['API Key: this is not a MainWP API key. On the dashboard, copy it with the button beside "API key (Bearer token)".'], $errors);
        self::assertSame($before, $this->repo->settings, 'nothing of the form is saved, and the key that worked is kept');
        self::assertSame([], WhmcsCalls::$api, 'a refused form encrypts nothing');
        self::assertSame(self::KEY, Settings::load($this->repo)->apiKey(new WhmcsEncrypter()));
    }

    public function testTheKeyIsNamedAfterTheDashboardAddressAmongTheProblems(): void
    {
        $errors = Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['dashboard_url' => 'http://dash.example.com', 'api_key' => 'not-a-key', 'mode' => 'maybe']));
        self::assertSame(['MainWP Dashboard URL:', 'API Key:', 'Mode:'], array_map(static fn (string $e): string => substr($e, 0, (int) strpos($e, ':') + 1), $errors));
    }

    /** The stored key is for the stored address: it is never sent to another host on its own */
    public function testANewDashboardHostWithoutANewKeyIsRefusedAndNothingIsSaved(): void
    {
        Settings::save($this->repo, new WhmcsEncrypter(), $this->input());
        $before = $this->repo->settings;

        foreach (['https://other.example.com', 'https://dash.example.com:8443', 'https://dash.example.com.evil.example'] as $address) {
            self::assertSame(['Enter the API key again for the new address.'], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['dashboard_url' => $address, 'api_key' => ''])), $address);
            self::assertSame($before, $this->repo->settings, $address);
        }
    }

    public function testTheSameHostWrittenOtherwiseKeepsTheStoredKey(): void
    {
        Settings::save($this->repo, new WhmcsEncrypter(), $this->input());
        $stored = $this->repo->settings['api_key'];

        foreach (['https://DASH.Example.com/', 'https://dash.example.com', 'https://dash.example.com:443', 'https://dash.example.com/mainwp'] as $address) {
            self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['dashboard_url' => $address, 'api_key' => ''])), $address);
            self::assertSame($stored, $this->repo->settings['api_key']);
        }
    }

    public function testANewHostWithANewKeySavesBoth(): void
    {
        Settings::save($this->repo, new WhmcsEncrypter(), $this->input());
        $typed = str_repeat('1', 40) . '==' . str_repeat('2', 40);

        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['dashboard_url' => 'https://other.example.com', 'api_key' => $typed])));
        $settings = Settings::load($this->repo);
        self::assertSame(['https://other.example.com', $typed], [$settings->dashboardUrl, $settings->apiKey(new WhmcsEncrypter())]);
    }

    public function testLiveIsRefusedWhileNoApiKeyIsStoredOrTyped(): void
    {
        $before = $this->repo->settings;

        self::assertSame(['Mode: Live needs an API key; enter it, or choose Dry Run.'], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['api_key' => '', 'mode' => 'live'])));
        self::assertSame($before, $this->repo->settings);
        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['api_key' => '', 'mode' => 'dry_run'])), 'dry run needs no key');
        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['mode' => 'live'])), 'a key typed with it is enough');
    }

    public function testLiveNeedsNoCareAddOn(): void
    {
        $this->repo->addonModules = [];
        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['mode' => 'live'])));
        self::assertSame([[], ''], [Settings::load($this->repo)->careAddonIds, $this->repo->settings['dry_run']]);
    }

    public function testTheCareAddOnsAreTheProductAddOnsWhoseModuleSettingsNameTheModule(): void
    {
        $this->repo->addonModules = [21 => 'mainwp', 3 => '', 16 => 'mainwp', 5 => 'cpanel', 7 => 'MainWP'];
        self::assertSame([16, 21], Settings::load($this->repo)->careAddonIds, 'in ascending order; an empty module, another module\'s name or another spelling is not care');

        $this->repo->settings['care_addon_ids'] = '3,5,7';
        self::assertSame([16, 21], Settings::load($this->repo)->careAddonIds, 'whatever the old stored list says');
    }

    public function testTheOldStoredListIsNotRead(): void
    {
        $this->repo->settings['care_addon_ids'] = '16';
        $this->repo->addonModules = [7 => 'mainwp', 16 => ''];
        self::assertSame([7], Settings::load($this->repo)->careAddonIds);
    }

    public function testTheLiveInstallKeepsItsCareAddOn(): void
    {
        // An install whose old stored list is "16" and whose product add-on 16 names the module.
        $this->repo->settings['care_addon_ids'] = '16';
        $this->repo->addonModules = [16 => 'mainwp', 3 => '', 21 => ''];
        self::assertSame([16], Settings::load($this->repo)->careAddonIds);
    }

    public function testASaveNeitherNeedsNorStoresAListAndLeavesAStoredOneAlone(): void
    {
        $this->repo->settings['care_addon_ids'] = '16';
        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['care_addon_ids' => ['3', 'x']])), 'a list sent with the form is ignored');
        self::assertSame('16', $this->repo->settings['care_addon_ids'], 'the old row is left untouched');
        self::assertSame([16], Settings::load($this->repo)->careAddonIds);

        unset($this->repo->settings['care_addon_ids']);
        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input()));
        self::assertArrayNotHasKey('care_addon_ids', $this->repo->settings);
    }

    public function testFreshInstallDefaults(): void
    {
        $settings = Settings::load(new MemoryRepository());
        self::assertSame(['', false, true, []], [$settings->dashboardUrl, $settings->hasApiKey(), $settings->dryRun, $settings->careAddonIds]);
    }

    public function testTheDailyDigestIsOffWhenSomethingIsNewOrEveryDayAndStartsOnWhenSomethingIsNew(): void
    {
        self::assertSame(Settings::DIGEST_NEW, Settings::load(new MemoryRepository())->dailyDigest, 'a fresh install');

        foreach (['off', 'new', 'daily'] as $choice) {
            self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['report_by_email' => $choice])));
            self::assertSame($choice, $this->repo->settings['report_by_email']);
            self::assertSame($choice, Settings::load($this->repo)->dailyDigest);
        }
    }

    public function testTheOldTickBoxsStoredValuesReadSoThatNobodysEmailChanges(): void
    {
        $this->repo->settings['report_by_email'] = 'on';
        self::assertSame(Settings::DIGEST_NEW, Settings::load($this->repo)->dailyDigest, 'ticked');
        $this->repo->settings['report_by_email'] = '';
        self::assertSame(Settings::DIGEST_OFF, Settings::load($this->repo)->dailyDigest, 'unticked');
        unset($this->repo->settings['report_by_email']);
        self::assertSame(Settings::DIGEST_NEW, Settings::load($this->repo)->dailyDigest, 'never saved: the tick box started ticked');
    }

    public function testAnUnknownDailyDigestIsRefusedAndAFormWithoutItKeepsTheStoredOne(): void
    {
        $this->repo->settings['report_by_email'] = 'daily';
        self::assertSame(['Daily Digest: choose Off, When something is new or Every day.'], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['report_by_email' => 'weekly'])));
        self::assertSame('daily', $this->repo->settings['report_by_email']);

        $input = $this->input();
        unset($input['report_by_email']);
        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $input));
        self::assertSame(Settings::DIGEST_DAILY, Settings::load($this->repo)->dailyDigest);
    }

    public function testAFieldTheFormDidNotShowKeepsItsValue(): void
    {
        Settings::save($this->repo, new WhmcsEncrypter(), $this->input());
        Settings::saveLogRetention($this->repo, '6');

        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['mode' => 'live'])));

        $settings = Settings::load($this->repo);
        self::assertSame([6, false], [$settings->logRetentionMonths, $settings->dryRun], 'the log\'s retention is the Log tab\'s');
    }

    public function testATestClientIdStoredByAnEarlierVersionIsLeftWhereItIsAndNotRead(): void
    {
        $this->repo->settings['test_client_id'] = '540';

        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['test_client_id' => '999'])), 'a field sent with the form is ignored');

        self::assertSame('540', $this->repo->settings['test_client_id']);
        self::assertFalse(property_exists(Settings::class, 'testClientId'), 'no setting reads it');
    }

    public function testLiveIsRefusedWithoutACareAccountEmail(): void
    {
        $before = $this->repo->settings;

        self::assertSame(['Mode: Live needs a Care Account Email; enter it, or choose Dry Run.'], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['mode' => 'live', 'care_email' => ''])));
        self::assertSame($before, $this->repo->settings, 'nothing is saved');
        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['mode' => 'dry_run', 'care_email' => ''])), 'dry run needs none');
        self::assertSame([
            'Mode: Live needs an API key; enter it, or choose Dry Run.',
            'Mode: Live needs a Care Account Email; enter it, or choose Dry Run.',
        ], Settings::save(new MemoryRepository(), new WhmcsEncrypter(), $this->input(['mode' => 'live', 'api_key' => '', 'care_email' => ''])), 'both are said');
    }

    public function testLiveIsRefusedWithoutADashboardAddress(): void
    {
        $before = $this->repo->settings;

        self::assertSame(['Mode: Live needs the MainWP Dashboard URL; enter it, or choose Dry Run.'], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['mode' => 'live', 'dashboard_url' => ''])));
        self::assertSame($before, $this->repo->settings, 'nothing is saved');
        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['mode' => 'dry_run', 'dashboard_url' => ''])), 'dry run needs none');
    }

    public function testAnInstallAlreadyLiveWithoutACareAccountEmailCanStillSaveItsOtherSettings(): void
    {
        // Live since before version 0.6.0, with no Care Account Email: its other settings can be saved (and the
        // connection tested, which follows a save) without the email being forced first.
        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input()));
        $this->repo->settings['dry_run'] = '';
        $this->repo->settings['care_email'] = '';

        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['mode' => 'live', 'care_email' => '', 'api_key' => '', 'report_by_email' => 'off'])));

        self::assertFalse(Settings::load($this->repo)->dryRun);
        self::assertSame(Settings::DIGEST_OFF, Settings::load($this->repo)->dailyDigest);
    }

    public function testAnInstallAlreadyLiveWithoutACareAccountEmailKeepsRunning(): void
    {
        $this->repo->settings = ['dry_run' => '', 'care_email' => '', 'access' => '1'];
        self::assertFalse(Settings::load($this->repo)->dryRun, 'only a save is refused');
    }

    public function testTheLogIsKeptForAWholeNumberOfMonthsAndForEverAtNought(): void
    {
        self::assertSame(0, Settings::load($this->repo)->logRetentionMonths, 'nothing is deleted until a number is entered');
        foreach (['12' => 12, '0' => 0, '' => 0, ' 600 ' => 600] as $typed => $months) {
            self::assertNull(Settings::saveLogRetention($this->repo, (string) $typed), 'typed ' . $typed);
            self::assertSame($months, Settings::load($this->repo)->logRetentionMonths);
        }
        foreach (['-1', '1.5', 'six', '601', '0012', '1e2', ['12'], null] as $typed) {
            self::assertSame('Log Retention: enter a whole number of months. 0 keeps every entry.', Settings::saveLogRetention($this->repo, $typed), 'typed ' . json_encode($typed, JSON_THROW_ON_ERROR));
        }
        self::assertSame(600, Settings::load($this->repo)->logRetentionMonths, 'a refused number changes nothing');
        self::assertSame('1', $this->repo->settings['access'], 'and no other setting is touched');
        $this->repo->settings['log_retention_months'] = 'many';
        self::assertSame(0, Settings::load($this->repo)->logRetentionMonths, 'a stored value that is no number keeps everything');

        // The settings form does not carry it: a save there leaves it as it is.
        Settings::saveLogRetention($this->repo, '9');
        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['log_retention_months' => '3'])));
        self::assertSame(9, Settings::load($this->repo)->logRetentionMonths);
    }

    public function testInvalidInputIsRefusedAndNothingIsSaved(): void
    {
        $before = $this->repo->settings;
        $errors = Settings::save($this->repo, new WhmcsEncrypter(), $this->input([
            'dashboard_url' => 'http://dash.example.com',
            'mode' => 'maybe',
            'care_email' => 'care.example.com',
        ]));
        self::assertSame([
            'MainWP Dashboard URL: enter the dashboard\'s https:// address.',
            'Mode: choose Dry Run or Live.',
            'Care Account Email: enter an email address such as wordpress@example.com.',
        ], $errors);
        self::assertSame($before, $this->repo->settings);
        self::assertSame([], WhmcsCalls::$api, 'a refused form encrypts nothing');
    }

    public function testTheCareAccountEmailIsOneAddressStoredLowerCasedOrNothing(): void
    {
        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['care_email' => '  WordPress+Care@Example.COM '])));
        self::assertSame('wordpress+care@example.com', $this->repo->settings['care_email']);
        self::assertSame('wordpress+care@example.com', Settings::load($this->repo)->careEmail);

        self::assertSame([], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['care_email' => ''])), 'empty is allowed: the key route is then not offered');
        self::assertSame('', Settings::load($this->repo)->careEmail);

        foreach (['example.com', '@example.com', 'wordpress@', 'word press@example.com', 'wordpress@example.com, other@example.com'] as $notAnAddress) {
            self::assertSame(['Care Account Email: enter an email address such as wordpress@example.com.'], Settings::save($this->repo, new WhmcsEncrypter(), $this->input(['care_email' => $notAnAddress])), $notAnAddress);
        }
        self::assertSame('', $this->repo->settings['care_email']);
        self::assertArrayNotHasKey('care_email_domain', $this->repo->settings, 'the setting it replaced is never written');
    }

    public function testAKeyThatCannotBeDecryptedReadsAsNoKey(): void
    {
        $this->repo->settings['api_key'] = 'garbage';
        $settings = Settings::load($this->repo);
        self::assertTrue($settings->hasApiKey());
        $this->expectExceptionMessage('The stored API key could not be decrypted; enter it again.');
        $settings->apiKey(new WhmcsEncrypter());
    }
}
