<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use MainWpForWhmcs\Engine\LogText;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Bootstrap.php';

/**
 * The Configure form under System Settings > Addon Modules: the access list, which WHMCS adds
 * itself, and the delete switch. Every other setting is edited on the module's own page, so no
 * setting has two homes.
 *
 * @return array<string, mixed>
 */
function mainwp_config(): array
{
    return [
        'name' => Module::DISPLAY_NAME,
        'description' => 'Puts the WordPress site of each paid care add-on on your MainWP dashboard and keeps it in step with billing. Choose ' . Module::DISPLAY_NAME . ' on a product add-on\'s Module Settings to make it a care add-on.',
        'version' => Module::VERSION,
        'author' => 'Noiz',
        'fields' => [
            'delete_data' => ['FriendlyName' => 'Delete module data on deactivate', 'Type' => 'yesno', 'Description' => 'The care sites, approval tokens and log are removed on deactivate only when this is ticked. Nothing on the dashboard or on any site is changed by deactivating.'],
        ],
    ];
}

/** @return array{status: string, description: string} */
function mainwp_activate(): array
{
    try {
        $repo = Module::repository();
        Schema::install($repo);
        // Tables kept from an earlier version (deactivated without deleting its data, then the files replaced) are
        // brought up to date here: WHMCS calls no upgrade for a module that is not active. Every step skips itself
        // when it is already applied, so a fresh install changes nothing.
        foreach (\MainWpForWhmcs\Upgrade::run($repo, '0.0.0') as $change) {
            \logActivity(Module::DISPLAY_NAME . ' activated over the tables of an earlier version: ' . $change);
        }
        $stored = $repo->settings();
        $defaults = [];
        if (!isset($stored['dry_run'])) {
            $defaults['dry_run'] = 'on'; // a fresh install starts in dry-run by a stored value, not a default
        }
        if ($defaults !== []) {
            $repo->saveSettings($defaults);
        }
        return ['status' => 'success', 'description' => Module::DISPLAY_NAME . ' activated in dry-run mode. Give your role access under Configure, then fill in the settings under Addons > ' . Module::DISPLAY_NAME . '.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => Module::DISPLAY_NAME . ' could not create its tables: ' . LogText::clean($e->getMessage())];
    }
}

/** @return array{status: string, description: string} */
function mainwp_deactivate(): array
{
    try {
        $repo = Module::repository();
        if (!Module::yes($repo->settings()['delete_data'] ?? null)) {
            return ['status' => 'success', 'description' => Module::DISPLAY_NAME . ' deactivated. Its tables (care sites, approval tokens, log) were kept; tick "Delete module data on deactivate" first to remove them. They hold the care accounts\' logins, encrypted. WHMCS removes the module\'s settings on deactivation: enter them again if you activate it again. Nothing on the dashboard changed.'];
        }
        Schema::uninstall($repo);
        return ['status' => 'success', 'description' => Module::DISPLAY_NAME . ' deactivated and its tables removed. Nothing on the dashboard changed.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => Module::DISPLAY_NAME . ' deactivation problem: ' . LogText::clean($e->getMessage())];
    }
}

/**
 * Called by WHMCS when the installed version ($vars['version']) is older than Module::VERSION.
 * Each change goes to the activity log; a step already applied is skipped, so a re-run is safe.
 *
 * @param array<string, mixed> $vars
 * @return array{status: string, description: string}
 */
function mainwp_upgrade(array $vars): array
{
    $from = is_scalar($vars['version'] ?? null) ? (string) $vars['version'] : 'unknown';
    try {
        $changes = \MainWpForWhmcs\Upgrade::run(Module::repository(), $from);
        foreach ($changes as $change) {
            \logActivity(Module::DISPLAY_NAME . ' upgrade to ' . Module::VERSION . ': ' . $change);
        }
        $summary = $changes === [] ? 'no schema change' : count($changes) . ' change' . (count($changes) === 1 ? '' : 's');
        \logActivity(Module::DISPLAY_NAME . ' upgraded from ' . $from . ' to ' . Module::VERSION . ' (' . $summary . ')');
        return ['status' => 'success', 'description' => Module::DISPLAY_NAME . ' upgraded from ' . $from . ' to ' . Module::VERSION . '. ' . ($changes === [] ? 'No tables changed.' : ucfirst(implode('; ', $changes)) . '.')];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => Module::DISPLAY_NAME . ' upgrade problem: ' . LogText::clean($e->getMessage())];
    }
}

/**
 * The module's page under Addons. A POST is checked against WHMCS's CSRF token first.
 *
 * @param array<string, mixed> $vars
 */
function mainwp_output(array $vars): void
{
    $method = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 'POST' : 'GET';
    if ($method === 'POST') {
        check_token('WHMCS.admin.default');
    }
    try {
        $adminId = \WHMCS\Session::get('adminid');
        $token = preg_match('/value="([^"]*)"/', generate_token('form'), $m) === 1 ? $m[1] : '';
        echo \MainWpForWhmcs\Admin\Page::render(Module::repository(), new \MainWpForWhmcs\WhmcsEncrypter(), is_numeric($adminId) ? (int) $adminId : null, (string) ($vars['modulelink'] ?? ''), $method, \MainWpForWhmcs\Admin\Page::decodeInput($_POST), $token, \MainWpForWhmcs\Admin\Page::decodeInput($_GET));
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger">' . Module::DISPLAY_NAME . ' could not show this page: ' . htmlspecialchars(LogText::clean($e->getMessage()), ENT_QUOTES, 'UTF-8') . '</div>';
    }
}

/**
 * The module's client page (lib/ClientPage.php): index.php?m=mainwp&addon=<add-on row> with the two
 * ways to connect a site, and the addresses a client's own site sends their browser back to. Those
 * need no login: they work from the one-time token. A POST is checked against WHMCS's CSRF token
 * first. A problem shows the page as not available and goes to WHMCS's activity log, never a
 * password or the key.
 *
 * PHP function names ignore case, so this is also the provisioning module's mainwp_ClientArea
 * (modules/servers/mainwp, which never declares it). When WHMCS calls it as that, with an add-on's
 * module parameters and no modulelink, it answers '' at once.
 *
 * @param array<string, mixed> $vars
 * @return array<string, mixed>|string
 */
function mainwp_clientarea(array $vars): array|string
{
    if (!array_key_exists('modulelink', $vars) && (array_key_exists('addonId', $vars) || array_key_exists('model', $vars))) {
        return '';
    }
    $method = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? 'POST' : 'GET';
    $returning = \MainWpForWhmcs\ClientPage::returning($_GET);
    try {
        if ($method === 'POST') {
            check_token('WHMCS.default');
        }
        if ($method === 'POST' || $returning) {
            // Connecting a site takes a while; a closed tab must not cut it off halfway.
            ignore_user_abort(true);
            if (function_exists('set_time_limit')) {
                @set_time_limit(180);
            }
        }
        $token = generate_token('plain');
        $token = preg_match('/value="([^"]*)"/', $token, $m) === 1 ? $m[1] : $token;
        $data = \MainWpForWhmcs\ClientPage::vars(Module::repository(), \MainWpForWhmcs\ClientAccess::viewer(), $method, \MainWpForWhmcs\Admin\Page::decodeInput($_GET), \MainWpForWhmcs\Admin\Page::decodeInput($_POST), $token);
    } catch (\Throwable $e) {
        \logActivity(Module::DISPLAY_NAME . ': the client page failed: ' . LogText::clean($e->getMessage()));
        // A return never stays on the address that holds the key, even when handling it failed.
        $data = ($returning ? ['available' => true, 'outcomeOnly' => true, 'redirect' => 'index.php?m=' . Module::NAME . '&' . \MainWpForWhmcs\ClientPage::Q_RESULT . '=none&' . \MainWpForWhmcs\ClientPage::Q_NOTE . '=error'] : []) + \MainWpForWhmcs\ClientPage::NOTHING;
    }
    if (is_string($data['redirect'] ?? null) && $data['redirect'] !== '') {
        Module::redirect($data['redirect']);
    }
    // The name of the add-on the page is for, as the client bought it; the fallback where none is at hand.
    $name = is_string($data['name'] ?? null) && $data['name'] !== '' ? $data['name'] : \MainWpForWhmcs\ClientPage::fallbackName();
    $data['name'] = $name;
    return [
        'pagetitle' => \MainWpForWhmcs\ServiceName::forTheme($name),
        'breadcrumb' => ['index.php?m=' . Module::NAME => \MainWpForWhmcs\ServiceName::forTheme($name)],
        'templatefile' => 'client-connect',
        'requirelogin' => !$returning && !isset(\MainWpForWhmcs\ClientPage::query($_GET)[\MainWpForWhmcs\ClientPage::Q_RESULT]),
        'vars' => $data,
    ];
}
