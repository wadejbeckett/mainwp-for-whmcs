<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

use MainWpForWhmcs\Engine\Approval;
use MainWpForWhmcs\Engine\CareSite;
use MainWpForWhmcs\Engine\HeldKey;
use MainWpForWhmcs\Engine\LogEntry;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\State;
use MainWpForWhmcs\Engine\Storage;

/**
 * The engine's storage on the module's tables. A stored login is one JSON object encrypted with
 * WHMCS's encryption in mod_mainwp_sites.login_encrypted, a held key one in
 * mod_mainwp_tokens.key_encrypted; neither is ever read back into a log or a page. A site's other
 * administrators are a JSON list of login names in mod_mainwp_sites.other_admins.
 */
final class RepositoryStorage implements Storage
{
    /** @param \Closure(): string $now 'Y-m-d H:i:s' */
    public function __construct(
        private readonly Repository $repo,
        private readonly Encrypter $encrypter,
        private readonly \Closure $now,
    ) {
    }

    public function site(int $addonRowId): ?CareSite
    {
        $row = $this->repo->careSite($addonRowId);
        return $row === null ? null : self::fromRow($row);
    }

    public function sites(): array
    {
        return array_map(self::fromRow(...), $this->repo->careSites());
    }

    public function saveSite(CareSite $site): void
    {
        $now = ($this->now)();
        $row = [
            'addon_row_id' => $site->addonRowId,
            'service_id' => $site->serviceId,
            'client_id' => $site->clientId,
            'site_url' => $site->siteUrl,
            'dashboard_client_id' => $site->dashboardClientId,
            'dashboard_site_id' => $site->dashboardSiteId,
            'state' => $site->state->value,
            'retry_count' => $site->retryCount,
            'last_sync' => $site->lastSync,
            'last_error' => $site->lastError,
            'retry_after' => $site->retryAfter,
            'other_admins' => $site->otherAdmins === null ? null : json_encode(array_values($site->otherAdmins), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => $now,
        ];
        if ($this->repo->careSite($site->addonRowId) === null) {
            $row['created_at'] = $now;
        }
        $this->repo->saveCareSite($row);
    }

    public function saveLogin(int $addonRowId, Login $login): void
    {
        $json = json_encode(['username' => $login->username, 'password' => $login->password], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->repo->saveCareSite(['addon_row_id' => $addonRowId, 'login_encrypted' => $this->encrypter->encrypt($json), 'updated_at' => ($this->now)()]);
    }

    public function login(int $addonRowId): ?Login
    {
        $cipher = $this->repo->careSite($addonRowId)['login_encrypted'] ?? null;
        if (!is_string($cipher) || $cipher === '') {
            return null;
        }
        try {
            $data = json_decode($this->encrypter->decrypt($cipher), true);
        } catch (\RuntimeException) {
            return null;
        }
        return is_array($data) && is_string($data['username'] ?? null) && is_string($data['password'] ?? null) ? new Login($data['username'], $data['password']) : null;
    }

    public function deleteLogin(int $addonRowId): void
    {
        if ($this->repo->careSite($addonRowId) !== null) {
            $this->repo->saveCareSite(['addon_row_id' => $addonRowId, 'login_encrypted' => null, 'updated_at' => ($this->now)()]);
        }
    }

    public function log(LogEntry $entry): void
    {
        $this->repo->addLog([
            'created_at' => $entry->time,
            'actor' => $entry->actor,
            'action' => $entry->action,
            'target' => $entry->target,
            'outcome' => $entry->outcome,
            'dry_run' => $entry->dryRun ? 1 : 0,
            'detail' => $entry->detail,
        ]);
    }

    public function saveApproval(string $tokenHash, int $addonRowId, string $expiresAt): void
    {
        $this->repo->addApproval(['token_hash' => $tokenHash, 'addon_row_id' => $addonRowId, 'expires_at' => $expiresAt, 'created_at' => ($this->now)()]);
    }

    public function approval(string $tokenHash): ?Approval
    {
        $row = $this->repo->approval($tokenHash);
        return $row === null ? null : self::toApproval($row);
    }

    public function latestApproval(int $addonRowId): ?Approval
    {
        $rows = $this->repo->approvals($addonRowId);
        return $rows === [] ? null : self::toApproval($rows[array_key_last($rows)]);
    }

    /**
     * The key and the care account's login are one JSON object, encrypted with WHMCS's encryption in
     * mod_mainwp_tokens.key_encrypted; the key's SHA-256 beside it tells the same return from a second key.
     */
    public function claimApproval(string $tokenHash, HeldKey $key, string $at): bool
    {
        $json = json_encode(['user' => $key->key->username, 'key' => $key->key->password, 'care_user' => $key->care->username, 'care_password' => $key->care->password], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $this->repo->claimApproval($tokenHash, $this->encrypter->encrypt($json), hash('sha256', $key->key->password), $at);
    }

    public function heldKey(int $addonRowId): ?HeldKey
    {
        foreach (array_reverse($this->repo->approvals($addonRowId)) as $row) {
            $cipher = $row['key_encrypted'] ?? null;
            if (!is_string($cipher) || $cipher === '') {
                continue;
            }
            try {
                $data = json_decode($this->encrypter->decrypt($cipher), true);
            } catch (\RuntimeException) {
                continue;
            }
            if (is_array($data) && is_string($data['user'] ?? null) && is_string($data['key'] ?? null) && is_string($data['care_user'] ?? null) && is_string($data['care_password'] ?? null)) {
                return new HeldKey(new Login($data['user'], $data['key']), new Login($data['care_user'], $data['care_password']));
            }
        }
        return null;
    }

    public function lapseApprovals(int $addonRowId, string $at): void
    {
        $this->repo->lapseApprovals($addonRowId, $at);
    }

    public function releaseKey(int $addonRowId): void
    {
        $this->repo->releaseApprovalKeys($addonRowId);
    }

    public function purgeApprovals(string $before): void
    {
        $this->repo->purgeApprovals($before);
    }

    public function lock(int $addonRowId, int $waitSeconds = 0): bool
    {
        return $this->repo->lock('addon:' . $addonRowId, $waitSeconds);
    }

    public function unlock(int $addonRowId): void
    {
        $this->repo->unlock('addon:' . $addonRowId);
    }

    public function lockClient(int $whmcsClientId, int $waitSeconds = 0): bool
    {
        return $this->repo->lock('client:' . $whmcsClientId, $waitSeconds);
    }

    public function unlockClient(int $whmcsClientId): void
    {
        $this->repo->unlock('client:' . $whmcsClientId);
    }

    public function serviceName(int $addonRowId): string
    {
        return ServiceName::of($this->repo, $this->repo->addonRow($addonRowId));
    }

    /** @param array<string, mixed> $row */
    private static function toApproval(array $row): Approval
    {
        $str = static fn (string $key): string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
        return new Approval($str('token_hash'), (int) $str('addon_row_id'), $str('expires_at'), $str('used_at') !== '', $str('key_encrypted') !== '', $str('key_hash') !== '' ? $str('key_hash') : null);
    }

    /** @param array<string, mixed> $row */
    private static function fromRow(array $row): CareSite
    {
        $int = static fn (string $key): ?int => is_numeric($row[$key] ?? null) ? (int) $row[$key] : null;
        $str = static fn (string $key): ?string => is_scalar($row[$key] ?? null) ? (string) $row[$key] : null;
        return new CareSite(
            (int) $int('addon_row_id'),
            (int) $int('service_id'),
            (int) $int('client_id'),
            (string) $str('site_url'),
            State::tryFrom((string) $str('state')) ?? State::Failed,
            $int('dashboard_client_id'),
            $int('dashboard_site_id'),
            (int) $int('retry_count'),
            $str('last_sync'),
            $str('last_error'),
            $str('retry_after'),
            self::logins($str('other_admins')),
        );
    }

    /**
     * The other administrators kept as a JSON list of login names; null when none were recorded or
     * the column holds anything else.
     *
     * @return list<string>|null
     */
    private static function logins(?string $json): ?array
    {
        $list = $json === null ? null : json_decode($json, true);
        if (!is_array($list) || !array_is_list($list)) {
            return null;
        }
        foreach ($list as $login) {
            if (!is_string($login)) {
                return null;
            }
        }
        return $list;
    }
}
