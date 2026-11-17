<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

use MainWpForWhmcs\Engine\LogText;
use MainWpForWhmcs\Engine\Outcome;
use MainWpForWhmcs\Engine\State;

/**
 * What the provisioning module (modules/servers/mainwp) hands WHMCS's calls to: CreateAccount,
 * SuspendAccount, UnsuspendAccount and TerminateAccount for a care add-on. Each answer
 * is 'success' or an error text; on an error WHMCS keeps the add-on's status and holds the command in
 * its Module Queue for Retry. While the add-on module is deactivated (WHMCS has removed its settings),
 * every command answers that it is not active. Otherwise CreateAccount answers 'success' for every care add-on: one not billed yet
 * waits, and a dashboard failure is logged, and InvoicePaid or the daily check provisions it later, so
 * a sale is never left Pending, and a fault after the add-on row is found is logged and answered
 * 'success' too (settlement point 1). Suspend, unsuspend and terminate answer the engine's error text
 * when the dashboard, the lock or a fault stopped them. Nothing is kept between calls, and nothing escapes
 * to WHMCS: no exception, no password, key, token or file path.
 */
final class Provisioning
{
    private const ACTORS = ['create' => 'whmcs:CreateAccount', 'suspend' => 'whmcs:SuspendAccount', 'unsuspend' => 'whmcs:UnsuspendAccount', 'terminate' => 'whmcs:TerminateAccount'];

    /**
     * @param 'create'|'suspend'|'unsuspend'|'terminate' $command
     * @param array<string, mixed> $params WHMCS's module parameters: the add-on row in addonId, the parent service in serviceid
     */
    public static function run(string $command, array $params): string
    {
        // Deactivating the add-on module removes its settings rows (and keeps its tables). Without them
        // nothing can be decided: an error keeps the status in WHMCS and the command in its Module Queue.
        try {
            $active = Module::repository()->settings() !== [];
        } catch (\Throwable) {
            $active = true; // the fault is answered below, with the add-on it was for
        }
        if (!$active) {
            return 'The add-on module ' . Module::DISPLAY_NAME . ' is not active.';
        }
        $value = $params['addonId'] ?? null;
        $id = (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
        if ($id === null) {
            return Module::DISPLAY_NAME . ' works only on a product add-on (the care add-on\'s Module Settings), not on a product.';
        }
        try {
            return self::decide($command, $id);
        } catch (\Throwable $e) {
            return Module::DISPLAY_NAME . ' could not ' . $command . ' care for add-on ' . $id . ': ' . LogText::clean($e->getMessage());
        }
    }

    /** @param 'create'|'suspend'|'unsuspend'|'terminate' $command */
    private static function decide(string $command, int $id): string
    {
        $repo = Module::repository();
        // Everything is read fresh from WHMCS, never from the params: their status is the one before the action.
        $row = $repo->addonRow($id);
        if ($row === null) {
            return 'Add-on ' . $id . ' was not found in WHMCS.';
        }
        $settings = Settings::load($repo);
        $actor = self::ACTORS[$command];
        return match ($command) {
            'create' => self::createOrLog($repo, $settings, $row, $actor),
            'suspend' => self::answer(Module::engine($repo, $settings, null, $actor)->suspend($id, $actor)),
            'unsuspend' => self::unsuspend($repo, $settings, $row, $actor),
            'terminate' => self::answer(Module::engine($repo, $settings, null, $actor)->remove($id, $actor)),
        };
    }

    /**
     * Settlement point 1: once the add-on row is found, CreateAccount answers 'success' even for
     * a fault, so that a sale is never left Pending; the fault is logged as "failed" when the log can be
     * written, and InvoicePaid or the daily check provisions the care site later.
     */
    private static function createOrLog(Repository $repo, Settings $settings, AddonRow $row, string $actor): string
    {
        try {
            return self::create($repo, $settings, $row, $actor);
        } catch (\Throwable $e) {
            try {
                $repo->addLog(['created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => 'provision', 'target' => 'add-on ' . $row->id, 'outcome' => 'failed', 'dry_run' => $settings->dryRun ? 1 : 0, 'detail' => 'not provisioned: ' . LogText::clean($e->getMessage())]);
            } catch (\Throwable) {
                // The log cannot be written either; the daily check still finds the add-on without a care site.
            }
            return 'success';
        }
    }

    /**
     * The row is still Pending when WHMCS calls. Decision 017's "active and paid", read as "billed once
     * WHMCS sets it Active". Always 'success': the engine's "failed" row says what went wrong, and
     * InvoicePaid or the daily check provisions the care site later.
     */
    private static function create(Repository $repo, Settings $settings, AddonRow $row, string $actor): string
    {
        $site = $repo->careSite($row->id);
        if ($site !== null && ($site['state'] ?? null) !== State::Removed->value) {
            return 'success'; // read first, so that dry run writes no "would run" row for a provisioned add-on
        }
        $because = $row->notBilledOnceActiveBecause();
        if ($because !== null) {
            self::waiting($repo, $settings, $actor, 'provision', $row->id, 'not provisioned yet: ' . $because);
            return 'success';
        }
        Module::engine($repo, $settings, null, $actor)->provision($row->id, $row->serviceId, $row->client(), $row->siteUrl(), $actor);
        return 'success';
    }

    /**
     * The row still reads Suspended: the call itself says it becomes Active, so its own status is not
     * asked. Care is not resumed under a hosting service that is not Active; the service's own
     * AfterModuleUnsuspend resumes it then (Lifecycle::service). An open invoice does not stop it.
     */
    private static function unsuspend(Repository $repo, Settings $settings, AddonRow $row, string $actor): string
    {
        $site = $repo->careSite($row->id);
        if ($site !== null && ($site['state'] ?? null) === State::Suspended->value && $row->serviceStatus !== 'Active') {
            self::waiting($repo, $settings, $actor, 'resume', $row->id, 'not resumed: its hosting service is ' . $row->serviceStatus . ', not Active');
            return 'success';
        }
        return self::answer(Module::engine($repo, $settings, null, $actor)->resume($row->id, $actor));
    }

    private static function answer(Outcome $outcome): string
    {
        return $outcome->ok ? 'success' : LogText::clean($outcome->message);
    }

    /** A waiting row, worded as Hooks::provisionIfBilled and Lifecycle write theirs. */
    private static function waiting(Repository $repo, Settings $settings, string $actor, string $action, int $id, string $detail): void
    {
        $repo->addLog(['created_at' => date('Y-m-d H:i:s'), 'actor' => $actor, 'action' => $action, 'target' => 'add-on ' . $id, 'outcome' => 'waiting', 'dry_run' => $settings->dryRun ? 1 : 0, 'detail' => $detail]);
    }
}
