<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use MainWpForWhmcs\Hooks;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Bootstrap.php';

// WHMCS calls the provisioning module (modules/servers/mainwp) for its own create, suspend, unsuspend
// and terminate of a care add-on. These hooks cover what WHMCS changes without calling it: a status set
// by hand or through the API, the hosting service's own module commands, payment after a create that
// waited for it, and a deleted add-on.
// Provisioning (SPEC.md, "What the WHMCS layer listens to"): which of AddonActivated, AddonActivation
// and InvoicePaid fires at payment was not seen in the trial, so all three lead to the same decision.
// The same events, and AddonUnsuspended, resume a suspended care site whose add-on is Active again.
foreach (['AddonActivated', 'AddonActivation', 'AddonUnsuspended'] as $mainwpHook) {
    add_hook($mainwpHook, 1, static function (array $vars) use ($mainwpHook): void {
        Hooks::addonEvent($mainwpHook, $vars);
    });
}
add_hook('InvoicePaid', 1, static function (array $vars): void {
    Hooks::invoicePaid($vars);
});

// Suspending, resuming and removing (SPEC.md, "Suspending, resuming, removing") when the status is set
// by hand or through the API; WHMCS's own suspend, unsuspend and terminate call the provisioning module
// instead. A return to Active fires AddonActivated, registered above. The service's own module events
// are heard because a service suspended by its module fires no add-on event and leaves the add-on's
// status as it was; an add-on's own module command fires them too, with its row id, and is skipped.
add_hook('AddonSuspended', 1, static function (array $vars): void {
    Hooks::addonSuspended($vars);
});
foreach (['AddonTerminated', 'AddonCancelled', 'AddonDeleted'] as $mainwpHook) {
    add_hook($mainwpHook, 1, static function (array $vars) use ($mainwpHook): void {
        Hooks::addonEnded($mainwpHook, $vars);
    });
}
foreach (['AfterModuleSuspend', 'AfterModuleUnsuspend', 'AfterModuleTerminate'] as $mainwpHook) {
    add_hook($mainwpHook, 1, static function (array $vars) use ($mainwpHook): void {
        Hooks::serviceEvent($mainwpHook, $vars);
    });
}

// The daily check (SPEC.md, "Daily check").
add_hook('DailyCronJob', 1, static function (): void {
    Hooks::dailyCheck();
});

// The panels: the client's in the sidebar of the service's product details page, which WHMCS's own
// themes (Nexus, Twenty-One and Six) draw whatever the service's server module (a Plesk service's own
// overview prints no module output), and the staff's on the admin service page.
add_hook('ClientAreaPrimarySidebar', 1, static function ($primarySidebar): void {
    Hooks::clientSidebar($primarySidebar);
});
add_hook('AdminClientServicesTabFields', 1, static function (array $vars): array {
    return Hooks::adminServiceFields($vars);
});
