<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

use MainWpForWhmcs\Engine\LogText;

/**
 * What hooks.php hands WHMCS's events to. Every entry point catches everything: a problem in the
 * module goes to WHMCS's activity log and never stops WHMCS's own work.
 */
final class Hooks
{
    /**
     * AddonActivated, AddonActivation: the add-on row is in 'id' (trial of 6 November 2026; WHMCS's
     * documentation). The catalogue id, status and service are read from WHMCS, not taken from the event.
     * AddonActivated also fires on every return to Active, so a suspended care site is resumed here;
     * AddonUnsuspended, which WHMCS documents for an unsuspension and the trial never saw fire, is
     * handled the same.
     *
     * @param array<string, mixed> $vars
     */
    public static function addonEvent(string $hook, array $vars): void
    {
        $id = self::id($vars['id'] ?? null);
        if ($id === null) {
            return;
        }
        self::guard($hook . ' for add-on ' . $id, static function () use ($id, $hook): void {
            $repo = Module::repository();
            self::provisionIfBilled($repo, $id, 'whmcs:' . $hook);
            Lifecycle::resumeIfActive($repo, $id, 'whmcs:' . $hook, true);
        });
    }

    /**
     * AddonSuspended: the care site is suspended on the dashboard. Seen when the status was set by
     * hand or through the API (trial of 9 November 2026).
     *
     * @param array<string, mixed> $vars
     */
    public static function addonSuspended(array $vars): void
    {
        $id = self::id($vars['id'] ?? null);
        if ($id === null) {
            return;
        }
        self::guard('AddonSuspended for add-on ' . $id, static function () use ($id): void {
            Lifecycle::suspend(Module::repository(), $id, 'whmcs:AddonSuspended');
        });
    }

    /**
     * AddonTerminated, AddonCancelled, AddonDeleted: the care site is removed from the dashboard and
     * its stored login deleted. AddonDeleted carries only 'id' and fires when the row is gone from
     * WHMCS, so nothing is read from the row: the module's own record says whether it had a care site.
     *
     * @param array<string, mixed> $vars
     */
    public static function addonEnded(string $hook, array $vars): void
    {
        $id = self::id($vars['id'] ?? null);
        if ($id === null) {
            return;
        }
        self::guard($hook . ' for add-on ' . $id, static function () use ($id, $hook): void {
            Lifecycle::remove(Module::repository(), $id, 'whmcs:' . $hook);
        });
    }

    /**
     * AfterModuleSuspend, AfterModuleUnsuspend, AfterModuleTerminate: the module parameters are in
     * 'params'. They are acted on only when 'addonId' is there and is 0, which is the hosting service
     * itself; the same events fire for an add-on's own module commands with that add-on's row id
     * (trial of 6 and 9 November 2026). An event that names neither is written to the log.
     *
     * @param array<string, mixed> $vars
     */
    public static function serviceEvent(string $hook, array $vars): void
    {
        $params = is_array($vars['params'] ?? null) ? $vars['params'] : [];
        $addonId = $params['addonId'] ?? null;
        $serviceId = self::id($params['serviceid'] ?? null);
        $itself = $addonId === 0 || $addonId === '0';
        if (!$itself && self::id($addonId) !== null) {
            return; // an add-on's own module command
        }
        if (!$itself || $serviceId === null) {
            // Not said which service or add-on: nothing can be acted on, and that must not pass unseen.
            $noIds = !array_key_exists('addonId', $params) && !array_key_exists('serviceid', $params);
            self::guard($hook . ' without a service or add-on', static function () use ($serviceId, $hook, $noIds): void {
                Lifecycle::unheard(Module::repository(), $hook, $serviceId, 'whmcs:' . $hook, $noIds);
            });
            return;
        }
        self::guard($hook . ' for service ' . $serviceId, static function () use ($serviceId, $hook): void {
            Lifecycle::service(Module::repository(), $hook, $serviceId, 'whmcs:' . $hook);
        });
    }

    /**
     * InvoicePaid: every care add-on itemised on the invoice is provisioned if it is now billed, and
     * resumed if it was suspended and is Active again.
     *
     * @param array<string, mixed> $vars
     */
    public static function invoicePaid(array $vars): void
    {
        $invoiceId = self::id($vars['invoiceid'] ?? null);
        if ($invoiceId === null) {
            return;
        }
        self::guard('InvoicePaid for invoice ' . $invoiceId, static function () use ($invoiceId): void {
            $repo = Module::repository();
            foreach ($repo->addonRowsOnInvoice($invoiceId) as $addonRowId) {
                self::provisionIfBilled($repo, $addonRowId, 'whmcs:InvoicePaid');
                // Which event fires when a payment lifts a suspension was not seen in the trial: this leads to the same decision.
                Lifecycle::resumeIfActive($repo, $addonRowId, 'whmcs:InvoicePaid');
            }
        });
    }

    /** DailyCronJob (no parameters; it runs at the very end of WHMCS's daily automation): the daily check, with its email when there is news. */
    public static function dailyCheck(): void
    {
        self::guard('the daily check', static function (): void {
            Daily::run(Module::repository(), 'cron:daily', true);
        });
    }

    /**
     * The one provisioning decision: a care add-on that is billed and has no care site (or only a
     * removed one) is provisioned; one that is not billed yet is logged as waiting; anything else,
     * including a second event for a provisioned add-on, does nothing.
     */
    public static function provisionIfBilled(Repository $repo, int $addonRowId, string $actor): void
    {
        $row = $repo->addonRow($addonRowId);
        $settings = Settings::load($repo);
        if ($row === null || !in_array($row->addonId, $settings->careAddonIds, true)) {
            return;
        }
        $site = $repo->careSite($addonRowId);
        if ($site !== null && ($site['state'] ?? null) !== Engine\State::Removed->value) {
            return;
        }
        $because = $row->notBilledBecause();
        if ($because !== null) {
            $repo->addLog(['created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => 'provision', 'target' => 'add-on ' . $addonRowId, 'outcome' => 'waiting', 'dry_run' => $settings->dryRun ? 1 : 0, 'detail' => 'not provisioned yet: ' . $because]);
            return;
        }
        Module::engine($repo, $settings, null, $actor)->provision($row->id, $row->serviceId, $row->client(), $row->siteUrl(), $actor);
    }

    /**
     * ClientAreaPrimarySidebar: the sidebar (\WHMCS\View\Menu\Item) is changed in place; WHMCS uses
     * nothing the hook returns. The service is read from Menu::context('service') by its id; on a
     * page without one the sidebar is left as it is.
     */
    public static function clientSidebar(mixed $sidebar): void
    {
        $id = null;
        self::guard('the client panel', static function () use (&$id): void {
            $id = self::serviceInMenuContext();
        });
        if ($id === null || !$sidebar instanceof \WHMCS\View\Menu\Item) {
            return;
        }
        self::guard('the client panel for service ' . $id, static function () use ($id, $sidebar): void {
            Panels::clientSidebar($sidebar, Module::repository(), $id, ClientAccess::viewer());
        });
    }

    private static function serviceInMenuContext(): ?int
    {
        if (!class_exists(\Menu::class)) {
            return null;
        }
        $service = \Menu::context('service');
        return is_object($service) ? self::id($service->id ?? null) : null;
    }

    /**
     * AdminClientServicesTabFields: the service id is in 'id'.
     *
     * @param array<string, mixed> $vars
     * @return array<string, string>
     */
    public static function adminServiceFields(array $vars): array
    {
        $id = self::id($vars['id'] ?? null);
        if ($id === null) {
            return [];
        }
        $fields = [];
        self::guard('the staff panel for service ' . $id, static function () use ($id, &$fields): void {
            $fields = Panels::admin(Module::repository(), $id, self::adminId());
        });
        return $fields;
    }

    private static function adminId(): ?int
    {
        $id = \WHMCS\Session::get('adminid');
        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /** @param \Closure(): void $work */
    private static function guard(string $what, \Closure $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            \logActivity(Module::DISPLAY_NAME . ': ' . $what . ' failed: ' . LogText::clean($e->getMessage()));
        }
    }

    private static function id(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }
}
