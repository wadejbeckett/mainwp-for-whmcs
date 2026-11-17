<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * MainWP for WHMCS's provisioning module: selected on a care product add-on's
 * Module Settings, it is what WHMCS itself calls to create, suspend, unsuspend and terminate
 * care. Each command hands the call to the add-on module's library (lib/Provisioning.php), which does
 * the work through the same engine.
 *
 * Nothing is required at file level: WHMCS loads this file in the cron's Overdue Suspensions and when
 * it lists server modules, and a missing, half-copied or older add-on folder must not be a fatal error
 * there. Each command checks that the library is there before it loads it, and loads and calls it
 * inside a try, so that a missing file or class gives an error text (with no path) instead.
 *
 * Never declare mainwp_ClientArea here: PHP function names ignore case, and it is the add-on module's
 * mainwp_clientarea.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** @return array<string, mixed> */
function mainwp_MetaData(): array
{
    // A literal, so that it answers without the add-on module's library.
    return ['DisplayName' => 'MainWP for WHMCS', 'APIVersion' => '1.1', 'RequiresServer' => false];
}

/** @param array<string, mixed> $params */
function mainwp_CreateAccount(array $params): string
{
    $lib = __DIR__ . '/../../addons/mainwp/lib/Bootstrap.php';
    if (!is_file($lib)) {
        return "MainWP for WHMCS: the add-on module's files are missing; nothing was done.";
    }
    try {
        require_once $lib; // the add-on module's library: one engine and one copy of every class
        return \MainWpForWhmcs\Provisioning::run('create', $params);
    } catch (\Throwable) {
        return "MainWP for WHMCS: the add-on module's files are incomplete or of another version; nothing was done.";
    }
}

/** @param array<string, mixed> $params */
function mainwp_SuspendAccount(array $params): string
{
    $lib = __DIR__ . '/../../addons/mainwp/lib/Bootstrap.php';
    if (!is_file($lib)) {
        return "MainWP for WHMCS: the add-on module's files are missing; nothing was done.";
    }
    try {
        require_once $lib;
        return \MainWpForWhmcs\Provisioning::run('suspend', $params);
    } catch (\Throwable) {
        return "MainWP for WHMCS: the add-on module's files are incomplete or of another version; nothing was done.";
    }
}

/** @param array<string, mixed> $params */
function mainwp_UnsuspendAccount(array $params): string
{
    $lib = __DIR__ . '/../../addons/mainwp/lib/Bootstrap.php';
    if (!is_file($lib)) {
        return "MainWP for WHMCS: the add-on module's files are missing; nothing was done.";
    }
    try {
        require_once $lib;
        return \MainWpForWhmcs\Provisioning::run('unsuspend', $params);
    } catch (\Throwable) {
        return "MainWP for WHMCS: the add-on module's files are incomplete or of another version; nothing was done.";
    }
}

/** @param array<string, mixed> $params */
function mainwp_TerminateAccount(array $params): string
{
    $lib = __DIR__ . '/../../addons/mainwp/lib/Bootstrap.php';
    if (!is_file($lib)) {
        return "MainWP for WHMCS: the add-on module's files are missing; nothing was done.";
    }
    try {
        require_once $lib;
        return \MainWpForWhmcs\Provisioning::run('terminate', $params);
    } catch (\Throwable) {
        return "MainWP for WHMCS: the add-on module's files are incomplete or of another version; nothing was done.";
    }
}
