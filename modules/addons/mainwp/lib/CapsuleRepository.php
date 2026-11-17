<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

use WHMCS\Database\Capsule;

/** The Repository on WHMCS's own database layer (WHMCS\Database\Capsule). */
final class CapsuleRepository implements Repository
{
    private const SETTINGS = 'tbladdonmodules';

    public function settings(): array
    {
        $settings = [];
        foreach (Capsule::table(self::SETTINGS)->where('module', Module::NAME)->get(['setting', 'value']) as $row) {
            $settings[(string) $row->setting] = html_entity_decode((string) $row->value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $settings;
    }

    /** Stored HTML-encoded, as WHMCS stores the values of its own Configure form, so settings() reads both alike. */
    public function saveSettings(array $settings): void
    {
        foreach ($settings as $setting => $value) {
            Capsule::table(self::SETTINGS)->updateOrInsert(
                ['module' => Module::NAME, 'setting' => $setting],
                ['value' => htmlspecialchars($value, ENT_QUOTES, 'UTF-8')],
            );
        }
    }

    public function deleteSetting(string $setting): void
    {
        Capsule::table(self::SETTINGS)->where('module', Module::NAME)->where('setting', $setting)->delete();
    }

    public function execute(string $sql): void
    {
        Capsule::connection()->statement($sql);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return (bool) Capsule::schema()->hasColumn($table, $column);
    }

    public function careSite(int $addonRowId): ?array
    {
        $row = Capsule::table(Schema::SITES)->where('addon_row_id', $addonRowId)->first();
        return $row === null ? null : (array) $row;
    }

    public function careSites(): array
    {
        $rows = [];
        foreach (Capsule::table(Schema::SITES)->orderBy('addon_row_id')->get() as $row) {
            $rows[] = (array) $row;
        }
        return $rows;
    }

    public function saveCareSite(array $row): void
    {
        Capsule::table(Schema::SITES)->updateOrInsert(['addon_row_id' => $row['addon_row_id']], $row);
    }

    public function addLog(array $row): void
    {
        $row['actor'] = mb_substr($row['actor'], 0, 64);
        $row['target'] = mb_substr($row['target'], 0, 255);
        Capsule::table(Schema::LOG)->insert($row);
    }

    public function lastLog(int $limit): array
    {
        $rows = [];
        foreach (Capsule::table(Schema::LOG)->orderBy('id', 'desc')->limit($limit)->get() as $row) {
            $rows[] = (array) $row;
        }
        return $rows;
    }

    public function lastLogFor(string $action, string $target, ?string $outcome = null): ?array
    {
        $query = Capsule::table(Schema::LOG)->where('action', $action)->where('target', $target);
        if ($outcome !== null) {
            $query = $query->where('outcome', $outcome);
        }
        $row = $query->orderBy('id', 'desc')->first();
        return $row === null ? null : (array) $row;
    }

    public function logSince(string $since, array $actions): array
    {
        $rows = [];
        foreach (Capsule::table(Schema::LOG)->where('created_at', '>', $since)->whereIn('action', $actions)->orderBy('id', 'desc')->limit(1000)->get() as $row) {
            $rows[] = (array) $row;
        }
        return array_reverse($rows);
    }

    private const LOG_ORDER = ['created_at', 'actor', 'action', 'target', 'outcome'];

    public function logPage(array $filter, string $orderBy, string $direction, int $offset, int $limit): array
    {
        $known = in_array($orderBy, self::LOG_ORDER, true) && in_array($direction, ['asc', 'desc'], true);
        $orderBy = $known ? $orderBy : 'created_at';
        $direction = $known ? $direction : 'desc';
        $rows = [];
        foreach ($this->logQuery($filter)->orderBy($orderBy, $direction)->orderBy('id', $direction)->offset(max(0, $offset))->limit(max(1, $limit))->get() as $row) {
            $rows[] = (array) $row;
        }
        return $rows;
    }

    public function logCount(array $filter): int
    {
        return (int) $this->logQuery($filter)->count();
    }

    public function logOutcomes(): array
    {
        $outcomes = [];
        foreach (Capsule::table(Schema::LOG)->distinct()->orderBy('outcome')->pluck('outcome') as $outcome) {
            $outcomes[] = (string) $outcome;
        }
        return $outcomes;
    }

    public function clearLog(): int
    {
        return (int) Capsule::table(Schema::LOG)->delete();
    }

    public function pruneLog(string $before): int
    {
        return (int) Capsule::table(Schema::LOG)->where('created_at', '<', $before)->delete();
    }

    /** @param array<array-key, mixed> $filter */
    private function logQuery(array $filter): mixed
    {
        $query = Capsule::table(Schema::LOG);
        $search = is_scalar($filter['search'] ?? null) ? trim((string) $filter['search']) : '';
        if ($search !== '') {
            // The words are looked for as they are: "%" and "_" mean themselves.
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $query = $query->where(static function ($any) use ($like): void {
                foreach (['created_at', 'actor', 'action', 'target', 'outcome', 'detail'] as $column) {
                    $any->orWhere($column, 'like', $like);
                }
            });
        }
        $outcome = is_scalar($filter['outcome'] ?? null) ? (string) $filter['outcome'] : '';
        return $outcome !== '' ? $query->where('outcome', $outcome) : $query;
    }

    /** tbladdons.id and name, as read on WHMCS 9.1 (11 November 2026). A name is stored as it was typed, entity-encoded. */
    public function productAddons(): array
    {
        $addons = [];
        foreach (Capsule::table('tbladdons')->get(['id', 'name']) as $row) {
            $addons[(int) $row->id] = self::decodeName($row->name);
        }
        uasort($addons, static fn (string $a, string $b): int => strcasecmp($a, $b));
        return $addons;
    }

    /** tbladdons.module holds the module a product add-on's Module Settings name: "mainwp" on the care add-on, '' on the others (live WHMCS, 15 November 2026). */
    public function careAddonIds(): array
    {
        $ids = [];
        // Compared here as well: MySQL's own comparison ignores letter case and trailing spaces.
        foreach (Capsule::table('tbladdons')->where('module', Module::NAME)->orderBy('id')->get(['id', 'module']) as $row) {
            if ($row->module === Module::NAME) {
                $ids[] = (int) $row->id;
            }
        }
        return $ids;
    }

    public function clientName(int $clientId): ?string
    {
        $row = Capsule::table('tblclients')->where('id', $clientId)->first(['firstname', 'lastname', 'companyname']);
        if ($row === null) {
            return null;
        }
        $company = self::decodeName($row->companyname);
        return $company !== '' ? $company : trim(self::decodeName($row->firstname) . ' ' . self::decodeName($row->lastname));
    }

    /** tblconfiguration "NumRecordstoDisplay", as read on WHMCS 9.1 (11 November 2026: 50). */
    public function recordsPerPage(): int
    {
        $stored = Capsule::table('tblconfiguration')->where('setting', 'NumRecordstoDisplay')->value('value');
        $records = is_string($stored) && ctype_digit($stored) ? (int) $stored : 0;
        return $records >= 1 && $records <= 1000 ? $records : 50;
    }

    /** tbladminroles.systememails and tbladmins.disabled, as read on WHMCS 9.1 (10 November 2026). */
    public function systemEmailAdmins(): array
    {
        $names = [];
        $rows = Capsule::table('tbladmins as a')->join('tbladminroles as r', 'r.id', '=', 'a.roleid')
            ->where('r.systememails', 1)->where('a.disabled', 0)->orderBy('a.id')->get(['a.firstname', 'a.lastname']);
        foreach ($rows as $row) {
            $name = trim(self::decodeName($row->firstname ?? null) . ' ' . self::decodeName($row->lastname ?? null));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }

    public function adminRoleId(int $adminId): ?int
    {
        $role = Capsule::table('tbladmins')->where('id', $adminId)->value('roleid');
        return is_numeric($role) ? (int) $role : null;
    }

    public function addonRow(int $addonRowId): ?AddonRow
    {
        $row = $this->addonQuery()->where('a.id', $addonRowId)->first();
        return $row === null ? null : $this->toAddonRow($row);
    }

    public function serviceAddonRows(int $serviceId): array
    {
        $rows = [];
        foreach ($this->addonQuery()->where('a.hostingid', $serviceId)->orderBy('a.id')->get() as $row) {
            $rows[] = $this->toAddonRow($row);
        }
        return $rows;
    }

    public function careAddonRows(array $addonIds): array
    {
        if ($addonIds === []) {
            return [];
        }
        $rows = [];
        foreach ($this->addonQuery()->whereIn('a.addonid', $addonIds)->orderBy('a.id')->get() as $row) {
            $rows[] = $this->toAddonRow($row);
        }
        return $rows;
    }

    /** An add-on is itemised on an invoice as type "Addon" with its tblhostingaddons id in relid (read on the live install, 10 November 2026). */
    public function addonRowsOnInvoice(int $invoiceId): array
    {
        $ids = [];
        foreach (Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->where('type', 'Addon')->pluck('relid') as $relid) {
            if (is_numeric($relid) && (int) $relid > 0) {
                $ids[] = (int) $relid;
            }
        }
        return array_values(array_unique($ids));
    }

    public function addApproval(array $row): void
    {
        Capsule::table(Schema::TOKENS)->insert($row);
    }

    public function approval(string $tokenHash): ?array
    {
        $row = Capsule::table(Schema::TOKENS)->where('token_hash', $tokenHash)->first();
        return $row === null ? null : (array) $row;
    }

    public function approvals(int $addonRowId): array
    {
        $rows = [];
        foreach (Capsule::table(Schema::TOKENS)->where('addon_row_id', $addonRowId)->orderBy('id')->get() as $row) {
            $rows[] = (array) $row;
        }
        return $rows;
    }

    /** One UPDATE with the conditions in it, so that of two requests bringing the same token only one stores its key, and none on a link that lapsed. */
    public function claimApproval(string $tokenHash, string $keyEncrypted, string $keyHash, string $at): bool
    {
        return (int) Capsule::table(Schema::TOKENS)->where('token_hash', $tokenHash)->whereNull('used_at')->where('expires_at', '>', $at)->update(['used_at' => $at, 'key_hash' => $keyHash, 'key_encrypted' => $keyEncrypted]) === 1;
    }

    public function lapseApprovals(int $addonRowId, string $at): void
    {
        Capsule::table(Schema::TOKENS)->where('addon_row_id', $addonRowId)->whereNull('used_at')->where('expires_at', '>', $at)->update(['expires_at' => $at]);
    }

    public function releaseApprovalKeys(int $addonRowId): void
    {
        Capsule::table(Schema::TOKENS)->where('addon_row_id', $addonRowId)->whereNotNull('key_encrypted')->update(['key_encrypted' => null]);
    }

    public function purgeApprovals(string $before): void
    {
        Capsule::table(Schema::TOKENS)->whereNull('key_encrypted')->where('expires_at', '<', $before)->delete();
    }

    /** MySQL's GET_LOCK: held by this database connection, so it ends with the request even if the request dies. */
    public function lock(string $name, int $waitSeconds = 0): bool
    {
        $row = Capsule::connection()->selectOne('SELECT GET_LOCK(?, ?) AS taken', [$this->lockName($name), max(0, $waitSeconds)]);
        return is_object($row) && (int) ($row->taken ?? 0) === 1;
    }

    public function unlock(string $name): void
    {
        Capsule::connection()->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->lockName($name)]);
    }

    /** Lock names are shared by every database on the server, so the name carries this database's; 64 characters at most. */
    private function lockName(string $name): string
    {
        return substr(Module::NAME . ':' . substr(sha1((string) Capsule::connection()->getDatabaseName()), 0, 12) . ':' . $name, 0, 64);
    }

    public function systemUrl(): string
    {
        $value = Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
        return is_string($value) ? rtrim(trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')), '/') : '';
    }

    /**
     * WHMCS's own accessor first (WHMCS\Application's get_admin_folder_name(), through \App::self()),
     * then the configuration variable $customadminpath as a global, then "admin". Both gave the custom
     * folder name on the live WHMCS 9.1 when asked from the command line, as the cron runs (16 November 2026).
     */
    public function adminFolder(): string
    {
        $fromApp = null;
        try {
            $app = is_callable(['App', 'self']) ? call_user_func(['App', 'self']) : null;
            $fromApp = is_object($app) && method_exists($app, 'get_admin_folder_name') ? $app->get_admin_folder_name() : null;
        } catch (\Throwable) {
            // WHMCS without the accessor: the global below
        }
        return self::adminFolderFrom($fromApp, $GLOBALS['customadminpath'] ?? null);
    }

    /** The first of these that is a plain folder name (letters, digits, hyphens, underscores), else WHMCS's default "admin". */
    public static function adminFolderFrom(mixed ...$found): string
    {
        foreach ($found as $folder) {
            if (is_string($folder) && preg_match('/^[A-Za-z0-9_-]+$/', $folder) === 1) {
                return $folder;
            }
        }
        return 'admin';
    }

    /**
     * tblmodulequeue as read on the live WHMCS 9.1 on 16 November 2026: service_type "addon" with the
     * add-on row id in service_id, module_name the module's system name, completed 0 while open.
     */
    public function failedAddonCommands(array $addonIds): array
    {
        if ($addonIds === []) {
            return [];
        }
        $rows = [];
        $found = Capsule::table('tblmodulequeue as q')->join('tblhostingaddons as a', 'a.id', '=', 'q.service_id')
            ->where('q.service_type', 'addon')->where('q.module_name', Module::NAME)->where('q.completed', 0)
            ->whereRaw('LOWER(q.module_action) <> ?', [strtolower(self::QUEUED_CREATE)])->whereIn('a.addonid', $addonIds)
            ->orderBy('q.id')->get(['q.id', 'q.service_id', 'q.module_action', 'q.last_attempt_error', 'q.num_retries', 'q.last_attempt']);
        foreach ($found as $row) {
            $rows[] = self::queueRow($row);
        }
        return $rows;
    }

    /**
     * One tblmodulequeue row as failedAddonCommands() gives it: for service_type "addon" WHMCS keeps
     * the add-on row id (tblhostingaddons.id) in service_id.
     *
     * @return array{id: int, addon_row_id: int, action: string, error: string, retries: int, last_attempt: string}
     */
    public static function queueRow(object $row): array
    {
        $text = static fn (string $key): string => is_scalar($row->{$key} ?? null) ? (string) $row->{$key} : '';
        return [
            'id' => (int) $text('id'),
            'addon_row_id' => (int) $text('service_id'),
            'action' => $text('module_action'),
            'error' => $text('last_attempt_error'),
            'retries' => (int) $text('num_retries'),
            'last_attempt' => $text('last_attempt'),
        ];
    }

    /**
     * The WHMCS invoice statuses that still wait for the client's money (code review, 16 November
     * 2026). Not Draft: a draft is seen only by staff, so the client cannot pay it.
     */
    private const UNPAID_INVOICES = ['Unpaid', 'Payment Pending', 'Collections'];

    /** Whether an invoice in this WHMCS status counts as unpaid: Unpaid, Payment Pending or Collections. */
    public static function invoiceIsUnpaid(string $status): bool
    {
        return in_array($status, self::UNPAID_INVOICES, true);
    }

    /** A name as the client wrote it: WHMCS stores what is typed into its forms entity-encoded ("&" as "&amp;"). Decoded once. */
    public static function decodeName(mixed $stored): string
    {
        return is_scalar($stored) ? trim(html_entity_decode((string) $stored, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    private function addonQuery(): mixed
    {
        return Capsule::table('tblhostingaddons as a')
            ->join('tblhosting as h', 'h.id', '=', 'a.hostingid')
            ->join('tblclients as c', 'c.id', '=', 'h.userid')
            ->select(['a.id', 'a.hostingid', 'h.userid', 'a.addonid', 'a.status', 'a.billingcycle', 'a.recurring', 'h.domainstatus', 'h.domain', 'c.companyname', 'c.firstname', 'c.lastname', 'c.email']);
    }

    private function toAddonRow(object $row): AddonRow
    {
        $text = static fn (string $key): string => is_scalar($row->{$key} ?? null) ? trim((string) $row->{$key}) : '';
        $id = (int) $text('id');
        // Invoices, not lines: one unpaid invoice may itemise the add-on twice (after a merge, say).
        // An invoice in Payment Pending or Collections is not paid either, and reading it as paid would
        // set care up before money arrives. A Draft is not a bill yet: the client can neither see nor pay it.
        $unpaid = Capsule::table('tblinvoiceitems as ii')
            ->join('tblinvoices as i', 'i.id', '=', 'ii.invoiceid')
            ->where('ii.type', 'Addon')->where('ii.relid', $id)->whereIn('i.status', self::UNPAID_INVOICES)
            ->distinct()->count('ii.invoiceid');
        $company = self::decodeName($row->companyname ?? null);
        $name = $company !== '' ? $company : trim(self::decodeName($row->firstname ?? null) . ' ' . self::decodeName($row->lastname ?? null));
        return new AddonRow($id, (int) $text('hostingid'), (int) $text('userid'), (int) $text('addonid'), $text('status'), $text('domainstatus'), $text('domain'), $name, $text('email'), (int) $unpaid, self::isFree($row->billingcycle ?? null, $row->recurring ?? null));
    }

    /**
     * Whether an add-on row is set to free (GLOSSARY.md, "Free care add-on"): its own billing cycle is
     * WHMCS's "Free Account" (in any letter case), or its own recurring amount is not above nothing.
     */
    public static function isFree(mixed $billingCycle, mixed $recurring): bool
    {
        $cycle = is_scalar($billingCycle) ? strtolower(trim((string) $billingCycle)) : '';
        return $cycle === 'free account' || !(is_numeric($recurring) && (float) $recurring > 0);
    }
}
