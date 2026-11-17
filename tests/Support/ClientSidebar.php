<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Support;

use MainWpForWhmcs\Hooks;
use WHMCS\View\Menu\Item;

/**
 * The primary sidebar of a service's product details page as WHMCS hands it to ClientAreaPrimarySidebar:
 * the stock "Service Details Overview" and "Service Details Actions" panels, with the service in
 * Menu::context('service').
 */
final class ClientSidebar
{
    public const OVERVIEW_ORDER = 10;
    public const ACTIONS_ORDER = 20;

    public static function of(?int $serviceId): Item
    {
        if ($serviceId !== null) {
            $service = new \stdClass();
            $service->id = $serviceId;
            \Menu::$contexts['service'] = $service;
        }
        $sidebar = new Item('Primary Sidebar');
        $sidebar->addChild('Service Details Overview', ['label' => 'Overview', 'order' => self::OVERVIEW_ORDER])->addChild('Information', ['uri' => '#tabOverview']);
        $sidebar->addChild('Service Details Actions', ['label' => 'Actions', 'order' => self::ACTIONS_ORDER])->addChild('Change Password', ['uri' => '#tabChangepw']);
        return $sidebar;
    }

    /** The body of the care panel the hook added for the service (found by its neutral name), or null when it added none. */
    public static function panel(int $serviceId = 1176): ?string
    {
        $sidebar = self::of($serviceId);
        Hooks::clientSidebar($sidebar);
        return $sidebar->getChild(\MainWpForWhmcs\Panels::CLIENT_PANEL)?->getBodyHtml();
    }
}
