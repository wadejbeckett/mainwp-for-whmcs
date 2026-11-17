<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Admin;

use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\ClientPage;
use MainWpForWhmcs\Daily;
use MainWpForWhmcs\Engine\State;
use MainWpForWhmcs\Repository;
use MainWpForWhmcs\Settings;

/**
 * The rows of the Sites tab: every care site with its status and what the last daily check found
 * for it, each billed add-on that has no care site yet, and each site the check found on the
 * dashboard under a dashboard client the module made with no care add-on. A removed site with nothing to report is
 * history and is listed only when Removed is asked for. Narrowed, sorted and cut into pages here:
 * the rows come from the module's own table and the stored result of the last check.
 */
final class SitesList
{
    /** The order the list opens in: what has an issue first, then by site. */
    public const BY_ISSUE = 'issue';
    public const SORTABLE = ['site', 'client', 'status', 'lastsync', self::BY_ISSUE];

    /**
     * @return list<array{addon: ?int, service: ?int, clientId: ?int, client: string, site: string, status: string, lastSync: string, issue: string, connectable: bool, dashboardSite: ?int}>
     */
    public static function rows(Repository $repo, Settings $settings): array
    {
        $last = Daily::last($repo);
        $lines = [];
        $loose = [];
        foreach (is_array($last['lines'] ?? null) ? $last['lines'] : [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (is_int($line['addon'] ?? null)) {
                $lines[$line['addon']][] = $line;
            } elseif (str_starts_with((string) ($line['key'] ?? ''), 'not covered:')) {
                $loose[] = $line;
            }
        }
        $addons = [];
        foreach ($repo->careAddonRows($settings->careAddonIds) as $addon) {
            $addons[$addon->id] = $addon;
        }
        $rows = [];
        foreach ($repo->careSites() as $site) {
            $id = (int) ($site['addon_row_id'] ?? 0);
            $found = $lines[$id] ?? [];
            unset($lines[$id]);
            $state = State::tryFrom(is_scalar($site['state'] ?? null) ? (string) $site['state'] : '');
            $addon = $addons[$id] ?? $repo->addonRow($id);
            $disconnected = self::disconnected($found);
            $dashboardSite = is_numeric($site['dashboard_site_id'] ?? null) ? (int) $site['dashboard_site_id'] : null;
            $rows[] = [
                'addon' => $id,
                'service' => $addon?->serviceId ?? (is_numeric($site['service_id'] ?? null) ? (int) $site['service_id'] : null),
                'clientId' => $addon?->clientId ?? (is_numeric($site['client_id'] ?? null) ? (int) $site['client_id'] : null),
                'client' => $addon !== null ? $addon->clientName : 'Client ' . (int) ($site['client_id'] ?? 0),
                'site' => is_scalar($site['site_url'] ?? null) ? (string) $site['site_url'] : '',
                'status' => $state === null ? '' : Status::of($state, $disconnected),
                'lastSync' => is_scalar($site['last_sync'] ?? null) ? (string) $site['last_sync'] : '',
                'issue' => self::issue($found),
                // Only an add-on that still names the module (a care add-on) can be connected: the form and the connect itself ask the same.
                'connectable' => isset($addons[$id]) && $addons[$id]->active() && ClientPage::connectable($site),
                'dashboardSite' => $dashboardSite !== null && $dashboardSite > 0 ? $dashboardSite : null,
            ];
        }
        foreach ($lines as $id => $found) {
            // No care site: the line itself says whose it is.
            $addon = $addons[$id] ?? null;
            $rows[] = ['addon' => $id, 'service' => $addon?->serviceId, 'clientId' => $addon?->clientId, 'client' => $addon !== null ? $addon->clientName : self::name((string) $found[0]['client']), 'site' => (string) $found[0]['site'], 'status' => Status::NOT_PROVISIONED, 'lastSync' => '', 'issue' => self::issue($found), 'connectable' => false, 'dashboardSite' => null];
        }
        foreach ($loose as $line) {
            $word = trim((string) preg_replace('/^on the dashboard only,/i', '', (string) $line['state'])); // a result kept by 0.4.2 wrote it in lower case
            $rows[] = ['addon' => null, 'service' => null, 'clientId' => null, 'client' => self::name((string) $line['client']), 'site' => (string) $line['site'], 'status' => Status::ofDashboard($word), 'lastSync' => '', 'issue' => 'On the dashboard without a care add-on.', 'connectable' => false, 'dashboardSite' => null];
        }
        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>> the rows with these words in the site or the client and with this status; without a status, all but the removed sites that have no issue
     */
    public static function narrowed(array $rows, string $search, string $status): array
    {
        $search = trim($search);
        return array_values(array_filter($rows, static function (array $row) use ($search, $status): bool {
            if ($status !== '' ? $row['status'] !== $status : ($row['status'] === State::Removed->value && $row['issue'] === '')) {
                return false;
            }
            return $search === '' || mb_stripos($row['site'], $search) !== false || mb_stripos($row['client'], $search) !== false;
        }));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function sorted(array $rows, string $orderBy, string $order): array
    {
        $statuses = array_flip(array_keys(Status::ALL));
        $site = static fn (array $row): string => (string) preg_replace('#^https?://(www\.)?#i', '', $row['site']);
        $value = match ($orderBy) {
            'site' => $site,
            'client' => static fn (array $row): string => $row['client'],
            'status' => static fn (array $row): int => $statuses[$row['status']] ?? count($statuses),
            'lastsync' => static fn (array $row): string => $row['lastSync'],
            // What has an issue first: the order the list opens in.
            default => static fn (array $row): int => $row['issue'] !== '' ? 0 : 1,
        };
        $turn = $order === 'desc' ? -1 : 1;
        usort($rows, static function (array $a, array $b) use ($value, $site, $turn): int {
            [$x, $y] = [$value($a), $value($b)];
            $first = (is_string($x) ? strcasecmp($x, (string) $y) : $x <=> $y) * $turn;
            return $first !== 0 ? $first : strcasecmp($site($a), $site($b));
        });
        return $rows;
    }

    /**
     * The lines the last check found for one add-on.
     *
     * @return list<array<string, mixed>>
     */
    public static function found(Repository $repo, int $addonRowId): array
    {
        $found = [];
        $lines = Daily::last($repo)['lines'] ?? null;
        foreach (is_array($lines) ? $lines : [] as $line) {
            if (is_array($line) && ($line['addon'] ?? null) === $addonRowId) {
                $found[] = $line;
            }
        }
        return $found;
    }

    /**
     * Did the last check find the site disconnected on the dashboard (and not mend it)?
     *
     * @param list<array<string, mixed>> $found
     */
    public static function disconnected(array $found): bool
    {
        return array_filter($found, static fn (array $line): bool => str_starts_with((string) ($line['key'] ?? ''), 'disconnected:')) !== [];
    }

    /**
     * What the last check found for a site, its findings one after the other.
     *
     * @param list<array<string, mixed>> $found
     */
    public static function issue(array $found): string
    {
        return implode(' ', array_map(static function (array $line): string {
            $text = ucfirst(trim((string) $line['problem']));
            // A date in a finding is shown as every other date on the page is.
            $text = (string) preg_replace_callback('/\b\d{4}-\d{2}-\d{2}\b/', static fn (array $m): string => Listing::date($m[0], false), $text);
            return $text === '' || preg_match('/[.!?]$/', $text) === 1 ? $text : $text . '.';
        }, $found));
    }

    /** A report line names a client as "Name (client 12)": the name alone. */
    private static function name(string $client): string
    {
        return (string) preg_replace('/ \(client \d+\)$/', '', $client);
    }

    /** What is said under the list when the last check found more than it could keep. */
    public static function more(Repository $repo): string
    {
        $more = (int) (Daily::last($repo)['more'] ?? 0);
        return $more > 0 ? 'The last check found ' . $more . ' more ' . ($more === 1 ? 'issue' : 'issues') . ' than it could keep.' : '';
    }

    /**
     * What the last check found that belongs to no site: events from the log.
     *
     * @return list<string>
     */
    public static function others(Repository $repo): array
    {
        $others = [];
        foreach (is_array(Daily::last($repo)['lines'] ?? null) ? Daily::last($repo)['lines'] : [] as $line) {
            if (is_array($line) && !is_int($line['addon'] ?? null) && !str_starts_with((string) ($line['key'] ?? ''), 'not covered:')) {
                $others[] = trim((string) $line['site'] . ': ' . (string) $line['problem'], ': ');
            }
        }
        return $others;
    }

    /** May a member of staff connect this add-on's site now, and with which address and client? Null when not. */
    public static function connectable(Repository $repo, Settings $settings, int $addonRowId): ?AddonRow
    {
        $row = $repo->addonRow($addonRowId);
        $site = $repo->careSite($addonRowId);
        return $row !== null && $site !== null && in_array($row->addonId, $settings->careAddonIds, true) && $row->active() && ClientPage::connectable($site) ? $row : null;
    }
}
