<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * A MainWP dashboard, reached through MainWP's REST API. Every method throws a DashboardFailure,
 * with its kind and a plain-words reason, when the dashboard refuses or cannot be reached.
 */
interface Dashboard
{
    /** @return array{clients: int, sites: int} how many clients and sites the dashboard holds */
    public function counts(): array;

    /** @return list<DashboardClient> */
    public function clients(): array;

    /** The dashboard client that carries this billing-system client id in its note, or null. */
    public function findClient(int $billingClientId): ?int;

    /** Creates a dashboard client with the billing-system client id in its note; returns the dashboard client id. */
    public function createClient(int $billingClientId, string $name, string $email): int;

    /** @return list<DashboardSite> */
    public function sites(): array;

    /** One site's connection state and last sync. */
    public function site(int $dashboardSiteId): DashboardSite;

    /** Has the dashboard connect the site as $login and file it under the client; returns the dashboard site id. */
    public function addSite(int $dashboardClientId, string $url, Login $login): int;

    /** Has the dashboard sync the site now; returns its state after the sync. */
    public function syncSite(int $dashboardSiteId): DashboardSite;

    /**
     * Has the dashboard mend its connection to the site: a sync, and when that fails new keys with
     * the administrator it connected as. No login is sent. It cannot be done when MainWP's plugin is
     * switched off on the site or that administrator is gone (seen on a test bench).
     */
    public function reconnectSite(int $dashboardSiteId): void;

    /**
     * The login names of the site's administrators as MainWP lists them now, in its order: a live
     * call through the dashboard to the site. A site the dashboard cannot reach (its last sync failed,
     * or it is suspended) is refused (REFUSED).
     *
     * @return list<string>
     */
    public function administrators(int $dashboardSiteId): array;

    /**
     * Makes an administrator on the site through the dashboard, with this login, email and first and
     * last name (WordPress makes the display name of the two). Nothing is mailed. The site's own
     * refusal (a login or an address already taken) is REFUSED with the site's words, in the site's
     * language; so is a site the dashboard cannot reach. No message holds the password.
     */
    public function createAdministrator(int $dashboardSiteId, Login $account, string $email, string $firstName, string $lastName): void;

    /** Points the dashboard's site at another administrator of the site, by login name; no password is involved. */
    public function setSiteAdmin(int $dashboardSiteId, string $adminLogin): void;

    public function suspendSite(int $dashboardSiteId): void;

    public function resumeSite(int $dashboardSiteId): void;

    /**
     * Takes the site off the dashboard and returns what MainWP said about it, in its own words:
     * a normal removal says that its plugin "has been disabled" on the site; a site MainWP has
     * flagged as staging is dropped without that (seen on a test bench).
     */
    public function removeSite(int $dashboardSiteId): string;
}
