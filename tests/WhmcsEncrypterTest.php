<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\RepositoryStorage;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

/**
 * WHMCS's encryption through its API commands, as WHMCS behaves:
 * a value passes WHMCS's input filter before it is encrypted, so " & ' < > come back as HTML entities.
 * A login is stored as JSON, which is full of quotes: none of the eight logins stored that evening could
 * be read back, and a retry found "no stored login".
 */
final class WhmcsEncrypterTest extends TestCase
{
    private const AWKWARD = '{"username":"wpcareab12cd34","password":"Aa1!@#$%^&*()\'<x>&amp;é "}';

    protected function setUp(): void
    {
        WhmcsCalls::reset();
    }

    public function testWhatIsEncryptedComesBackAsItWasGiven(): void
    {
        $encrypter = new WhmcsEncrypter();

        self::assertSame(self::AWKWARD, $encrypter->decrypt($encrypter->encrypt(self::AWKWARD)));
        self::assertSame('', $encrypter->decrypt($encrypter->encrypt('')));
        self::assertSame("two\nlines\tand a tab", $encrypter->decrypt($encrypter->encrypt("two\nlines\tand a tab")));
    }

    public function testTheValueIsHandedToWhmcsInAFormItsInputFilterLeavesAlone(): void
    {
        (new WhmcsEncrypter())->encrypt(self::AWKWARD);

        $given = (string) WhmcsCalls::$api[0]['values']['password2'];
        self::assertSame('EncryptPassword', WhmcsCalls::$api[0]['command']);
        self::assertMatchesRegularExpression('~\Ab64:[A-Za-z0-9+/]+=*\z~', $given);
        self::assertStringNotContainsString('wpcareab12cd34', $given);
    }

    public function testAValueStoredBeforeThisIsStillRead(): void
    {
        // As 0.2.0 stored it: the plain value handed to WHMCS, which encoded it on the way in.
        $stored = (string) \localAPI('EncryptPassword', ['password2' => '{"username":"wpcareab12cd34","password":"Aa1!@#$%^&*()"}'])['password'];

        self::assertSame('{"username":"wpcareab12cd34","password":"Aa1!@#$%^&*()"}', (new WhmcsEncrypter())->decrypt($stored));
    }

    public function testAnApiKeyStoredBeforeThisIsStillRead(): void
    {
        $stored = (string) \localAPI('EncryptPassword', ['password2' => 'ck_0123456789abcdefABCDEF+/=='])['password'];

        self::assertSame('ck_0123456789abcdefABCDEF+/==', (new WhmcsEncrypter())->decrypt($stored));
    }

    public function testAWrappedValueThatIsDamagedIsNotReadAsSomethingElse(): void
    {
        $damaged = (string) \localAPI('EncryptPassword', ['password2' => 'b64:not base64 at all!'])['password'];

        $this->expectException(\RuntimeException::class);
        (new WhmcsEncrypter())->decrypt($damaged);
    }

    public function testAStoredLoginIsReadBackThroughWhmcs(): void
    {
        $repo = new MemoryRepository();
        $repo->saveCareSite(['addon_row_id' => 68, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example', 'state' => 'failed']);
        $storage = new RepositoryStorage($repo, new WhmcsEncrypter(), static fn (): string => '2026-11-11 21:13:41');

        $storage->saveLogin(68, new Login('wpcareab12cd34', 'Aa1!@#$%^&*()"\'<>'));

        $login = $storage->login(68);
        self::assertNotNull($login, 'the retry of 11 November 2026 found "no stored login" here');
        self::assertSame('wpcareab12cd34', $login->username);
        self::assertSame('Aa1!@#$%^&*()"\'<>', $login->password);
    }
}
