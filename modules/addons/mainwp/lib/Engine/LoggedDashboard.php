<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * Writes one log line for every dashboard call: what was called, on what, how it ended, and the
 * reason when it failed. It sees no key and logs no password; a failure's text is cleaned again.
 * Failures are passed on unchanged.
 */
final class LoggedDashboard implements Dashboard
{
    public function __construct(
        private readonly Dashboard $inner,
        private readonly Storage $storage,
        private readonly Clock $clock,
        private readonly string $actor = 'module',
    ) {
    }

    public function counts(): array
    {
        return $this->logged('counts', 'dashboard', fn (): array => $this->inner->counts(), static fn (array $c): string => $c['clients'] . ' clients, ' . $c['sites'] . ' sites');
    }

    public function clients(): array
    {
        return $this->logged('list clients', 'dashboard', fn (): array => $this->inner->clients(), static fn (array $l): string => count($l) . ' clients');
    }

    public function findClient(int $billingClientId): ?int
    {
        return $this->logged('find client', 'billing client ' . $billingClientId, fn (): ?int => $this->inner->findClient($billingClientId), static fn (?int $id): string => $id === null ? 'none' : 'dashboard client ' . $id);
    }

    public function createClient(int $billingClientId, string $name, string $email): int
    {
        return $this->logged('create client', 'billing client ' . $billingClientId, fn (): int => $this->inner->createClient($billingClientId, $name, $email), static fn (int $id): string => 'dashboard client ' . $id);
    }

    public function sites(): array
    {
        return $this->logged('list sites', 'dashboard', fn (): array => $this->inner->sites(), static fn (array $l): string => count($l) . ' sites');
    }

    public function site(int $dashboardSiteId): DashboardSite
    {
        return $this->logged('read site', 'dashboard site ' . $dashboardSiteId, fn (): DashboardSite => $this->inner->site($dashboardSiteId), self::state(...));
    }

    public function addSite(int $dashboardClientId, string $url, Login $login): int
    {
        return $this->logged('add site', $url . ' under dashboard client ' . $dashboardClientId, fn (): int => $this->inner->addSite($dashboardClientId, $url, $login), static fn (int $id): string => 'dashboard site ' . $id . ', as ' . $login->username, $login->password);
    }

    public function syncSite(int $dashboardSiteId): DashboardSite
    {
        return $this->logged('sync site', 'dashboard site ' . $dashboardSiteId, fn (): DashboardSite => $this->inner->syncSite($dashboardSiteId), self::state(...));
    }

    public function reconnectSite(int $dashboardSiteId): void
    {
        $this->logged('reconnect site', 'dashboard site ' . $dashboardSiteId, function () use ($dashboardSiteId): void {
            $this->inner->reconnectSite($dashboardSiteId);
        }, static fn (): string => '');
    }

    /** Logged by how many, not who: client sites' administrator logins stay out of the module's log. */
    public function administrators(int $dashboardSiteId): array
    {
        return $this->logged('list administrators', 'dashboard site ' . $dashboardSiteId, fn (): array => $this->inner->administrators($dashboardSiteId), static fn (array $logins): string => count($logins) . ' administrator' . (count($logins) === 1 ? '' : 's'));
    }

    public function createAdministrator(int $dashboardSiteId, Login $account, string $email, string $firstName, string $lastName): void
    {
        $this->logged('make administrator', 'dashboard site ' . $dashboardSiteId, function () use ($dashboardSiteId, $account, $email, $firstName, $lastName): void {
            $this->inner->createAdministrator($dashboardSiteId, $account, $email, $firstName, $lastName);
        }, static fn (): string => $account->username, $account->password);
    }

    public function setSiteAdmin(int $dashboardSiteId, string $adminLogin): void
    {
        $this->logged('switch administrator', 'dashboard site ' . $dashboardSiteId, function () use ($dashboardSiteId, $adminLogin): void {
            $this->inner->setSiteAdmin($dashboardSiteId, $adminLogin);
        }, static fn (): string => 'as ' . $adminLogin);
    }

    public function suspendSite(int $dashboardSiteId): void
    {
        $this->logged('suspend site', 'dashboard site ' . $dashboardSiteId, function () use ($dashboardSiteId): void {
            $this->inner->suspendSite($dashboardSiteId);
        }, static fn (): string => '');
    }

    public function resumeSite(int $dashboardSiteId): void
    {
        $this->logged('resume site', 'dashboard site ' . $dashboardSiteId, function () use ($dashboardSiteId): void {
            $this->inner->resumeSite($dashboardSiteId);
        }, static fn (): string => '');
    }

    public function removeSite(int $dashboardSiteId): string
    {
        return $this->logged('remove site', 'dashboard site ' . $dashboardSiteId, fn (): string => $this->inner->removeSite($dashboardSiteId), static fn (string $said): string => $said);
    }

    private static function state(DashboardSite $site): string
    {
        return $site->status . ($site->lastSync !== null ? ', last sync ' . $site->lastSync : '') . ($site->syncErrors !== '' ? ', ' . $site->syncErrors : '');
    }

    /**
     * @template T
     * @param callable(): T $call
     * @param callable(T): string $done
     * @return T
     */
    private function logged(string $action, string $target, callable $call, callable $done, string ...$secrets): mixed
    {
        try {
            $result = $call();
        } catch (\Throwable $e) {
            $kind = $e instanceof DashboardFailure ? $e->kind . ': ' : '';
            $this->write($action, $target, 'failed', LogText::clean($kind . $e->getMessage(), ...$secrets));
            throw $e;
        }
        $this->write($action, $target, 'done', LogText::clean($done($result), ...$secrets));
        return $result;
    }

    private function write(string $action, string $target, string $outcome, string $detail): void
    {
        $this->storage->log(new LogEntry($this->clock->now()->format('Y-m-d H:i:s'), $this->actor, 'dashboard: ' . $action, $target, $outcome, false, $detail));
    }
}
