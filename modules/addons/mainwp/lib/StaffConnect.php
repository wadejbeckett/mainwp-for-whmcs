<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\Outcome;
use MainWpForWhmcs\Engine\SiteAddress;

/**
 * The staff side of the password route: the login typed into the Connect Site form of the module's
 * Sites list, which the care panel on the admin service page links to. WHMCS has checked the CSRF token and the admin's
 * permission to edit the service; this checks that the admin's role may use the module, that the
 * add-on belongs to the service saved, and that it is Active on an Active hosting service. Every outcome goes to the module's log, which the panel shows.
 */
final class StaffConnect
{
    /** May this admin use the connect form? Only a role ticked under Configure. */
    public static function allowed(Repository $repo, ?int $adminId): bool
    {
        return $adminId !== null && Admin\Page::allowed($repo->settings()['access'] ?? '', $repo->adminRoleId($adminId));
    }

    /**
     * Connects the add-on's site with the login typed, and says what happened in one plain sentence;
     * null when no login was typed or nothing can be connected for this add-on (nothing was sent then).
     *
     * @param array<array-key, mixed> $fields address, username and password, decoded once
     */
    public static function attempt(Repository $repo, int $serviceId, ?int $adminId, int $addonRowId, array $fields): ?Outcome
    {
        $text = static fn (string $key): string => is_scalar($fields[$key] ?? null) ? (string) $fields[$key] : '';
        $username = trim($text('username'));
        $password = $text('password');
        if ($username === '' || $password === '' || !self::allowed($repo, $adminId)) {
            return null;
        }
        $settings = Settings::load($repo);
        $row = $repo->addonRow($addonRowId);
        $site = $repo->careSite($addonRowId);
        if ($row === null || $row->serviceId !== $serviceId || !in_array($row->addonId, $settings->careAddonIds, true) || $site === null || !ClientPage::connectable($site)) {
            return null;
        }
        $actor = 'admin:' . (int) $adminId;
        if (!$row->active()) {
            // The form is not shown then; a login typed into a page loaded before does not vanish without a word.
            $refused = ucfirst((string) $row->notActiveBecause()) . '. The login was not used and is not kept.';
            $repo->addLog(['created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => 'connect', 'target' => 'add-on ' . $addonRowId, 'outcome' => 'refused', 'dry_run' => $settings->dryRun ? 1 : 0, 'detail' => $refused]);
            return new Outcome(false, $refused);
        }
        if ($row->siteUrl() === '') {
            return self::refused($repo, $actor, $addonRowId, 'This hosting service has no domain, so no site can be connected to it.', $settings->dryRun);
        }
        $typed = trim($text('address'));
        $url = SiteAddress::under($typed !== '' ? $typed : (string) ($site['site_url'] ?? ''), $row->domain);
        if ($url === null) {
            $refused = $typed . ' is not part of this hosting service (' . $row->domain . '); nothing was sent';
            $repo->addLog(['created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => 'connect', 'target' => 'add-on ' . $addonRowId, 'outcome' => 'refused', 'dry_run' => $settings->dryRun ? 1 : 0, 'detail' => $refused]);
            return new Outcome(false, $refused . '.');
        }
        // One site, one care add-on: refused before anything is sent or stored, as on the client's page.
        $other = ClientPage::coveredBy($repo, $row, $url);
        if ($other !== null) {
            return self::refused($repo, $actor, $addonRowId, 'This site is already covered by add-on ' . $other . '.', $settings->dryRun);
        }
        // The same refusal as on the client's page when the site reports another address as its own.
        $elsewhere = ClientPage::notItsOwn($repo, $settings, $actor, $url, $row->domain);
        if ($elsewhere !== null) {
            return self::refused($repo, $actor, $addonRowId, $elsewhere, $settings->dryRun);
        }
        $engine = Module::engine($repo, $settings, null, $actor);
        $login = new Login($username, $password);
        $outcome = $engine->connect($addonRowId, $login, $actor, $url);
        // An address that only redirects (to its "www." form, say) is connected where the site really answers,
        // when that is an https:// address under the same service. The login was not sent to the first address.
        $canonical = !$outcome->ok && $outcome->movedTo !== null ? SiteAddress::movedWithin($outcome->movedTo, $url, $row->domain) : null;
        if ($canonical !== null) {
            $other = ClientPage::coveredBy($repo, $row, $canonical);
            if ($other !== null) {
                return self::refused($repo, $actor, $addonRowId, trim('This site is already covered by add-on ' . $other . '. ' . $outcome->note), $settings->dryRun);
            }
            $elsewhere = ClientPage::notItsOwn($repo, $settings, $actor, $canonical, $row->domain);
            if ($elsewhere !== null) {
                return self::refused($repo, $actor, $addonRowId, trim($elsewhere . ' ' . $outcome->note), $settings->dryRun);
            }
            $outcome = $engine->connect($addonRowId, $login, $actor, $canonical, $outcome->note);
        }
        if (!$outcome->ok && !$outcome->attempted && !$settings->dryRun) {
            // Another request had the site, or it is no longer waiting: say so where staff look. (An attempt at
            // an address that only redirects, just before, sent the login nowhere and did not keep it either.)
            $repo->addLog(['created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => 'connect', 'target' => 'add-on ' . $addonRowId, 'outcome' => 'refused', 'dry_run' => 0, 'detail' => $outcome->message . ' The login was not used and is not kept.']);
        }
        return $outcome;
    }

    /** A login refused before it was sent anywhere: said in the log, where the panel shows it, and to the admin. */
    private static function refused(Repository $repo, string $actor, int $addonRowId, string $why, bool $dryRun): Outcome
    {
        $repo->addLog(['created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => 'connect', 'target' => 'add-on ' . $addonRowId, 'outcome' => 'refused', 'dry_run' => $dryRun ? 1 : 0, 'detail' => $why . ' The login was not used and is not kept.']);
        return new Outcome(false, $why);
    }
}
