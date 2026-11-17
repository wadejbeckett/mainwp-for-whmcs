<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/**
 * Loads the module library. No autoloader on purpose: WHMCS modules are deployed by file copy and
 * must work without a Composer vendor directory. Keep this list in dependency order.
 */
require_once __DIR__ . '/Engine/Load.php';
require_once __DIR__ . '/AddonRow.php';
require_once __DIR__ . '/Repository.php';
require_once __DIR__ . '/ServiceName.php';
require_once __DIR__ . '/Schema.php';
require_once __DIR__ . '/Module.php';
require_once __DIR__ . '/CapsuleRepository.php';
require_once __DIR__ . '/Upgrade.php';
require_once __DIR__ . '/Encrypter.php';
require_once __DIR__ . '/WhmcsEncrypter.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/SystemClock.php';
require_once __DIR__ . '/RepositoryStorage.php';
require_once __DIR__ . '/Admin/Template.php';
require_once __DIR__ . '/Admin/Listing.php';
require_once __DIR__ . '/Admin/Status.php';
require_once __DIR__ . '/Admin/Page.php';
require_once __DIR__ . '/ClientAccess.php';
require_once __DIR__ . '/Panels.php';
require_once __DIR__ . '/ClientPage.php';
require_once __DIR__ . '/StaffConnect.php';
require_once __DIR__ . '/Lifecycle.php';
require_once __DIR__ . '/Provisioning.php';
require_once __DIR__ . '/Daily.php';
require_once __DIR__ . '/Admin/SitesList.php';
require_once __DIR__ . '/Hooks.php';
