<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Support;

use MainWpForWhmcs\Repository;

/**
 * The module's tables and its tbladdonmodules rows in memory. DDL is read, not run: a CREATE TABLE
 * makes the table with the columns its lines name, a DROP TABLE removes it, an ALTER TABLE ... ADD
 * COLUMN adds one and DROP COLUMN removes one, so tests see tables and columns appear and go as they would in MySQL.
 */
final class MemoryRepository implements Repository
{
    /** @var array<string, string> */
    public array $settings = [];
    /** @var array<string, list<string>> table => column names */
    public array $tables = [];
    /** @var list<string> */
    public array $executed = [];
    /** @var array<int, array<string, mixed>> add-on row id => care site row */
    public array $careSites = [];
    /** @var list<array<string, mixed>> */
    public array $log = [];
    /** @var array<int, \MainWpForWhmcs\AddonRow> add-on row id => row */
    public array $addons = [];
    /** @var array<int, list<int>> invoice id => add-on row ids itemised on it */
    public array $invoiceAddons = [];
    /** @var array<int, int> admin id => role id */
    public array $adminRoles = [];

    public function settings(): array
    {
        return $this->settings;
    }

    /** Thrown by saveSettings when set: the database refuses the value. */
    public ?\Throwable $saveRefuses = null;

    public function saveSettings(array $settings): void
    {
        if ($this->saveRefuses !== null) {
            throw $this->saveRefuses;
        }
        foreach ($settings as $key => $value) {
            $this->settings[$key] = $value;
        }
    }

    public function deleteSetting(string $setting): void
    {
        unset($this->settings[$setting]);
    }

    public function execute(string $sql): void
    {
        $this->executed[] = $sql;
        if (preg_match('/^CREATE TABLE IF NOT EXISTS (\w+) \((.*)\)/s', $sql, $m) === 1) {
            if (!isset($this->tables[$m[1]])) {
                preg_match_all('/^\s+([a-z_]+) [A-Z]/m', $m[2], $cols);
                $this->tables[$m[1]] = array_values(array_diff($cols[1], ['KEY', 'UNIQUE', 'PRIMARY']));
            }
        } elseif (preg_match('/^DROP TABLE IF EXISTS (\w+)$/', $sql, $m) === 1) {
            unset($this->tables[$m[1]]);
        } elseif (preg_match('/^ALTER TABLE (\w+) ADD COLUMN (\w+) /', $sql, $m) === 1) {
            if (!isset($this->tables[$m[1]]) || in_array($m[2], $this->tables[$m[1]], true)) {
                throw new \RuntimeException('ALTER failed: ' . $sql);
            }
            $this->tables[$m[1]][] = $m[2];
        } elseif (preg_match('/^ALTER TABLE (\w+) DROP COLUMN (\w+)$/', $sql, $m) === 1) {
            if (!in_array($m[2], $this->tables[$m[1]] ?? [], true)) {
                throw new \RuntimeException('ALTER failed: ' . $sql);
            }
            $this->tables[$m[1]] = array_values(array_diff($this->tables[$m[1]], [$m[2]]));
        } else {
            throw new \RuntimeException('MemoryRepository cannot read: ' . $sql);
        }
    }

    public function hasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->tables[$table] ?? [], true);
    }

    public function careSite(int $addonRowId): ?array
    {
        return $this->careSites[$addonRowId] ?? null;
    }

    public function careSites(): array
    {
        ksort($this->careSites);
        return array_values($this->careSites);
    }

    public function saveCareSite(array $row): void
    {
        $id = (int) $row['addon_row_id'];
        $this->careSites[$id] = $row + ($this->careSites[$id] ?? []);
    }

    public function addLog(array $row): void
    {
        $this->log[] = $row;
    }

    public function lastLog(int $limit): array
    {
        return array_slice(array_reverse($this->log), 0, $limit);
    }

    public function lastLogFor(string $action, string $target, ?string $outcome = null): ?array
    {
        foreach (array_reverse($this->log) as $row) {
            if ($row['action'] === $action && $row['target'] === $target && ($outcome === null || $row['outcome'] === $outcome)) {
                return $row;
            }
        }
        return null;
    }

    public function logSince(string $since, array $actions): array
    {
        return array_values(array_filter($this->log, static fn (array $row): bool => (string) ($row['created_at'] ?? '') > $since && in_array($row['action'] ?? null, $actions, true)));
    }

    private const LOG_ORDER = ['created_at', 'actor', 'action', 'target', 'outcome'];

    public function logPage(array $filter, string $orderBy, string $direction, int $offset, int $limit): array
    {
        $known = in_array($orderBy, self::LOG_ORDER, true) && in_array($direction, ['asc', 'desc'], true);
        $orderBy = $known ? $orderBy : 'created_at';
        $direction = $known ? $direction : 'desc';
        $rows = $this->logMatching($filter);
        uksort($rows, static function (int $a, int $b) use ($rows, $orderBy, $direction): int {
            $byValue = strcasecmp((string) $rows[$a][$orderBy], (string) $rows[$b][$orderBy]);
            return ($byValue !== 0 ? $byValue : $a <=> $b) * ($direction === 'asc' ? 1 : -1);
        });
        return array_slice(array_values($rows), max(0, $offset), max(1, $limit));
    }

    public function logCount(array $filter): int
    {
        return count($this->logMatching($filter));
    }

    public function logOutcomes(): array
    {
        $outcomes = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['outcome'], $this->log)));
        sort($outcomes);
        return $outcomes;
    }

    public function clearLog(): int
    {
        $rows = count($this->log);
        $this->log = [];
        return $rows;
    }

    public function pruneLog(string $before): int
    {
        $kept = array_values(array_filter($this->log, static fn (array $row): bool => (string) ($row['created_at'] ?? '') >= $before));
        $gone = count($this->log) - count($kept);
        $this->log = $kept;
        return $gone;
    }

    /**
     * @param array<array-key, mixed> $filter
     * @return array<int, array<string, mixed>> the rows that match, by the order they were written in
     */
    private function logMatching(array $filter): array
    {
        $search = is_scalar($filter['search'] ?? null) ? trim((string) $filter['search']) : '';
        $outcome = is_scalar($filter['outcome'] ?? null) ? (string) $filter['outcome'] : '';
        return array_filter($this->log, static function (array $row) use ($search, $outcome): bool {
            if ($outcome !== '' && (string) $row['outcome'] !== $outcome) {
                return false;
            }
            if ($search === '') {
                return true;
            }
            foreach (['created_at', 'actor', 'action', 'target', 'outcome', 'detail'] as $column) {
                if (mb_stripos((string) ($row[$column] ?? ''), $search) !== false) {
                    return true;
                }
            }
            return false;
        });
    }

    /** @var array<int, string> WHMCS's product add-ons as stored: id => name, entity-encoded as typed */
    public array $productAddons = [16 => 'WordPress Care'];

    public function productAddons(): array
    {
        $addons = array_map(static fn (string $name): string => trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8')), $this->productAddons);
        uasort($addons, static fn (string $a, string $b): int => strcasecmp($a, $b));
        return $addons;
    }

    /** @var array<int, string> the module each product add-on's Module Settings name, as stored (tbladdons.module): id => module; none unless a test says so */
    public array $addonModules = [];

    public function careAddonIds(): array
    {
        $ids = array_keys(array_filter($this->addonModules, static fn (string $module): bool => $module === \MainWpForWhmcs\Module::NAME));
        sort($ids);
        return $ids;
    }

    /** @var array<int, array{firstname: string, lastname: string, companyname: string}> WHMCS's clients as stored */
    public array $clients = [];

    public function clientName(int $clientId): ?string
    {
        $row = $this->clients[$clientId] ?? null;
        if ($row === null) {
            return null;
        }
        $decode = static fn (string $name): string => trim(html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return $decode($row['companyname']) !== '' ? $decode($row['companyname']) : trim($decode($row['firstname']) . ' ' . $decode($row['lastname']));
    }

    /** WHMCS's "Records to Display per Page" as stored, or null when it is not set. */
    public ?string $recordsPerPage = null;

    public function recordsPerPage(): int
    {
        $records = $this->recordsPerPage !== null && ctype_digit($this->recordsPerPage) ? (int) $this->recordsPerPage : 0;
        return $records >= 1 && $records <= 1000 ? $records : 50;
    }

    /** @var list<string> the administrators whose role receives WHMCS's system emails */
    public array $systemEmailAdmins = ['Ada Admin'];

    public function systemEmailAdmins(): array
    {
        return $this->systemEmailAdmins;
    }

    public function adminRoleId(int $adminId): ?int
    {
        return $this->adminRoles[$adminId] ?? null;
    }

    public function addonRow(int $addonRowId): ?\MainWpForWhmcs\AddonRow
    {
        return $this->addons[$addonRowId] ?? null;
    }

    public function serviceAddonRows(int $serviceId): array
    {
        ksort($this->addons);
        return array_values(array_filter($this->addons, static fn (\MainWpForWhmcs\AddonRow $r): bool => $r->serviceId === $serviceId));
    }

    public function careAddonRows(array $addonIds): array
    {
        ksort($this->addons);
        return array_values(array_filter($this->addons, static fn (\MainWpForWhmcs\AddonRow $r): bool => in_array($r->addonId, $addonIds, true)));
    }

    public function addonRowsOnInvoice(int $invoiceId): array
    {
        return $this->invoiceAddons[$invoiceId] ?? [];
    }

    /** @var list<array<string, mixed>> mod_mainwp_tokens rows, oldest first */
    public array $approvals = [];
    /** @var list<string> locks this request holds */
    public array $locks = [];
    /** @var list<string> locks another request holds */
    public array $lockedElsewhere = [];
    public string $systemUrl = 'https://billing.example.com';

    public function addApproval(array $row): void
    {
        $this->approvals[] = ['id' => count($this->approvals) + 1] + $row + ['used_at' => null, 'key_hash' => null, 'key_encrypted' => null];
    }

    public function approval(string $tokenHash): ?array
    {
        foreach ($this->approvals as $row) {
            if ($row['token_hash'] === $tokenHash) {
                return $row;
            }
        }
        return null;
    }

    public function approvals(int $addonRowId): array
    {
        return array_values(array_filter($this->approvals, static fn (array $row): bool => (int) $row['addon_row_id'] === $addonRowId));
    }

    public function claimApproval(string $tokenHash, string $keyEncrypted, string $keyHash, string $at): bool
    {
        foreach ($this->approvals as $i => $row) {
            if ($row['token_hash'] === $tokenHash && $row['used_at'] === null && $row['expires_at'] > $at) {
                $this->approvals[$i]['used_at'] = $at;
                $this->approvals[$i]['key_hash'] = $keyHash;
                $this->approvals[$i]['key_encrypted'] = $keyEncrypted;
                return true;
            }
        }
        return false;
    }

    public function lapseApprovals(int $addonRowId, string $at): void
    {
        foreach ($this->approvals as $i => $row) {
            if ((int) $row['addon_row_id'] === $addonRowId && $row['used_at'] === null && $row['expires_at'] > $at) {
                $this->approvals[$i]['expires_at'] = $at;
            }
        }
    }

    public function releaseApprovalKeys(int $addonRowId): void
    {
        foreach ($this->approvals as $i => $row) {
            if ((int) $row['addon_row_id'] === $addonRowId) {
                $this->approvals[$i]['key_encrypted'] = null;
            }
        }
    }

    public function purgeApprovals(string $before): void
    {
        $this->approvals = array_values(array_filter($this->approvals, static fn (array $row): bool => $row['key_encrypted'] !== null || $row['expires_at'] >= $before));
    }

    /** @var (\Closure(string): void)|null called as a lock is asked for: what another run did meanwhile */
    public ?\Closure $onLock = null;

    public function lock(string $name, int $waitSeconds = 0): bool
    {
        if ($this->onLock !== null) {
            ($this->onLock)($name);
        }
        if (in_array($name, $this->lockedElsewhere, true)) {
            return false;
        }
        $this->locks[] = $name;
        return true;
    }

    public function unlock(string $name): void
    {
        $this->locks = array_values(array_diff($this->locks, [$name]));
    }

    public function systemUrl(): string
    {
        return rtrim($this->systemUrl, '/');
    }

    /** WHMCS's admin folder as adminFolder() gives it. */
    public string $adminFolder = 'admin';

    public function adminFolder(): string
    {
        return $this->adminFolder;
    }

    /** @var list<array<string, mixed>> tblmodulequeue rows, with the columns of WHMCS 9.1 */
    public array $moduleQueue = [];

    /** What reading WHMCS's Module Queue throws, as a database error would. */
    public ?\Throwable $moduleQueueError = null;

    public function failedAddonCommands(array $addonIds): array
    {
        if ($this->moduleQueueError !== null) {
            throw $this->moduleQueueError;
        }
        $rows = [];
        foreach ($this->moduleQueue as $row) {
            $addon = $this->addons[(int) $row['service_id']] ?? null;
            if ($row['service_type'] === 'addon' && $row['module_name'] === \MainWpForWhmcs\Module::NAME && (int) $row['completed'] === 0
                && strtolower((string) $row['module_action']) !== strtolower(self::QUEUED_CREATE) && $addon !== null && in_array($addon->addonId, $addonIds, true)) {
                $rows[(int) $row['id']] = ['id' => (int) $row['id'], 'addon_row_id' => (int) $row['service_id'], 'action' => (string) $row['module_action'], 'error' => (string) $row['last_attempt_error'], 'retries' => (int) $row['num_retries'], 'last_attempt' => (string) $row['last_attempt']];
            }
        }
        ksort($rows);
        return array_values($rows);
    }
}
