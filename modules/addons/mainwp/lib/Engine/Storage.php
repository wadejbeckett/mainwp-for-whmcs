<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;


/** Where the engine keeps care sites, stored logins and held keys (encrypted by the implementation), approvals and its log. */
interface Storage
{
    public function site(int $addonRowId): ?CareSite;

    /** @return list<CareSite> */
    public function sites(): array;

    public function saveSite(CareSite $site): void;

    public function saveLogin(int $addonRowId, Login $login): void;

    /** The stored login, decrypted, for a retry or a reconnect; null when there is none or it cannot be decrypted. */
    public function login(int $addonRowId): ?Login;

    public function deleteLogin(int $addonRowId): void;

    public function log(LogEntry $entry): void;

    /** Keeps an approval the client was sent to give, by its token's hash, until $expiresAt ('Y-m-d H:i:s'). */
    public function saveApproval(string $tokenHash, int $addonRowId, string $expiresAt): void;

    public function approval(string $tokenHash): ?Approval;

    /** The newest approval kept for the add-on, or null. */
    public function latestApproval(int $addonRowId): ?Approval;

    /**
     * Marks the approval used at $at and keeps the key with it, encrypted by the implementation.
     * Only the first call for a token wins: false when the approval is unknown, was used already,
     * or lapsed before $at.
     */
    public function claimApproval(string $tokenHash, HeldKey $key, string $at): bool;

    /** The key held for the add-on, decrypted, for a retry or a clean-up; null when there is none or it cannot be decrypted. */
    public function heldKey(int $addonRowId): ?HeldKey;

    /** Every approval of the add-on that was not used stops working at $at; the rows stay, so that a late return is still recognised. */
    public function lapseApprovals(int $addonRowId, string $at): void;

    /** Forgets the key held for the add-on: it was deleted on the site, or given up. */
    public function releaseKey(int $addonRowId): void;

    /** Deletes the approvals that hold no key and whose time ran out before $before. */
    public function purgeApprovals(string $before): void;

    /**
     * Takes the add-on for one command at a time, waiting at most $waitSeconds for another request
     * to finish with it: false when one is still working on it. Released by unlock(), or by itself
     * when the request ends.
     */
    public function lock(int $addonRowId, int $waitSeconds = 0): bool;

    public function unlock(int $addonRowId): void;

    /**
     * Takes a WHMCS client for one provision at a time, so that its dashboard client is found or
     * created once, waiting at most $waitSeconds: false when another request still holds it.
     * Released by unlockClient(), or by itself when the request ends.
     */
    public function lockClient(int $whmcsClientId, int $waitSeconds = 0): bool;

    public function unlockClient(int $whmcsClientId): void;

    /**
     * The name of the service the add-on row is care under, as the client knows it: written on the
     * client's site (the application password they approve, the care account's name) and in what they
     * and staff read about it. Read afresh each time; the engine never keeps it.
     */
    public function serviceName(int $addonRowId): string;
}
