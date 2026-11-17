<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

/**
 * The module's settings, kept in tbladdonmodules beside the rows WHMCS writes for the Configure
 * form. The API key is stored encrypted with WHMCS's own encryption and leaves this class only
 * through apiKey(); nothing else ever shows it.
 */
final class Settings
{
    /** The Daily Digest's choices, as stored under report_by_email. */
    public const DIGEST_OFF = 'off';
    public const DIGEST_NEW = 'new';
    public const DIGEST_DAILY = 'daily';
    private const DIGESTS = [self::DIGEST_OFF, self::DIGEST_NEW, self::DIGEST_DAILY];

    /** @param list<int> $careAddonIds */
    private function __construct(
        public readonly string $dashboardUrl,
        private readonly string $apiKeyEncrypted,
        public readonly string $dailyDigest,
        public readonly bool $dryRun,
        public readonly array $careAddonIds,
        public readonly string $careEmail,
        public readonly int $logRetentionMonths = 0,
    ) {
    }

    public static function load(Repository $repo): self
    {
        $s = $repo->settings();
        // Which add-ons are care is set in one place: their Module Settings in WHMCS.
        $ids = $repo->careAddonIds();
        return new self(
            $s['dashboard_url'] ?? '',
            $s['api_key'] ?? '',
            self::digest($s['report_by_email'] ?? null),
            !array_key_exists('dry_run', $s) || Module::yes($s['dry_run']),
            $ids,
            $s['care_email'] ?? '',
            self::months($s['log_retention_months'] ?? '') ?? 0,
        );
    }

    public function hasApiKey(): bool
    {
        return $this->apiKeyEncrypted !== '';
    }

    public function apiKey(Encrypter $encrypter): string
    {
        try {
            return $encrypter->decrypt($this->apiKeyEncrypted);
        } catch (\RuntimeException) {
            throw new \RuntimeException('The stored API key could not be decrypted; enter it again.');
        }
    }

    /**
     * Checks the module page's form and, when every field is valid, saves it. A blank API key field
     * keeps the stored key; anything in it that has not the form of a MainWP key is a problem. A field
     * the form did not show (the Daily Digest) keeps its value. Live needs an API key and a Care
     * Account Email; an install already Live without one keeps running, only a save is refused. The
     * log's retention is saved from the Log tab, by saveLogRetention(). A test_client_id row stored by
     * an earlier version is left where it is and no longer read.
     * Returns the problems, one per field in plain words; nothing is saved when there is one.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public static function save(Repository $repo, Encrypter $encrypter, array $input): array
    {
        $text = static fn (string $key): string => trim(is_scalar($input[$key] ?? null) ? (string) $input[$key] : '');
        $errors = [];

        $url = rtrim($text('dashboard_url'), '/');
        $urlValid = $url !== '' && filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($url), 'https://');
        if ($url !== '' && !$urlValid) {
            $errors[] = 'MainWP Dashboard URL: enter the dashboard\'s https:// address.';
        }
        // The field hides what is typed: a web address pasted for the key has to be refused here, by name.
        $key = $text('api_key');
        if ($key !== '' && !Engine\MainWpDashboard::isKey($key)) {
            $errors[] = 'API Key: this is not a MainWP API key. On the dashboard, copy it with the button beside "API key (Bearer token)".';
        }
        // The stored key belongs to the stored address: it is not sent to another host unless it is typed again for it.
        $stored = $repo->settings();
        $hasKey = ($stored['api_key'] ?? '') !== '';
        if ($key === '' && $hasKey && $urlValid && self::place($url) !== self::place($stored['dashboard_url'] ?? '')) {
            $errors[] = 'Enter the API key again for the new address.';
        }
        $mode = $text('mode');
        if (!in_array($mode, ['dry_run', 'live'], true)) {
            $errors[] = 'Mode: choose Dry Run or Live.';
        } elseif ($mode === 'live' && $key === '' && !$hasKey) {
            $errors[] = 'Mode: Live needs an API key; enter it, or choose Dry Run.';
        }
        if ($mode === 'live' && $url === '') {
            $errors[] = 'Mode: Live needs the MainWP Dashboard URL; enter it, or choose Dry Run.';
        }
        $digest = $text('report_by_email');
        if (array_key_exists('report_by_email', $input) && !in_array($digest, self::DIGESTS, true)) {
            $errors[] = 'Daily Digest: choose Off, When something is new or Every day.';
        }
        $careEmail = strtolower($text('care_email'));
        if ($careEmail !== '' && filter_var($careEmail, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'Care Account Email: enter an email address such as wordpress@example.com.';
        }
        // An install that was Live before the email was required keeps its other settings saveable; the switch to Live needs it.
        $wasLive = array_key_exists('dry_run', $stored) && !Module::yes($stored['dry_run']);
        if ($mode === 'live' && $careEmail === '' && !$wasLive) {
            $errors[] = 'Mode: Live needs a Care Account Email; enter it, or choose Dry Run.';
        }
        if ($errors !== []) {
            return $errors;
        }

        $save = [
            'dashboard_url' => $url,
            'dry_run' => $mode === 'dry_run' ? 'on' : '',
            'care_email' => $careEmail,
        ];
        if (array_key_exists('report_by_email', $input)) {
            $save['report_by_email'] = $digest;
        }
        if ($key !== '') {
            $save['api_key'] = $encrypter->encrypt($key);
        }
        $repo->saveSettings($save);
        return [];
    }

    /**
     * Saves for how many months the log is kept; 0 (or nothing typed) keeps every entry. Returns the
     * problem in plain words when what was typed is no whole number from 0 to 600, and saves nothing then.
     */
    public static function saveLogRetention(Repository $repo, mixed $typed): ?string
    {
        $months = self::months(is_scalar($typed) ? (string) $typed : 'x');
        if ($months === null) {
            return 'Log Retention: enter a whole number of months. 0 keeps every entry.';
        }
        $repo->saveSettings(['log_retention_months' => (string) $months]);
        return null;
    }

    /**
     * The Daily Digest as stored. The tick box it replaced stored 'on' (ticked) or '' (not ticked)
     * under the same key and started ticked, so those read as When something is new and Off.
     */
    private static function digest(?string $stored): string
    {
        return match (true) {
            $stored === null => self::DIGEST_NEW,
            in_array($stored, self::DIGESTS, true) => $stored,
            default => Module::yes($stored) ? self::DIGEST_NEW : self::DIGEST_OFF,
        };
    }

    /** A whole number of months from 0 to 600 ('' is 0), or null for anything else. */
    private static function months(string $typed): ?int
    {
        $typed = trim($typed);
        if ($typed === '') {
            return 0;
        }
        return ctype_digit($typed) && strlen($typed) <= 3 && (int) $typed <= 600 ? (int) $typed : null;
    }

    /** Where an address sends a request: its host in lower case and its port (443 when none is named), or '' for no address. */
    private static function place(string $url): string
    {
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));
        return $host === '' ? '' : $host . ':' . (parse_url(trim($url), PHP_URL_PORT) ?? 443);
    }
}
