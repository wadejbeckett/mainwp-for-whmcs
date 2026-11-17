<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

/**
 * WHMCS's own encryption, through its documented internal API commands EncryptPassword and
 * DecryptPassword (keyed by the installation's cc_encryption_hash).
 *
 * WHMCS passes a value given to its API through its input filter before it encrypts it: " & ' < >
 * become HTML entities (seen on WHMCS 9.1, 11 November 2026), so what is decrypted is not what was
 * given. A stored login is JSON, which is full of quotes, and could not be read back at all. A value
 * is therefore handed over as base64 text, which that filter leaves alone, behind a marker. A value
 * stored before this (up to the first build of 0.2.0) comes back with the entities, which are decoded.
 */
final class WhmcsEncrypter implements Encrypter
{
    /** Marks a value that was wrapped as base64 before WHMCS encrypted it. */
    private const WRAPPED = 'b64:';

    public function encrypt(#[\SensitiveParameter] string $plain): string
    {
        $result = \localAPI('EncryptPassword', ['password2' => self::WRAPPED . base64_encode($plain)]);
        if (($result['result'] ?? '') !== 'success' || !is_string($result['password'] ?? null)) {
            throw new \RuntimeException('WHMCS could not encrypt the value.');
        }
        return $result['password'];
    }

    public function decrypt(string $cipher): string
    {
        $result = \localAPI('DecryptPassword', ['password2' => $cipher]);
        if (($result['result'] ?? '') !== 'success' || !is_string($result['password'] ?? null)) {
            throw new \RuntimeException('WHMCS could not decrypt the value.');
        }
        $value = $result['password'];
        if (str_starts_with($value, self::WRAPPED)) {
            $plain = base64_decode(substr($value, strlen(self::WRAPPED)), true);
            if ($plain === false) {
                throw new \RuntimeException('WHMCS could not decrypt the value.');
            }
            return $plain;
        }
        // Stored before the value was wrapped: WHMCS had encoded these five characters on the way in.
        return htmlspecialchars_decode($value, ENT_QUOTES | ENT_HTML401);
    }
}
