<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

use MainWpForWhmcs\Engine\State;

/**
 * What WHMCS's status events do to a care site (SPEC.md, "Suspending, resuming, removing"): a
 * suspended add-on or hosting service suspends the site on the dashboard, a return to Active
 * resumes it, and an add-on or service that ends removes it. These are the changes WHMCS makes
 * without calling the module (a status set by hand or through the API, the hosting service's own
 * module commands, a deleted add-on); its own create, suspend, unsuspend and terminate of a care
 * add-on call the provisioning module (Provisioning). The module's own record says whether
 * an add-on row has a care site; a row without one is not the module's business. Nothing here
 * changes anything in WHMCS.
 */
final class Lifecycle
{
    /** The add-on was suspended. */
    public static function suspend(Repository $repo, int $addonRowId, string $actor): void
    {
        if ($repo->careSite($addonRowId) !== null) {
            Module::engine($repo, Settings::load($repo), null, $actor)->suspend($addonRowId, $actor);
        }
    }

    /**
     * The add-on is (or may be) Active again: a suspended site is resumed when the add-on and its
     * hosting service are both Active. A site that is not suspended is left alone, so this is safe
     * to ask on every activation and every paid invoice. $saidActive: the event itself says the
     * add-on is Active, so its row is not asked (WHMCS may not have written the new status yet).
     */
    public static function resumeIfActive(Repository $repo, int $addonRowId, string $actor, bool $saidActive = false): void
    {
        $site = $repo->careSite($addonRowId);
        if ($site === null || ($site['state'] ?? null) !== State::Suspended->value) {
            return;
        }
        $settings = Settings::load($repo);
        $row = $repo->addonRow($addonRowId);
        $because = match (true) {
            $row === null => 'the add-on is no longer in the billing system',
            !$saidActive && $row->status !== 'Active' => 'the add-on is ' . $row->status . ', not Active',
            $row->serviceStatus !== 'Active' => 'its hosting service is ' . $row->serviceStatus . ', not Active',
            default => null,
        };
        if ($because !== null) {
            self::waiting($repo, $settings, $addonRowId, $actor, $because);
            return;
        }
        Module::engine($repo, $settings, null, $actor)->resume($addonRowId, $actor);
    }

    /** The add-on was terminated, cancelled or deleted. */
    public static function remove(Repository $repo, int $addonRowId, string $actor): void
    {
        if ($repo->careSite($addonRowId) !== null) {
            Module::engine($repo, Settings::load($repo), null, $actor)->remove($addonRowId, $actor);
        }
    }

    /**
     * The hosting service itself was suspended, unsuspended or terminated by its module: every care
     * site under it is suspended or removed, and on unsuspension those whose own care add-on is
     * Active are resumed. The service's own status is not read: the event says what happened, and
     * WHMCS may not have written the new status yet when it fires.
     */
    public static function service(Repository $repo, string $hook, int $serviceId, string $actor): void
    {
        $settings = Settings::load($repo);
        $engine = null;
        foreach ($repo->careSites() as $site) {
            $id = (int) ($site['addon_row_id'] ?? 0);
            if ($id <= 0 || (int) ($site['service_id'] ?? 0) !== $serviceId || ($site['state'] ?? null) === State::Removed->value) {
                continue;
            }
            $engine ??= Module::engine($repo, $settings, null, $actor);
            if ($hook === 'AfterModuleSuspend') {
                $engine->suspend($id, $actor);
            } elseif ($hook === 'AfterModuleTerminate') {
                $engine->remove($id, $actor);
            } elseif ($hook === 'AfterModuleUnsuspend' && ($site['state'] ?? null) === State::Suspended->value) {
                $row = $repo->addonRow($id);
                if ($row !== null && $row->status === 'Active') {
                    $engine->resume($id, $actor);
                } else {
                    self::waiting($repo, $settings, $id, $actor, $row === null ? 'the add-on is no longer in the billing system' : 'the add-on is ' . $row->status . ', not Active');
                }
            }
        }
    }

    /**
     * A service's module event that does not say which service or add-on it is about. WHMCS fired
     * module events with no parameters when a command was retried from its Module Queue (trial of
     * 9 November 2026), so a suspension, an unsuspension or a termination may have happened that
     * no care site was changed for. It is written to the log, where the daily check and staff find
     * it; a service that is named and has no care site is nobody's business here. $noIds: the
     * event carried neither a service id nor an addonId, which is the Module Queue's shape. A Retry
     * of the provisioning module's own command may arrive here too; that false alarm is accepted,
     * since nothing can tell it from a parent's retried command.
     */
    public static function unheard(Repository $repo, string $hook, ?int $serviceId, string $actor, bool $noIds = false): void
    {
        if ($serviceId !== null && array_filter($repo->careSites(), static fn (array $site): bool => (int) ($site['service_id'] ?? 0) === $serviceId && ($site['state'] ?? null) !== State::Removed->value) === []) {
            return;
        }
        $action = ['AfterModuleSuspend' => 'suspend', 'AfterModuleUnsuspend' => 'resume', 'AfterModuleTerminate' => 'remove'][$hook] ?? 'suspend';
        $repo->addLog([
            'created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => $action, 'target' => $serviceId !== null ? 'service ' . $serviceId : 'a hosting service', 'outcome' => 'failed',
            'dry_run' => Settings::load($repo)->dryRun ? 1 : 0,
            'detail' => match (true) {
                $serviceId !== null => $hook . ' named service ' . $serviceId . ' but not whether it was for the service itself or one of its add-ons, so no care site was changed. Check the care add-ons of service ' . $serviceId . '.',
                $noIds => $hook . ' did not say which service or add-on it was for (WHMCS does this when it retries a command from its Module Queue), so no care site was changed. If that service has care add-ons, their sites may need the same done.',
                default => $hook . ' did not say which service it was for, so no care site was changed. If that service has care add-ons, their sites may need the same done.',
            },
        ]);
    }

    /** A suspended site that stays suspended, with the reason, so that the log answers "why was it not resumed". */
    private static function waiting(Repository $repo, Settings $settings, int $addonRowId, string $actor, string $because): void
    {
        $repo->addLog(['created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => 'resume', 'target' => 'add-on ' . $addonRowId, 'outcome' => 'waiting', 'dry_run' => $settings->dryRun ? 1 : 0, 'detail' => 'not resumed: ' . $because]);
    }
}
