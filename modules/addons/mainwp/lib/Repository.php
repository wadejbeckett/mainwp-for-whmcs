<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

/**
 * The WHMCS layer's data seam: the module's rows in tbladdonmodules and its own tables. Production
 * binds it to WHMCS's database (CapsuleRepository); tests use an in-memory one.
 */
interface Repository
{
    /** Commands of WHMCS's Module Queue as WHMCS stores them, with WHMCS's own button word for each; matched in any letter case. */
    public const QUEUED_COMMANDS = ['SuspendAccount' => 'Suspend', 'UnsuspendAccount' => 'Unsuspend', 'TerminateAccount' => 'Terminate'];
    /** The one command failedAddonCommands() leaves out, in any letter case: the daily check retries a provision itself. */
    public const QUEUED_CREATE = 'CreateAccount';
    /** @return array<string, string> the module's settings rows, decoded */
    public function settings(): array;

    /** @param array<string, string> $settings */
    public function saveSettings(array $settings): void;

    /** Deletes the module's settings row of that name; one that is not there is done. */
    public function deleteSetting(string $setting): void;

    public function execute(string $sql): void;

    public function hasColumn(string $table, string $column): bool;

    /** @return array<string, mixed>|null a mod_mainwp_sites row */
    public function careSite(int $addonRowId): ?array;

    /** @return list<array<string, mixed>> every mod_mainwp_sites row, by add-on row id */
    public function careSites(): array;

    /** @param array<string, mixed> $row inserted, or merged into the row with the same addon_row_id */
    public function saveCareSite(array $row): void;

    /** @param array{created_at: string, actor: string, action: string, target: string, outcome: string, dry_run: int, detail: string} $row */
    public function addLog(array $row): void;

    /** @return list<array<string, mixed>> the newest $limit log rows, newest first */
    public function lastLog(int $limit): array;

    /** @return array<string, mixed>|null the newest log row with this action and target (and this outcome, when one is given), or null */
    public function lastLogFor(string $action, string $target, ?string $outcome = null): ?array;

    /**
     * @param list<string> $actions
     * @return list<array<string, mixed>> the log rows with one of these actions written after $since (Y-m-d H:i:s), oldest first; the newest 1000 when there are more
     */
    public function logSince(string $since, array $actions): array;

    /**
     * The log as the page's list asks for it: narrowed by words found anywhere in a row (also in its
     * time) and by an outcome, ordered by one of created_at, actor, action, target or outcome (anything
     * else is created_at, newest first), rows of equal value in the order they were written.
     *
     * @param array{search?: string, outcome?: string} $filter
     * @return list<array<string, mixed>> $limit rows from the $offset-th
     */
    public function logPage(array $filter, string $orderBy, string $direction, int $offset, int $limit): array;

    /** @param array{search?: string, outcome?: string} $filter */
    public function logCount(array $filter): int;

    /** @return list<string> every outcome the log holds, once, in alphabetical order */
    public function logOutcomes(): array;

    /** Deletes every log row; returns how many there were. */
    public function clearLog(): int;

    /** Deletes the log rows written before $before (Y-m-d H:i:s); returns how many. */
    public function pruneLog(string $before): int;

    /** @return array<int, string> WHMCS's product add-ons, id => name, by name */
    public function productAddons(): array;

    /** @return list<int> the care add-ons: the product add-ons whose Module Settings name this module (tbladdons.module is exactly Module::NAME), in ascending order */
    public function careAddonIds(): array;

    /** A WHMCS client's name as WHMCS lists it (the company when there is one, else first and last name), or null when there is no such client. */
    public function clientName(int $clientId): ?string;

    /** How many records WHMCS shows on a page of its own lists (General Settings, "Records to Display per Page"); 50 when that is not a number from 1 to 1000. */
    public function recordsPerPage(): int;

    /** @return list<string> the names of the active WHMCS administrators whose role receives system emails: who SendAdminEmail of type "system" goes to */
    public function systemEmailAdmins(): array;

    /** The product add-on row (tblhostingaddons.id) with its service, client and unpaid invoice count, or null when WHMCS holds no such row. */
    public function addonRow(int $addonRowId): ?AddonRow;

    /** @return list<AddonRow> every product add-on row on the hosting service, by row id */
    public function serviceAddonRows(int $serviceId): array;

    /**
     * @param list<int> $addonIds catalogue ids of the add-ons that count as care
     * @return list<AddonRow> every product add-on row of those add-ons, whatever its status, by row id
     */
    public function careAddonRows(array $addonIds): array;

    /** @return list<int> the add-on rows (tblhostingaddons.id) itemised on the invoice */
    public function addonRowsOnInvoice(int $invoiceId): array;

    /** The role of a WHMCS admin (tbladmins.roleid), or null when there is no such admin. */
    public function adminRoleId(int $adminId): ?int;

    /** @param array{token_hash: string, addon_row_id: int, expires_at: string, created_at: string} $row a new mod_mainwp_tokens row */
    public function addApproval(array $row): void;

    /** @return array<string, mixed>|null the mod_mainwp_tokens row with this token hash */
    public function approval(string $tokenHash): ?array;

    /** @return list<array<string, mixed>> the add-on's mod_mainwp_tokens rows, oldest first */
    public function approvals(int $addonRowId): array;

    /** Sets used_at, key_hash and key_encrypted on the row only if it was not used yet and expires after $at, in one statement; true when this call did. */
    public function claimApproval(string $tokenHash, string $keyEncrypted, string $keyHash, string $at): bool;

    /** Sets expires_at to $at on the add-on's unused rows that expire later. */
    public function lapseApprovals(int $addonRowId, string $at): void;

    /** Clears key_encrypted on the add-on's rows. */
    public function releaseApprovalKeys(int $addonRowId): void;

    /** Deletes the rows that hold no key and expired before $before. */
    public function purgeApprovals(string $before): void;

    /** Takes a named lock for this request, waiting at most $waitSeconds; false when another request still holds it. It is released by unlock() or when the request ends. */
    public function lock(string $name, int $waitSeconds = 0): bool;

    public function unlock(string $name): void;

    /** WHMCS's System URL without a trailing slash, or '' when none is set. */
    public function systemUrl(): string;

    /** The name of WHMCS's admin folder: WHMCS's custom admin path when it is a plain folder name (letters, digits, hyphens, underscores), else "admin". */
    public function adminFolder(): string;

    /**
     * The open rows of WHMCS's Module Queue (tblmodulequeue) for this module's commands on a product
     * add-on row of these add-ons, whatever the command, except a Create (QUEUED_CREATE, in any letter
     * case). Read only: the module never retries, edits or deletes a queue row.
     *
     * @param list<int> $addonIds catalogue ids of the add-ons that count as care
     * @return list<array{id: int, addon_row_id: int, action: string, error: string, retries: int, last_attempt: string}> by queue row id
     */
    public function failedAddonCommands(array $addonIds): array;
}
