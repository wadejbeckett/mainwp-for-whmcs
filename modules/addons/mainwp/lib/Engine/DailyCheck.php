<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * The daily check (SPEC.md, "Daily check"): every care add-on of the billing system set against
 * the module's own record and against what the dashboard holds. It provisions what is billed and
 * has no care site, works the retry list, mends a connection that broke, takes over a site staff
 * connected by hand, and reports every other mismatch. It corrects no mismatch: nothing is
 * suspended, resumed or removed here. One run at a time.
 *
 * When the dashboard itself is at fault, nothing is acted on; the report says so and still lists
 * what the module's own records show. In dry-run neither the dashboard nor a site is called.
 */
final class DailyCheck
{
    /** A failed connect is retried once a day, this many times. */
    public const RETRIES = 7;
    /** A care site whose add-on is not among those the check was given: the billing system no longer has it, or it no longer counts as care there. */
    private const GONE = 'its add-on is no longer in the billing system, or its Module Settings no longer name MainWP for WHMCS';

    /** The lock of the run itself; add-on rows start at 1. */
    private const RUN = 0;

    public function __construct(
        private readonly CareEngine $engine,
        private readonly Dashboard $dashboard,
        private readonly Storage $storage,
        private readonly Clock $clock,
        private readonly bool $dryRun,
    ) {
    }

    /** @param list<AddonState> $addons every care add-on, whatever its standing */
    public function run(array $addons, string $actor): Report
    {
        $report = new Report();
        if (!$this->storage->lock(self::RUN)) {
            $report->alreadyRunning = true;
            return $report;
        }
        try {
            $states = [];
            foreach ($addons as $addon) {
                $states[$addon->addonRowId] = $addon;
            }
            $this->check($states, $actor, $report);
        } finally {
            $this->storage->unlock(self::RUN);
        }
        return $report;
    }

    /** @param array<int, AddonState> $states */
    private function check(array $states, string $actor, Report $report): void
    {
        if ($this->dryRun) {
            $this->fromRecords($states, $report, $actor, true);
            $this->log($actor, 'done', self::addons(count($states)) . ', from the module\'s own records only: ' . count($report->lines()) . ' to report');
            return;
        }
        try {
            $rows = $this->dashboard->sites();
            $clients = $this->dashboard->clients();
        } catch (\Throwable $e) {
            $report->fault = 'The dashboard could not be read, so nothing was changed: ' . LogText::clean($e->getMessage());
            $this->fromRecords($states, $report, $actor, false);
            $this->log($actor, 'failed', $report->fault);
            return;
        }
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->id] = $row;
        }
        $this->engine->expireApprovals($actor);
        $this->engine->tidyKeys($actor);

        $run = new DailyRun($states, $byId, $this->clock->now()->format('Y-m-d'));
        $this->takeOver($run, $rows, $actor);
        foreach ($states as $addon) {
            $this->one($addon, $run, $report, $actor);
        }
        foreach ($this->storage->sites() as $site) {
            if (!isset($states[$site->addonRowId]) && $site->state !== State::Removed) {
                $this->ended($site, null, $run, $report);
            }
        }
        $this->lost($run, $report, $actor);
        $this->uncovered($rows, $clients, $report);

        $this->log($actor, 'done', sprintf(
            '%s: %d provisioned, %d retried (%d connected), %d mended, %d taken over, %d to report',
            self::addons(count($states)),
            $run->provisioned,
            $run->retried,
            $run->connected,
            $run->mended,
            $run->takenOver,
            count($report->lines()),
        ));
    }

    /** One care add-on in a live run. */
    private function one(AddonState $addon, DailyRun $run, Report $report, string $actor): void
    {
        $site = $this->storage->site($addon->addonRowId);
        if ($site === null || $site->state === State::Removed) {
            if (!$addon->billed) {
                return;
            }
            $outcome = $this->engine->provision($addon->addonRowId, $addon->serviceId, $addon->client, $addon->siteUrl, $actor);
            $site = $this->storage->site($addon->addonRowId);
            if (self::noDomain($addon) && ($site === null || $site->state === State::Removed)) {
                $report->add(self::noDomainLine($addon));
                return;
            }
            if (!$outcome->ok || $site === null || $site->state === State::Removed) {
                $report->add(new ReportLine($addon->addonRowId, self::client($addon), $addon->siteUrl, 'Not provisioned', 'could not be provisioned: ' . $outcome->message, 'not provisioned'));
                return;
            }
            $run->provisioned++;
        }
        if ($addon->standing === AddonState::ENDED) {
            $this->ended($site, $addon, $run, $report);
            return;
        }
        $row = null;
        if ($site->dashboardSiteId !== null) {
            $row = $run->rows[$site->dashboardSiteId] ?? $this->single($site, $run, $report);
            if ($row === null && $run->halted && !in_array($site->addonRowId, $run->gone, true)) {
                return; // the dashboard failed on this very read: nothing can be said about the site
            }
            if ($row !== null && self::place($row->url) !== '' && self::place($row->url) !== self::place($site->siteUrl)) {
                // Not the care site's own. MainWP numbers its sites from 1, so with another dashboard's address in the settings an id can exist there too.
                $run->foreign[] = 'dashboard site ' . $row->id . ' is ' . $row->url . ', not ' . $site->siteUrl;
                $report->add($this->line($addon, $site, 'its dashboard site ' . $row->id . ' is at another address on the dashboard: ' . $row->url, 'another address'));
                return;
            }
            $run->found += $row !== null ? 1 : 0;
        }
        if ($addon->standing !== AddonState::ACTIVE) {
            // Care does not run: the dashboard's site, if there is one, is expected to be suspended.
            if ($row !== null && !$row->isSuspended()) {
                $how = $addon->standing === AddonState::SUSPENDED ? 'suspended' : 'not active';
                $report->add($this->line($addon, $site, $how . ' in WHMCS (' . $addon->why . ') but ' . $row->status . ' on the dashboard', 'cared for while ' . $how));
            }
            return;
        }
        if ($site->dashboardSiteId !== null && $row === null) {
            $run->missing[] = $addon; // the dashboard said it has no such site; decided once all are known
            return;
        }
        if ($row !== null && (in_array($site->state, [State::Connected, State::Suspended], true) || !$row->isDisconnected())) {
            $this->onDashboard($addon, $site, $row, $run, $report, $actor);
            return;
        }
        // No site on the dashboard, or a dead one left from a connection that broke (the next connect
        // uses it again): what the record waits for is what counts.
        $covered = $this->coveredBy($site);
        if ($covered !== null) {
            // Not retried either: the dashboard already holds the site for the other add-on. What a failed connect
            // kept for its retries (a login typed, a key, the retry date) is let go, as when retries end.
            if ($site->state === State::Failed && ($site->retryAfter !== null || $this->storage->login($site->addonRowId) !== null || $this->storage->heldKey($site->addonRowId) !== null)) {
                $this->engine->endRetries($site->addonRowId, $actor);
                $site = $this->storage->site($site->addonRowId) ?? $site;
            }
            $report->add($this->secondAddon($addon, $site, $covered));
            return;
        }
        $this->waiting($addon, $site, $run, $report, $actor);
    }

    /** An active add-on whose care site is not connected on record: what it waits for, or the retry list. */
    private function waiting(AddonState $addon, CareSite $site, DailyRun $run, Report $report, string $actor): void
    {
        match ($site->state) {
            State::Provisioned => $report->add($this->line($addon, $site, 'waiting for the client to connect the site', 'waiting for the client')),
            State::Connecting => $report->add($this->line($addon, $site, 'waiting for the client to approve the connection on their site', 'waiting for the client')),
            State::Failed => $this->retry($addon, $site, $run, $report, $actor),
            default => null,
        };
    }

    /** An active add-on whose care site is connected on record, or whose dashboard site is not disconnected: judged by the dashboard's own row. */
    private function onDashboard(AddonState $addon, CareSite $site, DashboardSite $row, DailyRun $run, Report $report, string $actor): void
    {
        if ($row->isSuspended()) {
            $report->add($this->line($addon, $site, 'Active in WHMCS but suspended on the dashboard', 'active but suspended'));
            return;
        }
        if (!$row->isDisconnected()) {
            $this->engine->seenOnDashboard($site->addonRowId, $row, $actor);
            return;
        }
        $says = $row->syncErrors !== '' ? ' (' . $row->syncErrors . ')' : '';
        if ($site->state !== State::Connected) {
            $report->add($this->line($addon, $site, 'disconnected on the dashboard' . $says, 'disconnected'));
            return;
        }
        if ($run->halted) {
            $report->add($this->line($addon, $site, 'disconnected on the dashboard' . $says . '; not mended in this run because of the dashboard fault', 'disconnected'));
            return;
        }
        $outcome = $this->engine->mend($site->addonRowId, $actor);
        if ($outcome->ok) {
            $run->mended++;
            return;
        }
        if (!$outcome->attempted) {
            return; // another request is working on the add-on
        }
        if ($outcome->dashboardFault) {
            $this->halt($run, $report, $outcome->message);
            $report->add($this->line($addon, $site, 'disconnected on the dashboard' . $says . '; the mend was stopped by the dashboard fault', 'disconnected'));
            return;
        }
        $now = $this->storage->site($site->addonRowId) ?? $site;
        if ($now->state === State::Failed) {
            // The client is asked to connect again: reported as it will be tomorrow, so that it is not news twice. The log keeps why.
            $this->waiting($addon, $now, $run, $report, $actor);
            return;
        }
        $report->add($this->line($addon, $now, $outcome->message, 'disconnected'));
    }

    /** An active add-on whose connect failed, or whose connection broke and could not be mended: the retry list. */
    private function retry(AddonState $addon, CareSite $site, DailyRun $run, Report $report, string $actor): void
    {
        $id = $site->addonRowId;
        $reason = $site->lastError ?? 'no reason was recorded.';
        if ($site->retryAfter === null) {
            $report->add($this->line($addon, $site, $reason, 'not connected'));
            return;
        }
        if (substr($site->retryAfter, 0, 10) > $run->today) {
            $report->add($this->line($addon, $site, $reason . ' Next retry on ' . substr($site->retryAfter, 0, 10) . '.', 'not connected'));
            return;
        }
        if ($run->halted) {
            $report->add($this->line($addon, $site, $reason . ' The retry was not made: the dashboard fault.', 'not connected'));
            return;
        }
        if ($site->retryCount < self::RETRIES) {
            $outcome = $this->engine->retryConnect($id, $actor, $run->today);
            $now = $this->storage->site($id);
            if (!$outcome->attempted) {
                if ($now !== null && $now->state === State::Failed && $now->retryAfter === null) {
                    $report->add($this->line($addon, $now, ($now->lastError ?? $reason), 'not connected')); // nothing was left to retry with
                }
                return; // otherwise another request is working on the add-on, or made today's attempt
            }
            $run->retried++;
            if ($outcome->ok) {
                $run->connected++;
                return;
            }
            if ($outcome->dashboardFault) {
                $this->halt($run, $report, $outcome->message);
                $report->add($this->line($addon, $now ?? $site, 'the retry was stopped by the dashboard fault and is not counted', 'not connected'));
                return;
            }
            if ($now === null || $now->state !== State::Failed) {
                return;
            }
            if ($now->retryAfter === null || $now->retryCount < self::RETRIES) {
                $report->add($this->line($addon, $now, ($now->lastError ?? $reason) . ($now->retryAfter !== null ? ' Retry ' . $now->retryCount . ' of ' . self::RETRIES . '.' : ''), 'not connected'));
                return;
            }
            $reason = $now->lastError ?? $reason;
        }
        // The seventh retry has been made: the list lets go of the site, and the client is asked again.
        $this->engine->endRetries($id, $actor);
        $now = $this->storage->site($id);
        if ($now !== null && $now->state === State::Failed) {
            $report->add($this->line($addon, $now, 'not connected after seven retries: ' . str_replace(CareEngine::RETRY_PROMISE, '', $reason) . ' The client is asked to connect again.', 'not connected')); // the key it has the day after: the same finding is not news twice
        }
    }

    /**
     * Asks the dashboard for one site that is not in its list (a site it has flagged as staging is
     * left out of the list). Null when it has no such site, or when it failed to answer; a failure
     * ends the run's retries and mends.
     */
    private function single(CareSite $site, DailyRun $run, Report $report): ?DashboardSite
    {
        if ($run->halted) {
            return null;
        }
        try {
            return $this->dashboard->site((int) $site->dashboardSiteId);
        } catch (\Throwable $e) {
            if ($e instanceof DashboardFailure && $e->kind === DashboardFailure::NOT_FOUND) {
                $run->gone[] = $site->addonRowId;
                return null;
            }
            $this->halt($run, $report, LogText::clean($e->getMessage()));
            return null;
        }
    }

    /** Sites that are on record with a dashboard site the dashboard says it does not have. */
    private function lost(DailyRun $run, Report $report, string $actor): void
    {
        if ($run->missing !== [] && $run->found === 0 && ($run->foreign !== [] || count($run->missing) > 1)) {
            // A removed site is a removed site. But when none of the module's sites was seen on the dashboard, and one
            // is at another address there or several are not found, this looks like another dashboard: nothing is let go of.
            $report->fault ??= $run->foreign !== []
                ? 'The dashboard holds another site than the module\'s records say (' . $run->foreign[0] . '); nothing was changed for the sites that were not found. Check the dashboard address and the API key in the settings.'
                : 'None of the ' . count($run->missing) . ' sites the module\'s records hold a dashboard site for was found on the dashboard; nothing was changed for them. Check the dashboard address and the API key in the settings.';
            foreach ($run->missing as $addon) {
                $site = $this->storage->site($addon->addonRowId);
                if ($site !== null) {
                    $report->add($this->line($addon, $site, 'not found on the dashboard', 'not found on the dashboard'));
                }
            }
            return;
        }
        foreach ($run->missing as $addon) {
            $before = $this->storage->site($addon->addonRowId);
            $outcome = $this->engine->lostFromDashboard($addon->addonRowId, $actor);
            $site = $this->storage->site($addon->addonRowId);
            if ($before === null || $site === null || !$outcome->ok) {
                continue;
            }
            if (in_array($before->state, [State::Connected, State::Suspended], true)) {
                $report->add($this->line($addon, $site, 'the dashboard no longer holds its site; ' . ($site->retryAfter !== null ? 'it is added again with the stored login' : 'the client is asked to connect again'), 'lost from the dashboard'));
            } else {
                $this->waiting($addon, $site, $run, $report, $actor); // it was not connected on record: only the dead site is let go of
            }
        }
    }

    /**
     * A care site that waits to be connected and that the dashboard already holds, connected, under
     * its client at the same address: staff connected it there by hand. It is taken over only when
     * it is clear whose it is: exactly one waiting care site of an active add-on matches.
     *
     * @param list<DashboardSite> $rows
     */
    private function takeOver(DailyRun $run, array $rows, string $actor): void
    {
        $sites = $this->storage->sites();
        $pointed = [];
        foreach ($sites as $site) {
            if ($site->dashboardSiteId !== null) {
                $pointed[$site->dashboardSiteId] = true;
            }
        }
        foreach ($rows as $row) {
            if ($row->clientId === null || isset($pointed[$row->id]) || !$row->isConnected()) {
                continue;
            }
            $waiting = array_values(array_filter($sites, static fn (CareSite $site): bool => $site->dashboardClientId === $row->clientId
                && $site->dashboardSiteId === null
                && ($site->state === State::Provisioned || $site->state === State::Failed)
                && ($run->states[$site->addonRowId]->standing ?? null) === AddonState::ACTIVE
                && self::place($site->siteUrl) === self::place($row->url)));
            if (count($waiting) === 1 && $this->engine->adoptSite($waiting[0]->addonRowId, $row->id, $actor)->ok) {
                $run->takenOver++;
            }
        }
    }

    /**
     * Sites the dashboard holds under a client that no care site points at: reported, never removed.
     * Sites under no client, or under a client the module did not make, are the dashboard owner's own
     * and are left out.
     *
     * @param list<DashboardSite> $rows
     * @param list<DashboardClient> $clients
     */
    private function uncovered(array $rows, array $clients, Report $report): void
    {
        // Only the dashboard clients the module made (their note names the billing client): a dashboard
        // already in use keeps its own clients' sites out of the report.
        $names = [];
        foreach ($clients as $client) {
            if ($client->billingClientId !== null) {
                $names[$client->id] = $client->name;
            }
        }
        $pointed = [];
        foreach ($this->storage->sites() as $site) {
            if ($site->dashboardSiteId !== null && $site->state !== State::Removed) {
                $pointed[$site->dashboardSiteId] = true;
            }
        }
        foreach ($rows as $row) {
            if ($row->clientId === null || !isset($names[$row->clientId]) || isset($pointed[$row->id])) {
                continue;
            }
            $report->add(new ReportLine(null, $names[$row->clientId], $row->url, 'On the dashboard only, ' . $row->status, 'under a client on the dashboard and not covered by any care add-on', 'not covered'));
        }
    }

    private static function addons(int $count): string
    {
        return $count . ' care add-on' . ($count === 1 ? '' : 's');
    }

    /** A care site whose add-on has ended, or is gone from the billing system ($addon null), and that is not marked removed. */
    private function ended(CareSite $site, ?AddonState $addon, DailyRun $run, Report $report): void
    {
        $row = $site->dashboardSiteId !== null ? ($run->rows[$site->dashboardSiteId] ?? $this->single($site, $run, $report)) : null;
        $where = $row !== null ? 'still on the dashboard (' . $row->status . ')' : null;
        if ($row !== null && self::place($row->url) !== '' && self::place($row->url) !== self::place($site->siteUrl)) {
            // As in one(): a row at another address is not this care site's, and is not said to be.
            $run->foreign[] = 'dashboard site ' . $row->id . ' is ' . $row->url . ', not ' . $site->siteUrl;
            $where = 'at another address on the dashboard: ' . $row->url;
            $report->add(new ReportLine($site->addonRowId, $addon !== null ? self::client($addon) : 'client ' . $site->clientId, $site->siteUrl, ucfirst($site->state->value), ($addon !== null ? 'ended in WHMCS (' . $addon->why . ')' : self::GONE) . ' but its dashboard site ' . $row->id . ' is ' . $where, 'another address'));
            return;
        }
        if ($addon === null) {
            $report->add(new ReportLine($site->addonRowId, 'client ' . $site->clientId, $site->siteUrl, ucfirst($site->state->value), self::GONE . ', but ' . ($where !== null ? 'its site is ' . $where : 'its care site is not marked removed'), 'ended'));
            return;
        }
        $report->add($this->line($addon, $site, 'ended in WHMCS (' . $addon->why . ') but ' . ($where ?? 'its care site is not marked removed'), 'ended'));
    }

    /**
     * What the module's own records show, with nothing asked of the dashboard or of a site: in
     * dry-run, and when the dashboard could not be read.
     *
     * @param array<int, AddonState> $states
     */
    private function fromRecords(array $states, Report $report, string $actor, bool $dryRun): void
    {
        $today = $this->clock->now()->format('Y-m-d');
        foreach ($states as $addon) {
            $site = $this->storage->site($addon->addonRowId);
            if ($site === null || $site->state === State::Removed) {
                if ($addon->billed && self::noDomain($addon)) {
                    if ($dryRun) {
                        $this->engine->provision($addon->addonRowId, $addon->serviceId, $addon->client, $addon->siteUrl, $actor); // logs that it waits
                    }
                    $report->add(self::noDomainLine($addon));
                } elseif ($addon->billed) {
                    if ($dryRun) {
                        $this->engine->provision($addon->addonRowId, $addon->serviceId, $addon->client, $addon->siteUrl, $actor); // logs what it would do
                    }
                    $report->add(new ReportLine($addon->addonRowId, self::client($addon), $addon->siteUrl, 'Not provisioned', $dryRun ? 'would be provisioned (dry run)' : 'not provisioned yet', 'not provisioned'));
                }
                continue;
            }
            if ($addon->standing === AddonState::ENDED) {
                $report->add($this->line($addon, $site, 'ended in WHMCS (' . $addon->why . ') but its care site is not marked removed', 'ended'));
            } elseif ($addon->standing !== AddonState::ACTIVE) {
                if ($site->state === State::Connected) {
                    $report->add($this->line($addon, $site, ($addon->standing === AddonState::SUSPENDED ? 'suspended' : 'not active') . ' in WHMCS (' . $addon->why . ') but connected on record', 'cared for while ' . ($addon->standing === AddonState::SUSPENDED ? 'suspended' : 'not active')));
                }
            } elseif ($site->state === State::Suspended) {
                $report->add($this->line($addon, $site, 'Active in WHMCS but suspended on record', 'active but suspended'));
            } elseif (($site->state === State::Provisioned || $site->state === State::Failed) && ($covered = $this->coveredBy($site)) !== null) {
                $report->add($this->secondAddon($addon, $site, $covered));
            } elseif ($site->state === State::Provisioned) {
                $report->add($this->line($addon, $site, 'waiting for the client to connect the site', 'waiting for the client'));
            } elseif ($site->state === State::Connecting) {
                $report->add($this->line($addon, $site, 'waiting for the client to approve the connection on their site', 'waiting for the client'));
            } elseif ($site->state === State::Failed) {
                $due = $dryRun && $site->retryAfter !== null && substr($site->retryAfter, 0, 10) <= $today;
                $report->add($this->line($addon, $site, ($site->lastError ?? 'no reason was recorded.') . ($due ? ' Would be retried (dry run).' : ''), 'not connected'));
            }
        }
        foreach ($this->storage->sites() as $site) {
            if (!isset($states[$site->addonRowId]) && $site->state !== State::Removed) {
                $report->add(new ReportLine($site->addonRowId, 'client ' . $site->clientId, $site->siteUrl, ucfirst($site->state->value), self::GONE . ', but its care site is not marked removed', 'ended'));
            }
        }
    }

    /** Is the add-on's hosting service without a domain, so that no site can be connected for it? */
    private static function noDomain(AddonState $addon): bool
    {
        return trim($addon->siteUrl) === '';
    }

    private static function noDomainLine(AddonState $addon): ReportLine
    {
        return new ReportLine($addon->addonRowId, self::client($addon), '', 'Not provisioned', CareEngine::NO_DOMAIN, 'not provisioned');
    }

    /**
     * The add-on of another care site of the same client, at the same address (SiteAddress::same), that
     * is Connected or Suspended, when $site waits to be connected (Provisioned or Failed); otherwise null.
     */
    private function coveredBy(CareSite $site): ?int
    {
        if ($site->state !== State::Provisioned && $site->state !== State::Failed) {
            return null;
        }
        foreach ($this->storage->sites() as $other) {
            if ($other->addonRowId !== $site->addonRowId
                && $other->clientId === $site->clientId
                && ($other->state === State::Connected || $other->state === State::Suspended)
                && SiteAddress::same($other->siteUrl, $site->siteUrl)) {
                return $other->addonRowId;
            }
        }
        return null;
    }

    private function secondAddon(AddonState $addon, CareSite $site, int $covered): ReportLine
    {
        return $this->line($addon, $site, 'a second care add-on for a site that add-on ' . $covered . ' already covers', 'second add-on');
    }

    /** The dashboard failed in the middle of the run: no further retry or mend is made in it. */
    private function halt(DailyRun $run, Report $report, string $reason): void
    {
        $run->halted = true;
        $report->fault ??= 'The dashboard failed during the run, so no further retries or mends were made: ' . $reason;
    }

    private function line(AddonState $addon, CareSite $site, string $problem, string $kind): ReportLine
    {
        return new ReportLine($addon->addonRowId, self::client($addon), $site->siteUrl, ucfirst($site->state->value), $problem, $kind);
    }

    private static function client(AddonState $addon): string
    {
        return $addon->client->name . ' (client ' . $addon->client->id . ')';
    }

    /** Where a site is, for telling two addresses of one site apart from two sites: the host without "www." and the path, whatever the scheme. */
    private static function place(string $url): string
    {
        $host = (string) preg_replace('/^www\./', '', strtolower((string) parse_url(trim($url), PHP_URL_HOST)));
        return $host . rtrim((string) parse_url(trim($url), PHP_URL_PATH), '/');
    }

    private function log(string $actor, string $outcome, string $detail): void
    {
        $this->storage->log(new LogEntry($this->clock->now()->format('Y-m-d H:i:s'), $actor, 'daily check', 'all care add-ons', $outcome, $this->dryRun, $detail));
    }
}
