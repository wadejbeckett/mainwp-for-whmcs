<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

/** DDL for the module's three tables (MySQL/MariaDB) and the versioned steps that bring an older install, its tables and its settings, up to date. */
final class Schema
{
    public const SITES = 'mod_mainwp_sites';
    public const TOKENS = 'mod_mainwp_tokens';
    public const LOG = 'mod_mainwp_log';
    public const TABLES = [self::SITES, self::TOKENS, self::LOG];

    private const CREATE_SITES = <<<'SQL'
CREATE TABLE IF NOT EXISTS mod_mainwp_sites (
  addon_row_id INT NOT NULL PRIMARY KEY,
  service_id INT NOT NULL,
  client_id INT NOT NULL,
  site_url VARCHAR(255) NOT NULL,
  dashboard_client_id INT NULL,
  dashboard_site_id INT NULL,
  state VARCHAR(16) NOT NULL,
  retry_count INT NOT NULL DEFAULT 0,
  last_sync DATETIME NULL,
  last_error TEXT NULL,
  retry_after DATETIME NULL,
  login_encrypted TEXT NULL,
  other_admins TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY idx_service (service_id),
  KEY idx_client (client_id)
) DEFAULT CHARSET=utf8mb4
SQL;

    private const CREATE_TOKENS = <<<'SQL'
CREATE TABLE IF NOT EXISTS mod_mainwp_tokens (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_hash CHAR(64) NOT NULL,
  addon_row_id INT NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  key_hash CHAR(64) NULL,
  key_encrypted TEXT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY idx_token (token_hash),
  KEY idx_addon (addon_row_id)
) DEFAULT CHARSET=utf8mb4
SQL;

    private const CREATE_LOG = <<<'SQL'
CREATE TABLE IF NOT EXISTS mod_mainwp_log (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  actor VARCHAR(64) NOT NULL,
  action VARCHAR(32) NOT NULL,
  target VARCHAR(255) NOT NULL,
  outcome VARCHAR(16) NOT NULL,
  dry_run TINYINT(1) NOT NULL,
  detail TEXT NOT NULL,
  KEY idx_created (created_at)
) DEFAULT CHARSET=utf8mb4
SQL;

    public static function install(Repository $repo): void
    {
        foreach ([self::CREATE_SITES, self::CREATE_TOKENS, self::CREATE_LOG] as $sql) {
            $repo->execute($sql);
        }
    }

    public static function uninstall(Repository $repo): void
    {
        foreach (self::TABLES as $table) {
            $repo->execute('DROP TABLE IF EXISTS ' . $table);
        }
    }

    /**
     * The steps that bring an install at an older version up to date, in order. Each has the version
     * that introduced it and the sentence the activity log gets, and either statements, optionally
     * with an "unless" column guard so a repeated upgrade adds nothing twice, or a change that
     * returns whether it changed anything, so that a repeated upgrade does nothing twice.
     *
     * @return list<array{version: string, sentence: string, statements?: list<string>, unless?: array{string, string}, change?: \Closure(Repository): bool}>
     */
    public static function upgradeSteps(): array
    {
        return [
            ['version' => '0.2.0', 'sentence' => 'the setting Care Account Email Domain replaced by Care Account Email', 'change' => self::careEmailFromDomain(...)],
            ['version' => '0.2.0', 'sentence' => 'column other_admins added to mod_mainwp_sites', 'statements' => ['ALTER TABLE ' . self::SITES . ' ADD COLUMN other_admins TEXT NULL'], 'unless' => [self::SITES, 'other_admins']],
        ];
    }

    /**
     * Version 0.2.0 gives the care account one address instead of an address per site under a
     * domain: a domain that was set becomes wordpress@<domain>, unless a Care Account Email is
     * already saved, and the domain's row is deleted either way.
     */
    private static function careEmailFromDomain(Repository $repo): bool
    {
        $settings = $repo->settings();
        if (!array_key_exists('care_email_domain', $settings)) {
            return false;
        }
        $domain = strtolower(trim($settings['care_email_domain']));
        if ($domain !== '' && ($settings['care_email'] ?? '') === '') {
            $repo->saveSettings(['care_email' => 'wordpress@' . $domain]);
        }
        $repo->deleteSetting('care_email_domain');
        return true;
    }
}
