<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\Approval;
use MainWpForWhmcs\Engine\CareSite;
use MainWpForWhmcs\Engine\Client;
use MainWpForWhmcs\Engine\HeldKey;
use MainWpForWhmcs\Engine\LogEntry;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\State;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\RepositoryStorage;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Settings;
use MainWpForWhmcs\Tests\Support\FixtureHttp;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

/** The engine as the WHMCS layer builds it: its storage is the module's tables. */
final class WiringTest extends TestCase
{
    private MemoryRepository $repo;

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        $this->repo->settings = ['dry_run' => 'on'];
        $this->repo->addonModules = [16 => 'mainwp'];
    }

    private function storage(): RepositoryStorage
    {
        return new RepositoryStorage($this->repo, new WhmcsEncrypter(), static fn (): string => '2026-11-09 14:00:00');
    }

    public function testACareSiteRoundTripsThroughTheSitesTable(): void
    {
        $site = new CareSite(68, 1176, 540, 'https://clientsite.example', State::Failed, 100, null, 2, null, 'captcha on the login page');
        $this->storage()->saveSite($site);

        self::assertEquals($site, $this->storage()->site(68));
        self::assertEquals([$site], $this->storage()->sites());
        self::assertSame('failed', $this->repo->careSites[68]['state']);
        self::assertNull($this->storage()->site(69));
    }

    public function testAStoredLoginIsEncryptedAndDeletedAgain(): void
    {
        $storage = $this->storage();
        $storage->saveSite(new CareSite(68, 1176, 540, 'https://clientsite.example', State::Provisioned, 100));
        $storage->saveLogin(68, new Login('siteadmin', 'Hunter2!'));

        $cipher = (string) $this->repo->careSites[68]['login_encrypted'];
        self::assertStringNotContainsString('Hunter2!', $cipher);
        self::assertStringNotContainsString('siteadmin', $cipher);
        self::assertSame('{"username":"siteadmin","password":"Hunter2!"}', (new WhmcsEncrypter())->decrypt($cipher));

        $storage->saveSite(new CareSite(68, 1176, 540, 'https://clientsite.example', State::Connected, 100, 101));
        self::assertSame($cipher, $this->repo->careSites[68]['login_encrypted'], 'saving the site leaves the login alone');

        self::assertEquals(new Login('siteadmin', 'Hunter2!'), $storage->login(68), 'only the module reads it back, for retries and reconnects');

        $storage->deleteLogin(68);
        self::assertNull($this->repo->careSites[68]['login_encrypted']);
        self::assertNull($storage->login(68));
    }

    public function testALoginThatCannotBeDecryptedIsNoLogin(): void
    {
        $this->storage()->saveSite(new CareSite(68, 1176, 540, 'https://clientsite.example', State::Failed, 100));
        $this->repo->careSites[68]['login_encrypted'] = 'not-whmcs-cipher';

        self::assertNull($this->storage()->login(68));
        self::assertNull($this->storage()->login(99));
    }

    public function testAnApprovalIsKeptByItsHashClaimedOnceAndItsKeyEncrypted(): void
    {
        $storage = $this->storage();
        $hash = str_repeat('ab', 32);
        $storage->saveApproval($hash, 68, '2026-11-10 14:00:00');

        self::assertEquals(new Approval($hash, 68, '2026-11-10 14:00:00', false, false), $storage->approval($hash));
        self::assertNull($storage->approval(str_repeat('cd', 32)));
        self::assertNull($storage->heldKey(68));

        $held = new HeldKey(new Login('owner', 'BenchKey0123456789ABCDEF'), new Login('wpcarek7m2x9ab', 'CarePass0123456789abcdefABCDEF01'));
        self::assertTrue($storage->claimApproval($hash, $held, '2026-11-09 14:05:00'));
        self::assertFalse($storage->claimApproval($hash, new HeldKey(new Login('other', 'OtherKey0123456789ABCDEF'), new Login('wpcare-zzzzzz', 'x')), '2026-11-09 14:05:01'), 'only the first return of a token wins');

        $row = $this->repo->approvals[0];
        self::assertSame('2026-11-09 14:05:00', $row['used_at']);
        foreach (['BenchKey0123456789ABCDEF', 'CarePass0123456789abcdefABCDEF01', 'owner', 'wpcarek7m2x9ab'] as $plain) {
            self::assertStringNotContainsString($plain, (string) $row['key_encrypted'], 'stored with WHMCS\'s encryption');
        }
        self::assertEquals($held, $storage->heldKey(68), 'only the module reads it back');
        self::assertEquals(new Approval($hash, 68, '2026-11-10 14:00:00', true, true, hash('sha256', 'BenchKey0123456789ABCDEF')), $storage->approval($hash));

        $storage->releaseKey(68);
        self::assertNull($storage->heldKey(68));
        self::assertNull($this->repo->approvals[0]['key_encrypted']);
        self::assertEquals(new Approval($hash, 68, '2026-11-10 14:00:00', true, false, hash('sha256', 'BenchKey0123456789ABCDEF')), $storage->approval($hash), 'a used token stays used, and the same key coming back is still recognised');
    }

    public function testALinkThatLapsedCannotBeClaimed(): void
    {
        $storage = $this->storage();
        $hash = str_repeat('ab', 32);
        $storage->saveApproval($hash, 68, '2026-11-10 14:00:00');
        $held = new HeldKey(new Login('owner', 'BenchKey0123456789ABCDEF'), new Login('wpcarek7m2x9ab', 'x'));

        self::assertFalse($storage->claimApproval($hash, $held, '2026-11-10 14:00:00'), 'at its last second it is over');
        self::assertFalse($storage->claimApproval($hash, $held, '2026-11-10 14:00:01'));
        self::assertNull($this->repo->approvals[0]['used_at']);
        self::assertNull($storage->heldKey(68));
        self::assertTrue($storage->claimApproval($hash, $held, '2026-11-10 13:59:59'));
    }

    public function testAHeldKeyThatCannotBeDecryptedIsNoKey(): void
    {
        $storage = $this->storage();
        $storage->saveApproval(str_repeat('ab', 32), 68, '2026-11-10 14:00:00');
        $this->repo->approvals[0]['used_at'] = '2026-11-09 14:05:00';
        $this->repo->approvals[0]['key_encrypted'] = 'not-whmcs-cipher';

        self::assertNull($storage->heldKey(68));
    }

    public function testApprovalsLapseAndArePurgedButNeverWhileTheyHoldAKey(): void
    {
        $storage = $this->storage();
        $storage->saveApproval(str_repeat('a1', 32), 68, '2026-11-10 14:00:00');
        $storage->saveApproval(str_repeat('b2', 32), 68, '2026-11-10 15:00:00');
        $storage->saveApproval(str_repeat('c3', 32), 69, '2026-11-10 16:00:00');
        self::assertSame(str_repeat('b2', 32), $storage->latestApproval(68)?->tokenHash);
        self::assertNull($storage->latestApproval(70));

        $storage->lapseApprovals(68, '2026-11-09 16:00:00');

        self::assertSame(['2026-11-09 16:00:00', '2026-11-09 16:00:00', '2026-11-10 16:00:00'], array_column($this->repo->approvals, 'expires_at'), 'the add-on\'s unused links stop working; the rows stay');
        self::assertTrue($storage->approval(str_repeat('a1', 32))?->lapsed('2026-11-09 16:00:00'));

        $storage->claimApproval(str_repeat('c3', 32), new HeldKey(new Login('owner', 'BenchKey0123456789ABCDEF'), new Login('wpcarek7m2x9ab', 'x')), '2026-11-09 16:30:00');
        $storage->purgeApprovals('2026-11-20 00:00:00');

        self::assertSame([str_repeat('c3', 32)], array_column($this->repo->approvals, 'token_hash'), 'a row that holds a key is kept until the key is released');
    }

    public function testAnAddonIsLockedForOneRequestAtATime(): void
    {
        $storage = $this->storage();

        self::assertTrue($storage->lock(68));
        self::assertSame(['addon:68'], $this->repo->locks);
        $storage->unlock(68);
        self::assertSame([], $this->repo->locks);

        $this->repo->lockedElsewhere = ['addon:68'];
        self::assertFalse($storage->lock(68), 'another request is working on it');
    }

    public function testAWhmcsClientIsLockedForOneProvisionAtATime(): void
    {
        $storage = $this->storage();

        self::assertTrue($storage->lockClient(540, 10));
        self::assertSame(['client:540'], $this->repo->locks, 'its own name, apart from any add-on row with the same number');
        $storage->unlockClient(540);
        self::assertSame([], $this->repo->locks);

        $this->repo->lockedElsewhere = ['client:540'];
        self::assertFalse($storage->lockClient(540, 10), 'another request is provisioning for the client');
        self::assertTrue($storage->lock(540), 'the add-on row 540 is another lock');
    }

    public function testALogEntryBecomesALogRow(): void
    {
        $this->storage()->log(new LogEntry('2026-11-09 14:00:00', 'admin:1', 'provision', 'add-on 68', 'would run', true, 'would build'));
        self::assertSame([['created_at' => '2026-11-09 14:00:00', 'actor' => 'admin:1', 'action' => 'provision', 'target' => 'add-on 68', 'outcome' => 'would run', 'dry_run' => 1, 'detail' => 'would build']], $this->repo->log);
    }

    public function testTheModuleEngineInDryRunLogsToTheLogTableAndSendsNothing(): void
    {
        $engine = Module::engine($this->repo, Settings::load($this->repo));

        $outcome = $engine->provision(68, 1176, new Client(540, 'Demo Client', 'demo@example.com'), 'https://clientsite.example', 'admin:1');
        self::assertTrue($outcome->ok);
        self::assertSame([], $this->repo->careSites);

        $this->storage()->saveSite(new CareSite(69, 1176, 540, 'https://clientsite.example/shop', State::Provisioned, 100));
        self::assertTrue($engine->connect(69, new Login('siteadmin', 'Hunter2!'), 'admin:1')->ok);
        self::assertNull($this->repo->careSites[69]['login_encrypted'] ?? null, 'a dry run stores no login');
        self::assertSame([['provision', 'would run', 1], ['connect', 'would run', 1]], array_map(static fn (array $r): array => [$r['action'], $r['outcome'], $r['dry_run']], $this->repo->log));
        self::assertStringNotContainsString('Hunter2!', json_encode($this->repo->log, JSON_THROW_ON_ERROR));
    }

    public function testInLiveModeWithoutAddressAndKeyNothingIsSentAndTheReasonSaysWhy(): void
    {
        $this->repo->settings['dry_run'] = '';
        Module::$httpFactory = static fn (): FixtureHttp => new FixtureHttp();
        $outcome = Module::engine($this->repo, Settings::load($this->repo))->provision(68, 1176, new Client(540, 'Demo Client', 'demo@example.com'), 'https://clientsite.example', 'admin:1');
        self::assertFalse($outcome->ok);
        self::assertSame('Add-on 68 could not be provisioned: The dashboard address and API key are not both set; enter them in the module\'s settings.', $outcome->message);
        self::assertSame([], $this->repo->careSites);
    }

    public function testInLiveModeTheEngineProvisionsThroughMainWpsApi(): void
    {
        $this->repo->settings = ['dry_run' => '', 'dashboard_url' => 'https://dash.example.com', 'api_key' => (new WhmcsEncrypter())->encrypt('bench-key-0123456789')];
        $this->repo->addonModules = [16 => 'mainwp'];
        $http = (new FixtureHttp())->on('GET', 'clients?page=1&per_page=100', 'clients-list-empty')->on('POST', 'clients/add', 'clients-add');
        Module::$httpFactory = static fn (): FixtureHttp => $http;

        $outcome = Module::engine($this->repo, Settings::load($this->repo))->provision(68, 1176, new Client(540, 'Demo Client', 'demo-client@example.com'), 'https://clientsite.example', 'admin:1');

        self::assertTrue($outcome->ok, $outcome->message);
        self::assertSame(['name' => 'Demo Client', 'client_email' => 'demo-client@example.com', 'note' => 'WHMCS client 540'], $http->sent[1]['form']);
        self::assertSame(1, $this->repo->careSites[68]['dashboard_client_id']);
        self::assertSame([
            ['dashboard: find client', 'billing client 540', 'done'],
            ['dashboard: create client', 'billing client 540', 'done'],
            ['provision', 'add-on 68', 'done'],
        ], array_map(static fn (array $r): array => [$r['action'], $r['target'], $r['outcome']], $this->repo->log));
        self::assertStringNotContainsString('bench-key-0123456789', json_encode($this->repo->log, JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        Module::$httpFactory = null;
    }
}
