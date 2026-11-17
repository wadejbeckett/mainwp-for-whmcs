<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Admin;

use MainWpForWhmcs\Daily;
use MainWpForWhmcs\Encrypter;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Repository;
use MainWpForWhmcs\Settings;
use MainWpForWhmcs\StaffConnect;

/**
 * The module's admin page, in three tabs: Sites (every site with its status, the daily check and
 * the form that connects a site), Settings, and Log. Each tab and each view of a list is a plain
 * address. Only an admin whose role is ticked under Configure sees the page (WHMCS checks this too;
 * the page checks again so a direct request cannot read or save anything).
 */
final class Page
{
    /** The tabs, by the word in the address; the first is the one the page opens on. */
    private const TABS = ['sites', 'settings', 'log'];
    private const FIELDS = ['dashboard_url', 'report_by_email', 'mode', 'care_email'];
    /** The log's headings that sort, by their word in the address, and the column each sorts by. */
    private const LOG_COLUMNS = ['time' => 'created_at', 'by' => 'actor', 'action' => 'action', 'on' => 'target', 'outcome' => 'outcome'];

    /**
     * @param array<string, mixed> $post
     * @param array<array-key, mixed> $get the request's query: "tab" picks the tab; a list reads its order, page and filter from it
     */
    public static function render(Repository $repo, Encrypter $encrypter, ?int $adminId, string $moduleLink, string $method, array $post, string $token, array $get = []): string
    {
        $stored = $repo->settings();
        if (!self::allowed($stored['access'] ?? '', $adminId === null ? null : $repo->adminRoleId($adminId))) {
            return Template::render('admin.tpl', ['denied' => true, 'name' => Module::DISPLAY_NAME]);
        }
        // Only a known word picks a tab; anything else opens the first, and is never shown.
        $tab = in_array($get['tab'] ?? null, self::TABS, true) ? $get['tab'] : self::TABS[0];
        $actor = 'admin:' . (int) $adminId;
        $action = $method === 'POST' ? (is_string($post['action'] ?? null) ? $post['action'] : 'save') : '';
        $text = static fn (array $from, string $key): string => is_scalar($from[$key] ?? null) ? trim((string) $from[$key]) : '';
        $errors = [];
        $alerts = [];
        $typed = null;
        $connectTyped = null;
        $confirmClear = false;
        $retentionTyped = null;

        if ($action === 'daily_check') {
            // The run may outlast the browser's patience (a retry logs in to a site): it is let finish either way.
            @set_time_limit(0);
            ignore_user_abort(true);
            $result = Daily::run($repo, $actor, false);
            $found = count(is_array($result['lines']) ? $result['lines'] : []) + (int) $result['more'];
            $alerts[] = !empty($result['running'])
                ? ['class' => 'info', 'text' => 'A check is already running.']
                : ['class' => 'success', 'text' => 'Checked: ' . $found . ' ' . ($found === 1 ? 'issue' : 'issues') . '.'];
        } elseif ($action === 'connect') {
            $id = ctype_digit($text($get, 'connect')) ? (int) $text($get, 'connect') : 0;
            $row = SitesList::connectable($repo, Settings::load($repo), $id);
            if ($row === null) {
                $alerts[] = ['class' => 'danger', 'text' => 'This site cannot be connected now.'];
                unset($get['connect']);
            } else {
                @set_time_limit(0);
                ignore_user_abort(true);
                $outcome = StaffConnect::attempt($repo, $row->serviceId, $adminId, $id, ['address' => $text($post, 'address'), 'username' => $text($post, 'site_username'), 'password' => is_scalar($post['site_password'] ?? null) ? (string) $post['site_password'] : '']);
                if ($outcome === null) {
                    $alerts[] = ['class' => 'danger', 'text' => 'Enter the administrator username and password.'];
                    $connectTyped = $post;
                } elseif ($outcome->ok) {
                    $alerts[] = ['class' => 'success', 'text' => $outcome->message];
                    unset($get['connect']);
                } else {
                    $alerts[] = ['class' => 'danger', 'text' => $outcome->message];
                    $connectTyped = $post;
                }
            }
        } elseif ($action === 'save_retention') {
            $problem = Settings::saveLogRetention($repo, $post['log_retention_months'] ?? '');
            $alerts[] = $problem === null ? ['class' => 'success', 'text' => Listing::word('changesuccess', 'Changes Saved Successfully!')] : ['class' => 'danger', 'text' => $problem];
            $retentionTyped = $problem === null ? null : (is_scalar($post['log_retention_months'] ?? null) ? trim((string) $post['log_retention_months']) : '');
        } elseif ($action === 'clear_log') {
            $confirmClear = true;
        } elseif ($action === 'clear_log_confirmed') {
            $gone = $repo->clearLog();
            $repo->addLog(['created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => 'log', 'target' => 'log', 'outcome' => 'cleared', 'dry_run' => 0, 'detail' => $gone . ' ' . ($gone === 1 ? 'entry' : 'entries') . ' deleted']);
            $alerts[] = ['class' => 'success', 'text' => 'Log cleared.'];
        } elseif ($action !== '') {
            $errors = Settings::save($repo, $encrypter, $post);
            if ($errors === []) {
                $saved = Settings::load($repo);
                $repo->addLog(['created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => 'settings', 'target' => 'module settings', 'outcome' => 'done', 'dry_run' => 0, 'detail' => 'saved; mode ' . ($saved->dryRun ? 'dry run' : 'live')]);
                $alerts[] = ['class' => 'success', 'text' => Listing::word('changesuccess', 'Changes Saved Successfully!')];
                // Save Changes and Test Connection both save the form and then prove the address and the key, in
                // either mode: the test reads two numbers. So what is tested is always what the form shows.
                $alerts[] = $saved->dashboardUrl !== '' && $saved->hasApiKey()
                    ? self::tested($repo, $encrypter, $actor)
                    : ['class' => 'info', 'text' => 'Enter the MainWP Dashboard URL and an API key to connect.'];
            } else {
                $typed = $post;
            }
        }

        $settings = Settings::load($repo);
        // While the module is being set up (dry run) this is said on the Settings tab only, where it is mended; once live it affects clients, so every tab says it.
        $off = match (true) {
            $settings->dryRun && $tab !== 'settings' => '',
            $settings->careEmail === '' => 'No care account is made on clients\' sites, and clients can connect with a username and password only, until a Care Account Email is entered under Settings.',
            Module::returnAddress($repo) === '' => 'Clients can connect with a username and password only, until WHMCS\'s System URL (General Settings) starts with https://.',
            default => '',
        };
        if ($off !== '') {
            $alerts[] = ['class' => 'warning', 'text' => $off];
        }
        $vars = [
            'denied' => false,
            'name' => Module::DISPLAY_NAME,
            'dryRun' => $settings->dryRun,
            'tab' => $tab,
            'tabLinks' => array_combine(self::TABS, array_map(static fn (string $tab): string => $moduleLink . '&tab=' . $tab, self::TABS)),
            'token' => $token,
            'alerts' => $alerts,
            'errors' => $errors,
            'connect' => null,
            'words' => [
                'searchFilter' => Listing::word('searchfilter', 'Search/Filter'),
                'search' => Listing::word('search', 'Search'),
                'cancel' => Listing::word('cancel', 'Cancel'),
                'any' => Listing::word('any', 'Any'),
                'never' => Listing::word('never', 'Never'),
                'noRecords' => Listing::word('norecordsfound', 'No Records Found'),
            ],
        ];
        return Template::render('admin.tpl', match ($tab) {
            'settings' => self::settingsTab($repo, $settings, $typed, $moduleLink, $get),
            'log' => self::logTab($repo, $settings, $moduleLink, $get, $confirmClear, $retentionTyped),
            default => self::sitesTab($repo, $settings, $moduleLink, $get, $connectTyped),
        } + $vars);
    }

    /** @return array{class: string, text: string} the dashboard's answer to the saved address and key */
    private static function tested(Repository $repo, Encrypter $encrypter, string $actor): array
    {
        $outcome = Module::testConnection($repo, Settings::load($repo), $encrypter, $actor);
        return ['class' => $outcome->ok ? 'success' : 'danger', 'text' => $outcome->message];
    }

    /**
     * @param array<array-key, mixed> $get
     * @param array<string, mixed>|null $typed what was typed into a connect form that did not go through
     * @return array<string, mixed>
     */
    private static function sitesTab(Repository $repo, Settings $settings, string $moduleLink, array $get, ?array $typed): array
    {
        $text = static fn (string $key): string => is_scalar($get[$key] ?? null) ? trim((string) $get[$key]) : '';
        $status = isset(Status::ALL[$text('status')]) ? $text('status') : '';
        $list = Listing::fromRequest($get, SitesList::SORTABLE, SitesList::BY_ISSUE, 'asc', $repo->recordsPerPage(), ['search' => $text('search'), 'status' => $status], $moduleLink, 'sites');
        $connect = null;
        $id = ctype_digit($text('connect')) ? (int) $text('connect') : 0;
        if ($id > 0 && ($row = SitesList::connectable($repo, $settings, $id)) !== null) {
            $typedText = static fn (string $key): string => is_scalar($typed[$key] ?? null) ? trim((string) $typed[$key]) : '';
            $connect = [
                'client' => $row->clientName,
                'address' => $typed !== null ? $typedText('address') : (string) ($repo->careSite($id)['site_url'] ?? ''),
                'username' => $typedText('site_username'),
                'action' => $list->link(['connect' => $id]),
                'cancel' => $list->link(),
            ];
        }
        $all = SitesList::rows($repo, $settings);
        $rows = SitesList::sorted(SitesList::narrowed($all, $text('search'), $status), $list->orderBy, $list->order);
        $shown = [];
        foreach (array_slice($rows, $list->offset(count($rows)), $list->perPage) as $row) {
            $words = Status::ALL[$row['status']] ?? ['label' => '', 'class' => ''];
            $shown[] = [
                'site' => $row['site'],
                'siteLink' => $row['clientId'] !== null && $row['service'] !== null ? 'clientsservices.php?userid=' . $row['clientId'] . '&id=' . $row['service'] : '',
                'client' => $row['client'],
                'clientLink' => $row['clientId'] !== null ? 'clientssummary.php?userid=' . $row['clientId'] : '',
                'status' => $words['label'],
                'statusClass' => $words['class'],
                // MainWP leaves the cell empty for a site that never synced; here that would look like a fault.
                'lastSync' => $row['lastSync'] !== '' ? Listing::date($row['lastSync']) : ($row['addon'] !== null ? Listing::word('never', 'Never') : ''),
                'issue' => $row['issue'],
                'connectLink' => $row['connectable'] && $row['addon'] !== null ? $list->link(['connect' => $row['addon']]) : '',
                'dashboardLink' => $row['dashboardSite'] !== null && $settings->dashboardUrl !== '' ? $settings->dashboardUrl . '/wp-admin/admin.php?page=managesites&dashboard=' . $row['dashboardSite'] : '',
            ];
        }
        $last = Daily::last($repo);
        $options = [];
        foreach (Status::ALL as $value => $words) {
            $options[] = ['value' => $value, 'label' => $words['label'], 'selected' => $value === $status];
        }
        return [
            'connect' => $connect,
            'check' => ['at' => Listing::date((string) ($last['at'] ?? '')), 'dryRun' => !empty($last['dry_run']), 'fault' => (string) ($last['fault'] ?? '')],
            // Where the list's own buttons post to: the list as it is shown, so a check comes back to the same view.
            'listLink' => $list->link(['page' => $list->pageOf(count($rows))]),
            'dashboardUrl' => $settings->dashboardUrl,
            'others' => SitesList::others($repo),
            'filter' => self::filter($list, ['search', 'status'], ['search' => $text('search'), 'status' => $status], $options),
            'list' => $list->model(count($rows)),
            'sites' => $shown,
            'more' => SitesList::more($repo),
            'statusKey' => array_values(Status::ALL),
        ];
    }

    /**
     * @param array<array-key, mixed> $get
     * @param array<string, mixed>|null $typed the form as it was sent, when it was not saved
     * @return array<string, mixed>
     */
    private static function settingsTab(Repository $repo, Settings $settings, ?array $typed, string $moduleLink, array $get): array
    {
        $form = [
            'dashboard_url' => $settings->dashboardUrl,
            'report_by_email' => $settings->dailyDigest,
            'mode' => $settings->dryRun ? 'dry_run' : 'live',
            'care_email' => $settings->careEmail,
        ];
        if ($typed !== null) {
            foreach (self::FIELDS as $field) {
                $form[$field] = array_key_exists($field, $typed) ? (is_scalar($typed[$field]) ? trim((string) $typed[$field]) : '') : $form[$field];
            }
        }
        // The care add-ons as WHMCS has them, by name, each a link to its page in WHMCS: read, not ticked.
        $addons = [];
        foreach ($repo->productAddons() as $id => $name) {
            if (in_array($id, $settings->careAddonIds, true)) {
                $addons[] = ['name' => $name, 'link' => 'configaddons.php?action=manage&id=' . $id];
            }
        }
        $to = $repo->systemEmailAdmins();
        $saved = $settings->hasApiKey();
        return [
            'form' => $form,
            // Open to be typed into when no key is saved, when Change was pressed, and when a form with a key in it was not saved.
            'key' => ['saved' => $saved, 'open' => !$saved || ($get['key'] ?? null) === 'change' || ($typed !== null && array_key_exists('api_key', $typed)), 'changeLink' => $moduleLink . '&tab=settings&key=change'],
            'addons' => $addons,
            'reportTo' => $to === [] ? 'No admin role receives WHMCS system emails, so it cannot be sent.' : 'Sent to ' . implode(', ', $to) . '.',
        ];
    }

    /**
     * @param array<array-key, mixed> $get
     * @return array<string, mixed>
     */
    private static function logTab(Repository $repo, Settings $settings, string $moduleLink, array $get, bool $confirmClear, ?string $retentionTyped): array
    {
        $text = static fn (string $key): string => is_scalar($get[$key] ?? null) ? trim((string) $get[$key]) : '';
        $outcomes = $repo->logOutcomes();
        $outcome = in_array($text('outcome'), $outcomes, true) ? $text('outcome') : '';
        $filter = ['search' => $text('search'), 'outcome' => $outcome];
        $list = Listing::fromRequest($get, array_keys(self::LOG_COLUMNS), 'time', 'desc', $repo->recordsPerPage(), $filter, $moduleLink, 'log');
        $total = $repo->logCount($filter);
        $log = [];
        foreach ($repo->logPage($filter, self::LOG_COLUMNS[$list->orderBy], $list->order, $list->offset($total), $list->perPage) as $row) {
            $log[] = [
                'time' => Listing::date((string) ($row['created_at'] ?? '')),
                'actor' => (string) ($row['actor'] ?? ''),
                'action' => ucfirst((string) ($row['action'] ?? '')),
                'target' => (string) ($row['target'] ?? ''),
                'outcome' => ucfirst((string) ($row['outcome'] ?? '')) . (!empty($row['dry_run']) ? ' (dry run)' : ''),
                'detail' => (string) ($row['detail'] ?? ''),
            ];
        }
        return [
            'filter' => self::filter($list, ['search', 'outcome'], $filter, array_map(static fn (string $value): array => ['value' => $value, 'label' => ucfirst($value), 'selected' => $value === $outcome], $outcomes)),
            'list' => $list->model($total),
            'log' => $log,
            'listLink' => $list->link(['page' => $list->pageOf($total)]),
            'retention' => $retentionTyped ?? (string) $settings->logRetentionMonths,
            'logTotal' => $repo->logCount([]),
            'confirmClear' => $confirmClear && $repo->logCount([]) > 0,
            'clearable' => !$confirmClear,
        ];
    }

    /**
     * The Search/Filter strip above a list: what it holds now, what its form sends beside its own
     * fields (the tab and the order, so a search keeps the sort), and the way back to the whole list.
     *
     * @param list<string> $own the names of the strip's own fields
     * @param array<string, string> $values
     * @param list<array{value: string, label: string, selected: bool}> $options
     * @return array<string, mixed>
     */
    private static function filter(Listing $list, array $own, array $values, array $options): array
    {
        $hidden = array_values(array_filter($list->model(0)['hidden'], static fn (array $field): bool => !in_array($field['name'], $own, true)));
        return [
            'open' => array_filter($values, static fn (string $v): bool => $v !== '') !== [],
            'search' => $values['search'] ?? '',
            'options' => $options,
            'hidden' => $hidden,
            'resetLink' => $list->link(array_fill_keys($own, '') + ['page' => 1]),
        ];
    }

    /**
     * WHMCS HTML-encodes request input at init ("&" arrives as "&amp;"); the page decodes every
     * string of the POST once before reading it, as Agency Discount does.
     *
     * @param array<array-key, mixed> $input
     * @return array<array-key, mixed>
     */
    public static function decodeInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = self::decodeInput($value);
            } elseif (is_string($value)) {
                $input[$key] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $input;
    }

    /** WHMCS writes the roles ticked under Configure as the "access" row: role ids separated by commas. */
    public static function allowed(string $access, ?int $roleId): bool
    {
        if ($roleId === null) {
            return false;
        }
        return in_array((string) $roleId, array_map('trim', explode(',', $access)), true);
    }
}
