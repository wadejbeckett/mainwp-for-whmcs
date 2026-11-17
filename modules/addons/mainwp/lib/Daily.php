<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

use MainWpForWhmcs\Engine\AddonState;
use MainWpForWhmcs\Engine\LogText;
use MainWpForWhmcs\Engine\ReportLine;
use MainWpForWhmcs\Engine\State;

/**
 * The daily check as WHMCS runs it: every care add-on row WHMCS holds is handed to the engine's
 * check with its standing; the result is kept for the module's page; and a run from the cron sends
 * the daily digest as the Daily Digest setting says, never two on one calendar day. WHMCS's Module
 * Queue adds a line for each care add-on whose command (other than a Create) is still open there,
 * and an Active care add-on set to free in WHMCS is a line too. Lines for staff carry the log's reason for a failed connect, since
 * the stored reason is the sentence the client reads. Log rows since the last cron run that no
 * state would show are lines too.
 *
 * What is news is measured against the last run that came from the cron: a run from the page sends
 * no email, so it does not take the news from the run that does. The first run in another mode
 * (dry run, live) counts everything as new: a line found from the records alone in dry run is not
 * the same finding as the real one.
 */
final class Daily
{
    /** The settings row with the calendar date (Y-m-d, WHMCS's time zone) of the last daily digest WHMCS accepted: never two on one day. */
    private const SENT_ON = 'report_sent_on';
    /** The named lock under which that date and what counts as new are read and written. */
    private const SEND_LOCK = 'report';
    /** Seconds a run waits for another run that is sending the digest. */
    private const SEND_WAIT = 30;
    /** The start of a failed command's line key; the command follows. */
    private const FAILED = 'failed command ';
    /** Characters of an action word WHMCS stored that are shown when it is not one of the known commands. */
    private const MAX_COMMAND = 40;
    /** The settings row that keeps the last result. WHMCS stores a setting HTML-encoded in a TEXT column (65,535 bytes), hence the short keys and the caps. */
    private const RESULT = 'last_check';
    private const MAX_LINES = 100;
    /** What the stored result may take once it is HTML-encoded. */
    private const MAX_BYTES = 60000;
    /** Bytes left for the words on what became of the email, which are written after the lines are chosen. */
    private const EMAIL_ROOM = 4000;
    /** Characters of one line's finding that are kept. */
    private const MAX_PROBLEM = 600;
    /** The keys of this many lines are kept (8 characters each) to tell the next run what is new; with more lines than this, the next run counts none as new. */
    private const MAX_SEEN = 3000;
    private const ENDED = ['Terminated', 'Cancelled', 'Fraud'];
    /** The log actions whose rows can be events for the digest (events()). */
    private const EVENT_ACTIONS = ['key', 'suspend', 'resume', 'remove'];

    /**
     * Runs the check and returns its result as last() gives it. $mayEmail: the run comes from the
     * cron (a run from the page sends no email). $at is the time the run counts as its own.
     *
     * @return array<string, mixed>
     */
    public static function run(Repository $repo, string $actor, bool $mayEmail, ?string $at = null): array
    {
        $at ??= date('Y-m-d H:i:s');
        $settings = Settings::load($repo);
        $stored = self::stored($repo);
        $careRows = $repo->careAddonRows($settings->careAddonIds);
        $states = array_map(self::state(...), $careRows);
        $report = Module::dailyCheck($repo, $settings, null, $actor)->run($states, $actor);
        if ($report->alreadyRunning) {
            return ['running' => true] + ($stored !== null ? self::open($stored) : ['at' => '', 'by' => '', 'dry_run' => $settings->dryRun, 'fault' => null, 'lines' => [], 'more' => 0, 'total' => 0, 'new' => 0, 'email' => '']);
        }
        // The last cron run: its lines' keys, its time, its mode, and whether it had a dashboard fault.
        $base = is_string($stored['seen'] ?? null) && !empty($stored['bdry']) === $settings->dryRun ? $stored : null;
        $seen = $base === null || $base['seen'] === '' ? [] : array_fill_keys(str_split($base['seen'], 8), true);
        $since = is_string($stored['since'] ?? null) && $stored['since'] !== '' ? $stored['since'] : date('Y-m-d H:i:s', (int) strtotime($at) - 86400);
        $fresh = [];
        $open = [];
        $hashes = [];
        // Failed commands first, so that they come first under New and under Still open; then the free add-ons.
        foreach ([...self::failedCommands($repo, $settings, $careRows), ...self::free($repo, $careRows), ...$report->lines(), ...self::events($repo, $since, $at)] as $line) {
            $hash = substr(sha1($line->key()), 0, 8);
            $hashes[] = $hash;
            $new = $base === null || (empty($base['flood']) && !isset($seen[$hash]));
            $row = ['k' => $line->key(), 'a' => $line->addonRowId, 'c' => $line->client, 's' => $line->site, 't' => $line->state, 'p' => self::cut(self::forStaff($repo, $line)), 'n' => $new];
            if ($new) {
                $fresh[] = $row;
            } else {
                $open[] = $row;
            }
        }
        $total = count($hashes);
        $news = $fresh !== [] || ($report->fault !== null && ($base === null || empty($base['bfault'])));
        $result = ['at' => $at, 'by' => $actor, 'dry' => $settings->dryRun, 'fault' => $report->fault !== null ? self::cut($report->fault) : null, 'total' => $total, 'new' => count($fresh), 'email' => ''];
        // Off sends nothing, and the cron's run still moves what the next run is measured against (as
        // the unticked box did): what was found while it was off is not news when it is switched on again.
        $due = $mayEmail && match ($settings->dailyDigest) {
            Settings::DIGEST_DAILY => true,
            Settings::DIGEST_NEW => $news,
            default => false,
        };
        // What the next cron run is measured against. A run from the page leaves it as it was; so does
        // a cron run whose email is not out yet: what nobody was told stays news.
        $now = ['seen' => implode('', array_slice($hashes, 0, self::MAX_SEEN)), 'since' => $at, 'bdry' => $settings->dryRun, 'bfault' => $report->fault !== null, 'flood' => $total > self::MAX_SEEN];
        $rows = [...$fresh, ...$open];
        if ($due) {
            $result = self::sendOnce($repo, $result, $rows, $now, $since, $actor, $settings->dryRun, $news, substr($at, 0, 10));
        } else {
            $result = self::fit($result + ($mayEmail ? $now : self::baseline($stored)), $rows, $now);
            self::keep($repo, $result);
        }
        if ($mayEmail) {
            self::prune($repo, $settings, $actor, $at);
        }
        return self::open($result);
    }

    /**
     * Deletes the log entries older than the months in the settings; nothing at 0, which keeps
     * every entry. Done by the cron's run only, and written to the log when entries went.
     */
    private static function prune(Repository $repo, Settings $settings, string $actor, string $at): void
    {
        $months = $settings->logRetentionMonths;
        if ($months < 1) {
            return;
        }
        // The same day N months back, or that month's last day when it has no such day: "31 March less one
        // month" is 28 February, where PHP's own sum rolls on into March and would delete days too many.
        $run = new \DateTimeImmutable($at);
        $month = $run->modify('first day of -' . $months . ' months');
        $before = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), min((int) $run->format('j'), (int) $month->format('t')));
        $gone = $repo->pruneLog($before->format('Y-m-d H:i:s'));
        if ($gone > 0) {
            $repo->addLog(['created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => 'log', 'target' => 'log', 'outcome' => 'pruned', 'dry_run' => 0, 'detail' => $gone . ' ' . ($gone === 1 ? 'entry' : 'entries') . ' older than ' . $months . ' ' . ($months === 1 ? 'month' : 'months') . ' deleted']);
        }
    }

    /**
     * The last run's result, or null when the check has never run: at, by, dry_run, running (false),
     * fault, lines (key, addon, client, site, state, problem, text, new; what is new first), total
     * (all that was found), new (how many of them), more (found and not kept), email.
     *
     * @return array<string, mixed>|null
     */
    public static function last(Repository $repo): ?array
    {
        $stored = self::stored($repo);
        return $stored !== null ? self::open($stored) : null;
    }

    /** @return array<array-key, mixed>|null the result as it is kept */
    private static function stored(Repository $repo): ?array
    {
        $stored = json_decode($repo->settings()[self::RESULT] ?? '', true);
        return is_array($stored) && is_string($stored['at'] ?? null) ? $stored : null;
    }

    /**
     * What the next cron run is measured against, as this stored result holds it.
     *
     * @param array<array-key, mixed>|null $stored
     * @return array<array-key, mixed>
     */
    private static function baseline(?array $stored): array
    {
        return array_intersect_key($stored ?? [], ['seen' => 1, 'since' => 1, 'bdry' => 1, 'bfault' => 1, 'flood' => 1]);
    }

    /**
     * The result with as many lines as the settings row holds, what is new first: measured with the
     * baseline it may end with, and with room for what became of the email.
     *
     * @param array<array-key, mixed> $result
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $now
     * @return array<array-key, mixed>
     */
    private static function fit(array $result, array $rows, array $now): array
    {
        $result['lines'] = [];
        $size = max(self::size($result), self::size($now + $result)) + self::EMAIL_ROOM;
        foreach ($rows as $row) {
            $size += self::size($row) + 1;
            if (count($result['lines']) >= self::MAX_LINES || $size > self::MAX_BYTES) {
                break;
            }
            $result['lines'][] = $row;
        }
        return $result;
    }

    /** @param array<array-key, mixed> $result */
    private static function keep(Repository $repo, array $result): void
    {
        $repo->saveSettings([self::RESULT => self::json($result)]);
    }

    /** @param array<array-key, mixed> $value */
    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /** @param array<array-key, mixed> $value the bytes it takes in the settings row, HTML-encoded as the repository stores it */
    private static function size(array $value): int
    {
        return strlen(htmlspecialchars(self::json($value), ENT_QUOTES, 'UTF-8'));
    }

    /** A finding cut to what one line may take, on a whole character. */
    private static function cut(string $text): string
    {
        return preg_match('/^.{' . (self::MAX_PROBLEM + 1) . '}/us', $text) === 1 && preg_match('/^.{' . self::MAX_PROBLEM . '}/us', $text, $m) === 1 ? $m[0] . '…' : $text;
    }

    /**
     * @param array<array-key, mixed> $stored
     * @return array<string, mixed>
     */
    private static function open(array $stored): array
    {
        $lines = [];
        foreach (is_array($stored['lines'] ?? null) ? $stored['lines'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $text = static fn (string $key): string => is_scalar($line[$key] ?? null) ? (string) $line[$key] : '';
            $row = new ReportLine(is_int($line['a'] ?? null) ? $line['a'] : null, $text('c'), $text('s'), $text('t'), $text('p'));
            $lines[] = ['key' => $text('k'), 'addon' => $row->addonRowId, 'client' => $row->client, 'site' => $row->site, 'state' => $row->state, 'problem' => $row->problem, 'text' => $row->text(), 'new' => !empty($line['n'])];
        }
        $total = is_int($stored['total'] ?? null) ? max($stored['total'], count($lines)) : count($lines);
        return [
            'at' => (string) $stored['at'],
            'by' => is_string($stored['by'] ?? null) ? $stored['by'] : '',
            'dry_run' => !empty($stored['dry']),
            'running' => false,
            'fault' => is_string($stored['fault'] ?? null) ? $stored['fault'] : null,
            'lines' => $lines,
            'total' => $total,
            'new' => is_int($stored['new'] ?? null) ? $stored['new'] : count(array_filter($lines, static fn (array $line): bool => $line['new'])),
            'more' => $total - count($lines),
            'email' => is_string($stored['email'] ?? null) ? $stored['email'] : '',
        ];
    }

    /** How a care add-on row stands for the daily check; an add-on or a service that has ended counts as ended even when the other is only suspended. */
    private static function state(AddonRow $row): AddonState
    {
        $standing = match (true) {
            in_array($row->status, self::ENDED, true), in_array($row->serviceStatus, self::ENDED, true) => AddonState::ENDED,
            $row->suspended() => AddonState::SUSPENDED,
            $row->active() => AddonState::ACTIVE,
            default => AddonState::PENDING,
        };
        return new AddonState($row->id, $row->serviceId, $row->client(), $row->siteUrl(), $standing, $row->billed(), (string) $row->notActiveBecause());
    }

    /**
     * For a connect that failed, staff read the log's reason: the reason kept with the care site is
     * the sentence the client reads, which for a fault on our side names no cause.
     */
    private static function forStaff(Repository $repo, ReportLine $line): string
    {
        if ($line->addonRowId === null || $line->kind !== 'not connected') {
            return $line->problem;
        }
        // The newer of the last failed connect and the last failed mend. Where the retries stand is
        // said by the line itself; the row's own note on that is of the day it was written.
        $target = 'add-on ' . $line->addonRowId;
        $connect = $repo->lastLogFor('connect', $target, 'failed');
        $mend = $repo->lastLogFor('mend', $target, 'failed');
        $at = static fn (?array $row): array => [(string) ($row['created_at'] ?? ''), (int) ($row['id'] ?? 0)];
        $row = $mend !== null && ($connect === null || $at($mend) > $at($connect)) ? $mend : $connect;
        $detail = trim((string) preg_replace('/ \(on the retry list: [^)]*\)/', '', (string) ($row['detail'] ?? '')));
        return $detail === '' || str_contains($line->problem, $detail) ? $line->problem : $line->problem . ' Log: ' . $detail;
    }

    /**
     * Log rows after the last cron run, up to the time this run counts as its own, that no state shows: a key that could not be deleted on a site
     * for a week, and a service event that did not say which service it was for. An event that
     * failed for a known add-on shows as a mismatch of its site instead.
     *
     * @return list<ReportLine>
     */
    private static function events(Repository $repo, string $since, string $until): array
    {
        $lines = [];
        foreach ($repo->logSince($since, self::EVENT_ACTIONS) as $row) {
            if ((string) ($row['created_at'] ?? '') > $until) {
                continue; // written while this run was under way: the next run's, so that it is read once
            }
            $action = (string) ($row['action'] ?? '');
            $outcome = (string) ($row['outcome'] ?? '');
            $target = (string) ($row['target'] ?? '');
            $givenUp = $action === 'key' && $outcome === 'given up';
            $unheard = in_array($action, ['suspend', 'resume', 'remove'], true) && $outcome === 'failed' && !str_starts_with($target, 'add-on ');
            if ($givenUp || $unheard) {
                $lines[] = new ReportLine(null, '', $target, 'from the log', (string) ($row['detail'] ?? ''), 'log ' . (string) ($row['created_at'] ?? '') . ' ' . $action);
            }
        }
        return $lines;
    }

    /**
     * Sends the digest unless one already went out on this calendar day, and keeps the result. The
     * date of the last email WHMCS accepted, and what counts as new, are read and written under a
     * lock of their own: another cron run may have sent, and moved what counts as new, while this
     * one was checking, so both are read again here and not taken from the start of the run. The
     * date is written only once WHMCS accepted the email, so a failed send may be tried again the
     * same day. A run that may not send leaves what counts as new as it is stored, so that what it
     * found is still new for the next run.
     *
     * @param array<string, mixed> $result without its lines and what counts as new
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $now what counts as new once this run's email is out
     * @return array<array-key, mixed> the result as it was kept
     */
    private static function sendOnce(Repository $repo, array $result, array $rows, array $now, string $since, string $actor, bool $dryRun, bool $news, string $today): array
    {
        $skip = static function (string $said) use ($repo, $actor, $dryRun): string {
            $repo->addLog(['created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => 'daily check', 'target' => 'daily digest', 'outcome' => 'skipped', 'dry_run' => $dryRun ? 1 : 0, 'detail' => $said]);
            return $said;
        };
        if (!$repo->lock(self::SEND_LOCK, self::SEND_WAIT)) {
            // The run that holds the lock keeps its own result of the same night; keeping this one
            // without the lock could write back what counted as new before that run's email. Whether
            // that run's email goes out is not known here, so the words do not say that one was sent.
            $result['email'] = $skip('not sent: another run is sending today\'s digest');
            return self::fit($result, $rows, $now);
        }
        try {
            $was = self::baseline(self::stored($repo));
            // Before any email has gone out there is no time to read the log from: this run's stays, until one does.
            $was['since'] ??= $since;
            $result = self::fit($result + $was, $rows, $now);
            // Kept before the email goes out: a result that could not be kept must not be mailed day after day.
            self::keep($repo, $result);
            if (($repo->settings()[self::SENT_ON] ?? '') === $today) {
                $result['email'] = $skip('not sent: one was already sent today');
            } else {
                $result['email'] = self::cut(self::send($repo, self::open($result), $actor, $dryRun, $news));
                if (str_starts_with($result['email'], 'sent')) {
                    $repo->saveSettings([self::SENT_ON => $today]);
                    $result = $now + $result;
                }
            }
            self::keep($repo, $result);
            return $result;
        } finally {
            $repo->unlock(self::SEND_LOCK);
        }
    }

    /**
     * An Active care add-on set to free in WHMCS: one line each, with the care site's
     * state, or "Active in WHMCS" when it has none or a removed one. Care goes on as for any other.
     *
     * @param list<AddonRow> $careRows
     * @return list<ReportLine>
     */
    private static function free(Repository $repo, array $careRows): array
    {
        $lines = [];
        foreach ($careRows as $addon) {
            if (!$addon->free || $addon->status !== 'Active') {
                continue;
            }
            $care = $repo->careSite($addon->id);
            $site = $care['site_url'] ?? null;
            $state = $care['state'] ?? null;
            $lines[] = new ReportLine(
                $addon->id,
                $addon->clientName . ' (client ' . $addon->clientId . ')',
                is_string($site) && $site !== '' ? $site : $addon->siteUrl(),
                is_string($state) && $state !== '' && $state !== State::Removed->value ? ucfirst($state) : 'Active in WHMCS',
                'set to free in WHMCS',
                'free',
            );
        }
        return $lines;
    }

    /**
     * The open rows of WHMCS's Module Queue for a care add-on's commands other than a Create:
     * one line each, named by WHMCS's own button word for Suspend, Unsuspend and Terminate (in any
     * letter case) and else by the word WHMCS stored, with the error WHMCS kept (the text this
     * module returned to it), how often it was tried and when last. Two open rows of one command on
     * one add-on are one finding: the later row's words are kept.
     *
     * @param list<AddonRow> $careRows
     * @return list<ReportLine>
     */
    private static function failedCommands(Repository $repo, Settings $settings, array $careRows): array
    {
        $rows = [];
        foreach ($careRows as $row) {
            $rows[$row->id] = $row;
        }
        try {
            $queue = $repo->failedAddonCommands($settings->careAddonIds);
        } catch (\Throwable $e) {
            // One more source of lines: a fault in reading it costs one line, not the night's check.
            return [new ReportLine(null, '', 'Module Queue', 'in WHMCS', 'could not be read, so no failed command is listed: ' . LogText::clean(trim((string) preg_replace('/\s+/u', ' ', $e->getMessage()))), 'module queue')];
        }
        $lines = [];
        foreach ($queue as $queued) {
            $addon = $rows[$queued['addon_row_id']] ?? null;
            if ($addon === null) {
                continue;
            }
            $command = self::command($queued['action']);
            $site = $repo->careSite($addon->id)['site_url'] ?? null;
            // WHMCS may keep its own text there as well as this module's answer: no file path is shown.
            $error = LogText::clean(trim((string) preg_replace('/\s+/u', ' ', strip_tags($queued['error']))));
            $error = $error === '' ? '.' : ': ' . $error . (preg_match('/[.!?]$/', $error) === 1 ? '' : '.');
            $tries = $queued['retries'] + 1;
            $when = $queued['last_attempt'] !== '' && !str_starts_with($queued['last_attempt'], '0000') ? ', last on ' . substr($queued['last_attempt'], 0, 10) : '';
            $line = new ReportLine($addon->id, $addon->clientName . ' (client ' . $addon->clientId . ')', is_string($site) && $site !== '' ? $site : $addon->siteUrl(), $addon->status . ' in WHMCS', $command . ' failed' . $error . ' Tried ' . $tries . ' ' . ($tries === 1 ? 'time' : 'times') . $when . '.', self::FAILED . $command);
            $lines[$line->key()] = $line;
        }
        return array_values($lines);
    }

    /** A Module Queue action word as a line names it: WHMCS's button word for a known command, else the word as stored, cleaned and cut. */
    private static function command(string $action): string
    {
        foreach (Repository::QUEUED_COMMANDS as $stored => $button) {
            if (strcasecmp($action, $stored) === 0) {
                return $button;
            }
        }
        $word = LogText::clean(trim((string) preg_replace('/\s+/u', ' ', strip_tags($action))));
        $word = preg_match('/^.{0,' . self::MAX_COMMAND . '}/us', $word, $m) === 1 ? trim($m[0]) : '';
        return $word !== '' ? $word : 'Command';
    }

    /**
     * Sends the digest with WHMCS's own SendAdminEmail, type "system": WHMCS documents no way to
     * mail an address of a module's choosing, so it goes to the administrators whose role receives
     * system emails. Returns what happened, in a few words, for the page and the log.
     *
     * @param array<string, mixed> $result
     */
    private static function send(Repository $repo, array $result, string $actor, bool $dryRun, bool $news): string
    {
        $to = $repo->systemEmailAdmins();
        if ($to === []) {
            $said = 'not sent: no administrator\'s role receives system emails';
        } else {
            $total = (int) $result['total'];
            // A night with nothing new is mailed only on Every day: it says how many are still open.
            $parts = array_merge(is_string($result['fault']) ? ['dashboard fault'] : [], $total > 0 ? [$news ? (int) $result['new'] . ' new of ' . $total . ' to report' : $total . ' still open'] : []);
            $subject = Module::DISPLAY_NAME . ($dryRun ? ' (dry run)' : '') . ': ' . ($parts === [] ? 'nothing to report' : implode('; ', $parts));
            $body = $parts === [] ? '<p>Nothing to report today.</p>' : self::body($result, $dryRun, $repo);
            $answer = \localAPI('SendAdminEmail', ['type' => 'system', 'customsubject' => $subject, 'custommessage' => $body]);
            $said = ($answer['result'] ?? '') === 'success' ? 'sent to ' . implode(', ', $to) : 'not sent: ' . (is_string($answer['message'] ?? null) ? $answer['message'] : 'WHMCS gave no reason');
        }
        $repo->addLog(['created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => 'daily check', 'target' => 'daily digest', 'outcome' => str_starts_with($said, 'sent') ? 'done' : 'failed', 'dry_run' => $dryRun ? 1 : 0, 'detail' => $said]);
        return $said;
    }

    /**
     * The email's body: escaped text in plain HTML. Braces are written as entities, so that nothing
     * a client typed (a name, an address) can be read as template code if WHMCS parses the body.
     * Every paragraph and list item ends its line, because WHMCS makes the plain-text version by
     * taking the tags away; and a line names its add-on, so that two add-ons on one site are told apart.
     *
     * @param array<string, mixed> $result
     */
    private static function body(array $result, bool $dryRun, Repository $repo): string
    {
        $safe = static fn (string $text): string => str_replace(['{', '}'], ['&#123;', '&#125;'], htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $list = static function (string $heading, array $lines) use ($safe): string {
            return $lines === [] ? '' : '<p><strong>' . $heading . "</strong></p>\n<ul>\n" . implode('', array_map(static fn (array $line): string => '<li>' . $safe((string) $line['text']) . (is_int($line['addon'] ?? null) ? ' (add-on ' . $line['addon'] . ')' : '') . "</li>\n", $lines)) . "</ul>\n";
        };
        $lines = is_array($result['lines']) ? $result['lines'] : [];
        $new = array_values(array_filter($lines, static fn (array $line): bool => $line['new']));
        $old = array_values(array_filter($lines, static fn (array $line): bool => !$line['new']));
        $html = (int) $result['total'] > 0 ? '<p>The daily check of ' . Module::DISPLAY_NAME . ' found ' . (int) $result['total'] . ' to report, ' . (int) $result['new'] . " of them new.</p>\n" : '';
        $html .= $dryRun ? "<p>Dry run: nothing was sent to the dashboard or to any site.</p>\n" : '';
        $html .= is_string($result['fault']) ? '<p><strong>Dashboard fault:</strong> ' . $safe($result['fault']) . "</p>\n" : '';
        $html .= $list('New', $new) . $list('Still open', $old);
        // More than the result can keep: the page holds the same lines as this email and no more, so it is not called the whole list.
        $html .= (int) $result['more'] > 0
            ? '<p>And ' . (int) $result['more'] . " more, not listed here.</p>\n<p>The module's page in the WHMCS admin area, under Addons, has the same lines and no more.</p>"
            : '<p>The whole list is on the module\'s page in the WHMCS admin area, under Addons.</p>';
        if (array_filter($lines, static fn (array $line): bool => str_starts_with((string) $line['key'], self::FAILED)) === []) {
            return $html;
        }
        // One link to WHMCS's Module Queue, where a failed command is retried; in words without a System URL.
        $url = $repo->systemUrl();
        $queue = $url . '/' . $repo->adminFolder() . '/modulequeue.php';
        return $html . "\n" . ($url === '' ? '<p>To retry a failed command, open ' . $safe('Utilities > Module Queue') . ' in WHMCS.</p>' : '<p>To retry a failed command, open the Module Queue in WHMCS: <a href="' . $safe($queue) . '">' . $safe($queue) . '</a></p>');
    }
}
