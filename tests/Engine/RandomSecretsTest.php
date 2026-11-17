<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\RandomSecrets;
use MainWpForWhmcs\Engine\Secrets;

require_once __DIR__ . '/Fakes.php';

/** What the engine generates for the key route: the one-time token, the care account's login and its password */
final class RandomSecretsTest extends TestCase
{
    public function testATokenIs64HexCharactersAndNeverTheSameTwice(): void
    {
        $secrets = new RandomSecrets();

        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $token = $secrets->token();
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
            $tokens[$token] = true;
        }

        self::assertCount(50, $tokens);
    }

    public function testACareLoginBeginsWithWpcareAndHasOnlyLowerCaseLettersAndDigits(): void
    {
        $secrets = new RandomSecrets();

        $logins = [];
        for ($i = 0; $i < 50; $i++) {
            $login = $secrets->careLogin();
            self::assertMatchesRegularExpression('/^wpcare[a-z0-9]{8}$/', $login, 'no hyphen: a multisite network accepts only lower-case letters and digits');
            self::assertTrue(Secrets::isCareLogin($login));
            $logins[$login] = true;
        }

        self::assertCount(50, $logins, 'the eight characters are random');
    }

    public function testAPasswordIsLettersAndDigitsInGroupsJoinedByHyphens(): void
    {
        $secrets = new RandomSecrets();

        $passwords = [];
        for ($i = 0; $i < 200; $i++) {
            $password = $secrets->password();
            self::assertMatchesRegularExpression('/^[A-Za-z0-9]{8}(-[A-Za-z0-9]{8}){3}$/', $password, 'no quote or backslash: WordPress refuses a backslash and MainWP strips slashes');
            self::assertMatchesRegularExpression('/[A-Z]/', $password, 'Wordfence asks an administrator for capitals, small letters, a digit and a symbol (bench, fifth run)');
            self::assertMatchesRegularExpression('/[a-z]/', $password);
            self::assertMatchesRegularExpression('/[0-9]/', $password);
            self::assertSame($password, stripslashes($password));
            self::assertSame($password, rawurldecode($password), 'nothing in it changes when MainWP decodes it once more');
            $passwords[$password] = true;
        }

        self::assertCount(200, $passwords);
    }

    public function testACareLoginIsThePrefixAndExactlyEightLowerCaseLettersOrDigits(): void
    {
        self::assertTrue(Secrets::isCareLogin('wpcareetec6urx'), 'the care account the bench made through MainWP (eighth run)');
        self::assertTrue(Secrets::isCareLogin('wpcare00000000'));
        self::assertTrue(Secrets::isCareLogin(FixedSecrets::CARE_LOGIN), 'the tests\' care login is one');
        foreach (['owner', 'wpcare', 'wpcareetec6ur', 'wpcareetec6urxx', 'wpcare-tec6urx', 'wpcareEtec6urx', 'WPCAREetec6urx', ' wpcareetec6urx', 'wpcareetec6urx ', "wpcareetec6urx\n", 'xwpcareetec6urx', 'wpcare-k7m2x9', 'wpcareétec6ur'] as $other) {
            self::assertFalse(Secrets::isCareLogin($other), var_export($other, true));
        }
    }

    public function testAPlusTaggedAddressCarriesTheCareLoginBeforeTheAt(): void
    {
        self::assertSame('wordpress+wpcareab12cd34@example.com', Secrets::plusTagged('wordpress@example.com', 'wpcareab12cd34'));
        self::assertSame('care+wpcareab12cd34@mail.example.co.za', Secrets::plusTagged('care@mail.example.co.za', 'wpcareab12cd34'));
    }
}
