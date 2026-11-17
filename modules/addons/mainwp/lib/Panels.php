<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

use MainWpForWhmcs\Admin\Template;
use MainWpForWhmcs\Engine\State;

/** The client's panel in the sidebar of the service's product details page and the staff panel on the admin service page. */
final class Panels
{
    /**
     * The name of the client's panel in the sidebar, by which a theme or another hook finds it: the
     * same neutral words whatever the host calls its care. Its heading is the care add-ons' own name.
     */
    public const CLIENT_PANEL = ServiceName::NEUTRAL;

    /** Its icon, a Font Awesome class as WHMCS's own panels carry one. */
    private const CLIENT_PANEL_ICON = 'fas fa-shield-alt';

    /** WHMCS's own panels in the sidebar of a service's product details page, by name. */
    private const SERVICE_OVERVIEW = 'Service Details Overview';
    private const SERVICE_ACTIONS = 'Service Details Actions';

    /** What the client reads for a connected site the last check found disconnected and could not mend. */
    public const UNREACHABLE = 'We cannot reach your site at the moment.';

    /** Add-on statuses under which the client sees the care add-on, in the panel and on the connect page. */
    public const CLIENT_STATUSES = ['Active', 'Suspended', 'Pending'];

    /**
     * Adds the client's panel to the sidebar, between the service's overview and its actions when
     * their order leaves room for it, else where WHMCS puts an item given no order. Nothing is added
     * when client() has nothing to show.
     *
     * @param array{clientId: int, permitted: bool}|null $viewer
     */
    public static function clientSidebar(\WHMCS\View\Menu\Item $sidebar, Repository $repo, int $serviceId, ?array $viewer): void
    {
        [$html, $rows] = self::clientPanel($repo, $serviceId, $viewer);
        if ($html === '') {
            return;
        }
        // The heading: the name the care add-ons shown share, else the fallback, so that two names never mix.
        $options = ['label' => ServiceName::forTheme(ServiceName::shared($repo, $rows))];
        $overview = $sidebar->getChild(self::SERVICE_OVERVIEW);
        $actions = $sidebar->getChild(self::SERVICE_ACTIONS);
        if ($overview !== null && $actions !== null && $actions->getOrder() - $overview->getOrder() > 1) {
            $options['order'] = $overview->getOrder() + 1;
        }
        $panel = $sidebar->addChild(self::CLIENT_PANEL, $options)->setBodyHtml($html);
        try {
            // A theme that hides the icons still spaces its panel headings by them.
            $panel->setIcon(self::CLIENT_PANEL_ICON);
        } catch (\Throwable) {
            // Decoration only: setIcon() throws for a class it does not take for an icon, and the panel stands without one.
        }
    }

    /**
     * The body of the client's panel, or '' when the viewer may not see it: nobody logged in, another client's
     * service, a user without the permission to manage the account's products, or anyone in dry run
     * (nothing is stored then, so there is nothing to show or connect).
     *
     * @param array{clientId: int, permitted: bool}|null $viewer
     */
    public static function client(Repository $repo, int $serviceId, ?array $viewer): string
    {
        return self::clientPanel($repo, $serviceId, $viewer)[0];
    }

    /**
     * client()'s body with the care add-on rows it shows.
     *
     * @param array{clientId: int, permitted: bool}|null $viewer
     * @return array{string, list<AddonRow>}
     */
    private static function clientPanel(Repository $repo, int $serviceId, ?array $viewer): array
    {
        if ($viewer === null || !$viewer['permitted']) {
            return ['', []];
        }
        $settings = Settings::load($repo);
        if ($settings->dryRun) {
            return ['', []];
        }
        $items = [];
        $shown = [];
        foreach (self::careRows($repo, $serviceId, $settings) as $row) {
            if ($row->clientId !== $viewer['clientId']) {
                return ['', []];
            }
            if (!in_array($row->status, self::CLIENT_STATUSES, true)) {
                continue;
            }
            $site = self::current($repo, $settings, $row->id);
            $noDomain = $row->active() && ClientPage::noDomain($row, $site);
            $connect = !$noDomain && $site !== null && $row->active() && ClientPage::connectable($site);
            $running = self::running($repo, $site);
            $message = $noDomain ? ClientPage::NO_DOMAIN : self::reachable($repo, $row->id, self::clientMessage($repo, $row, $site, $running));
            $shown[] = $row;
            $items[] = ['address' => self::text($site, 'site_url') ?? $row->siteUrl(), 'message' => $message, 'lastSync' => Admin\Listing::date(self::text($site, 'last_sync') ?? ''), 'connectLink' => $connect && !$running ? 'index.php?m=' . Module::NAME . '&addon=' . $row->id : ''];
        }
        return $items === [] ? ['', []] : [Template::render('client-panel.tpl', ['items' => $items]), $shown];
    }

    /** @return array<string, string> one field per care add-on on the service, label => HTML */
    public static function admin(Repository $repo, int $serviceId, ?int $adminId = null): array
    {
        $settings = Settings::load($repo);
        $mayConnect = StaffConnect::allowed($repo, $adminId);
        $fields = [];
        foreach (self::careRows($repo, $serviceId, $settings) as $row) {
            $site = self::current($repo, $settings, $row->id);
            $state = State::tryFrom(self::text($site, 'state') ?? '');
            $because = $row->notBilledBecause();
            // The Sites tab's status and findings: a site the dashboard lost is not called connected here either.
            $found = Admin\SitesList::found($repo, $row->id);
            $status = $state === null ? Admin\Status::NOT_PROVISIONED : Admin\Status::of($state, Admin\SitesList::disconnected($found));
            $fields[ServiceName::forTheme(ServiceName::of($repo, $row)) . ' (add-on ' . $row->id . ')'] = Template::render('admin-service.tpl', [
                'state' => Admin\Status::label($status),
                // What the status alone does not say: why nothing is provisioned, or what a connection waits for.
                'stateNote' => match (true) {
                    $state === null => $because !== null ? '(' . $because . ')' : ($row->siteUrl() === '' ? '(' . Engine\CareEngine::NO_DOMAIN . ')' : ''),
                    $state === State::Connecting => self::running($repo, $site) ? '(a connection is running)' : '(waiting for the client to approve on their site)',
                    default => '',
                },
                'address' => self::text($site, 'site_url') ?? $row->siteUrl(),
                'stateClass' => Admin\Status::ALL[$status]['class'],
                'lastSync' => self::text($site, 'last_sync') !== null ? Admin\Listing::date((string) self::text($site, 'last_sync')) : 'Never',
                'lastError' => self::issue(self::text($site, 'last_error') ?? '', Admin\SitesList::issue($found)),
                'retryAfter' => $state === State::Failed ? Admin\Listing::date(self::text($site, 'retry_after') ?? '') : '',
                'lastConnect' => self::lastConnect($repo, $row->id),
                // One place connects a site: the Connect Site form of the module's Sites list.
                'connectLink' => $mayConnect && $site !== null && $row->active() && ClientPage::connectable($site) && !self::running($repo, $site) ? 'addonmodules.php?module=' . Module::NAME . '&tab=sites&connect=' . $row->id : '',
                // Where a connect form would be but for the add-on or its service: said, so that its absence is not a riddle.
                'noConnect' => $mayConnect && $site !== null && !$row->active() && ClientPage::connectable($site) ? 'Connecting is not available: ' . $row->notActiveBecause() . '.' : '',
                'dryRun' => $settings->dryRun,
            ]);
        }
        return $fields;
    }

    /**
     * The stored reason and what the last check found, the reason left out when the check's lines
     * already say it.
     */
    private static function issue(string $lastError, string $found): string
    {
        $reason = rtrim(trim($lastError), '.');
        return $reason === '' || str_contains(strtolower($found), strtolower($reason)) ? $found : trim($lastError . ' ' . $found);
    }

    /**
     * The care site's row as it stands now: an approval that was never given, or a connection cut
     * off halfway, is ended first, so that a panel never shows Connecting for ever.
     *
     * @return array<string, mixed>|null
     */
    private static function current(Repository $repo, Settings $settings, int $addonRowId): ?array
    {
        $site = $repo->careSite($addonRowId);
        if ($site !== null && ($site['state'] ?? null) === State::Connecting->value && Module::engine($repo, $settings)->expireApproval($addonRowId, 'module')) {
            return $repo->careSite($addonRowId);
        }
        return $site;
    }

    /** The newest connect attempt from the log, in one line, or ''. */
    private static function lastConnect(Repository $repo, int $addonRowId): string
    {
        $row = $repo->lastLogFor('connect', 'add-on ' . $addonRowId);
        if ($row === null) {
            return '';
        }
        $line = Admin\Listing::date((string) ($row['created_at'] ?? '')) . ' by ' . (string) ($row['actor'] ?? '') . ': ' . (string) ($row['outcome'] ?? '') . (!empty($row['dry_run']) ? ' (dry run)' : '') . ', ' . (string) ($row['detail'] ?? '');
        if (($row['outcome'] ?? null) === 'ended') {
            // The row that ended the retries says nothing of why they failed; the last failure does.
            $failed = $repo->lastLogFor('connect', 'add-on ' . $addonRowId, 'failed');
            if ($failed !== null) {
                $line .= ' The attempt before it failed (' . Admin\Listing::date((string) ($failed['created_at'] ?? '')) . '): ' . (string) ($failed['detail'] ?? '');
            }
        }
        return $line;
    }

    /** @return list<AddonRow> */
    private static function careRows(Repository $repo, int $serviceId, Settings $settings): array
    {
        return array_values(array_filter($repo->serviceAddonRows($serviceId), static fn (AddonRow $r): bool => in_array($r->addonId, $settings->careAddonIds, true)));
    }

    /**
     * Is a connection being made for the site right now, in another request? Connecting with a
     * password attempt's retry armed, or with a key that came back on its approval. A request that
     * died leaves the same marks, but current() has ended those before this is asked.
     *
     * @param array<string, mixed>|null $site
     */
    public static function running(Repository $repo, ?array $site): bool
    {
        if ($site === null || ($site['state'] ?? null) !== State::Connecting->value) {
            return false;
        }
        if (self::text($site, 'retry_after') !== null) {
            return true;
        }
        $approvals = $repo->approvals((int) ($site['addon_row_id'] ?? 0));
        return $approvals !== [] && self::text($approvals[array_key_last($approvals)], 'used_at') !== null;
    }

    /**
     * What the client reads about one care add-on: its site's state in plain words, or, while care
     * is not active, that it is not, since a site that is not on the dashboard cannot be connected then.
     *
     * @param array<string, mixed>|null $site
     */
    public static function clientMessage(Repository $repo, AddonRow $row, ?array $site, bool $running = false): string
    {
        $state = State::tryFrom(self::text($site, 'state') ?? '');
        if ($state === null) {
            return match (true) {
                $row->billed() => 'Care is being set up.',
                $row->unpaidInvoices > 0 => 'We can start looking after your site once the invoice for ' . ServiceName::of($repo, $row) . ' is paid.',
                $row->suspended() => self::suspended($row),
                default => 'Care is not active yet.',
            };
        }
        if (!$row->active() && in_array($state, ClientPage::CONNECTABLE, true)) {
            // Not on the dashboard, so its state would ask the client to connect a site that cannot be connected now.
            return $row->suspended() ? self::suspended($row) : 'Care is not active at the moment.';
        }
        return self::stateMessage($state, self::reason($site), $running);
    }

    /**
     * The stored reason of a care site as its client may read it (CareEngine::reasonForClient). Staff
     * read the stored words themselves.
     *
     * @param array<string, mixed>|null $site
     */
    public static function reason(?array $site): ?string
    {
        return Engine\CareEngine::reasonForClient(self::text($site, 'last_error'), self::text($site, 'site_url') ?? '');
    }

    /**
     * The client's sentence for one care add-on, wherever the client reads it: a connected site the
     * last check found disconnected on the dashboard, and could not mend, is one we cannot reach.
     */
    public static function reachable(Repository $repo, int $addonRowId, string $message): string
    {
        return $message === self::stateMessage(State::Connected, null) && Admin\SitesList::disconnected(Admin\SitesList::found($repo, $addonRowId)) ? self::UNREACHABLE : $message;
    }

    /**
     * Suspended care, with why when the add-on itself is suspended and has an unpaid invoice; no reason
     * is made up otherwise (paying the add-on's invoice does not end its hosting service's suspension).
     */
    private static function suspended(AddonRow $row): string
    {
        return $row->status === 'Suspended' && $row->unpaidInvoices > 0 ? 'Care is suspended until its invoice is paid.' : 'Care is suspended.';
    }

    /** What a state means to the client, in plain words. $running: a connection is being made right now. */
    public static function stateMessage(?State $state, ?string $lastError, bool $running = false): string
    {
        return match ($state) {
            null => 'Care is not set up yet.',
            State::Provisioned => 'Care is set up. Next, connect your site so that we can start looking after it.',
            State::Connecting => $running ? 'We are connecting your site now. Reload this page in a minute.' : 'Waiting for the connection to be approved on your site.',
            State::Connected => 'Your site is connected and cared for.',
            State::Suspended => 'Care is suspended: updates and health checks have stopped.',
            State::Removed => 'Care has ended for this site.',
            State::Failed => match (true) {
                $lastError === null || $lastError === '' => 'Connecting your site failed: no reason was recorded.',
                str_starts_with($lastError, Engine\CareEngine::NO_LONGER_CONNECTED) => $lastError, // it was connected: nothing the client tried has failed
                str_starts_with($lastError, Engine\CareEngine::NOT_CONNECTED) => $lastError, // says it by itself
                default => 'Connecting your site failed: ' . $lastError,
            },
        };
    }

    /** @param array<string, mixed>|null $row */
    private static function text(?array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
