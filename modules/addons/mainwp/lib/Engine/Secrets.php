<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/** What the engine makes up for the key route and the care account. Abstract so that tests can name the values. */
abstract class Secrets
{
    /**
     * How every care account's login begins. It is the service's short name, not the module's: the
     * client reads it in the list of users on their own site.
     */
    public const CARE_LOGIN_PREFIX = 'wpcare';

    /** A one-time token for an approval's return address: 64 hexadecimal characters. */
    abstract public function token(): string;

    /** The login name of a new care account: the prefix and eight lower-case letters or digits, which a multisite network accepts too. */
    abstract public function careLogin(): string;

    /**
     * A care account's password: letters and digits in groups joined by hyphens. No quote and no
     * backslash (WordPress refuses a backslash, and MainWP strips slashes from what it is sent); the
     * hyphen because Wordfence refuses an administrator's password without a symbol (seen on a test bench).
     */
    abstract public function password(): string;

    /** Is this the login of a care account: the prefix followed by exactly eight lower-case letters or digits? */
    public static function isCareLogin(string $login): bool
    {
        return preg_match('/\A' . self::CARE_LOGIN_PREFIX . '[a-z0-9]{8}\z/', $login) === 1;
    }

    /**
     * The Care Account Email with the care login as its plus tag (wordpress+<care login>@example.com):
     * the address a care account is given when the site already has an account with the plain one.
     */
    public static function plusTagged(string $email, string $login): string
    {
        $at = strrpos($email, '@');
        return $at === false ? $email : substr($email, 0, $at) . '+' . $login . substr($email, $at);
    }
}
