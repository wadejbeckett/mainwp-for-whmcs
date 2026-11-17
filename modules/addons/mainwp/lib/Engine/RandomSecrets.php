<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/** Secrets from PHP's cryptographic random source. */
final class RandomSecrets extends Secrets
{
    private const LOWER = 'abcdefghijklmnopqrstuvwxyz0123456789';
    private const MIXED = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

    public function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function careLogin(): string
    {
        return self::CARE_LOGIN_PREFIX . self::pick(self::LOWER, 8);
    }

    /** Four groups of eight letters and digits; drawn again until a capital, a small letter and a digit are all there. */
    public function password(): string
    {
        do {
            $password = implode('-', [self::pick(self::MIXED, 8), self::pick(self::MIXED, 8), self::pick(self::MIXED, 8), self::pick(self::MIXED, 8)]);
        } while (preg_match('/[A-Z]/', $password) !== 1 || preg_match('/[a-z]/', $password) !== 1 || preg_match('/[0-9]/', $password) !== 1);
        return $password;
    }

    private static function pick(string $alphabet, int $length): string
    {
        $last = strlen($alphabet) - 1;
        $picked = '';
        for ($i = 0; $i < $length; $i++) {
            $picked .= $alphabet[random_int(0, $last)];
        }
        return $picked;
    }
}
