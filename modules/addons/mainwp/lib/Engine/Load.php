<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/** Loads the care engine alone: plain PHP, no WHMCS. Another billing system requires only this file. */

require_once __DIR__ . '/State.php';
require_once __DIR__ . '/Client.php';
require_once __DIR__ . '/Login.php';
require_once __DIR__ . '/CareSite.php';
require_once __DIR__ . '/LogEntry.php';
require_once __DIR__ . '/Outcome.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/HttpResponse.php';
require_once __DIR__ . '/HttpTimeout.php';
require_once __DIR__ . '/HttpTooLarge.php';
require_once __DIR__ . '/DashboardFailure.php';
require_once __DIR__ . '/DashboardSite.php';
require_once __DIR__ . '/DashboardClient.php';
require_once __DIR__ . '/Dashboard.php';
require_once __DIR__ . '/MainWpDashboard.php';
require_once __DIR__ . '/CurlHttp.php';
require_once __DIR__ . '/LogText.php';
require_once __DIR__ . '/LoggedDashboard.php';
require_once __DIR__ . '/SiteAddress.php';
require_once __DIR__ . '/SiteFailure.php';
require_once __DIR__ . '/SiteIndex.php';
require_once __DIR__ . '/Site.php';
require_once __DIR__ . '/WordPressSite.php';
require_once __DIR__ . '/Approval.php';
require_once __DIR__ . '/HeldKey.php';
require_once __DIR__ . '/RouteOffer.php';
require_once __DIR__ . '/ApprovalStart.php';
require_once __DIR__ . '/ApprovalReturn.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/Clock.php';
require_once __DIR__ . '/Secrets.php';
require_once __DIR__ . '/RandomSecrets.php';
require_once __DIR__ . '/AddonState.php';
require_once __DIR__ . '/ReportLine.php';
require_once __DIR__ . '/Report.php';
require_once __DIR__ . '/CareEngine.php';
require_once __DIR__ . '/DailyRun.php';
require_once __DIR__ . '/DailyCheck.php';
