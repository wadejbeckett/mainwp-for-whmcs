<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\CareSite;
use MainWpForWhmcs\Engine\State;

/** A care site's other administrators: the logins the dashboard can be pointed at when the care account is gone */
final class CareSiteTest extends TestCase
{
    private static function site(): CareSite
    {
        return new CareSite(68, 1176, 540, 'https://clientsite.example', State::Connected, 100, 7, 1, '2026-11-10 00:00:05', null, null);
    }

    public function testOtherAdministratorsAreNeverRecordedUntilTheyAre(): void
    {
        self::assertNull(self::site()->otherAdmins);
        self::assertSame(['owner', 'editor'], self::site()->withOtherAdmins(['owner', 'editor'])->otherAdmins);
        self::assertSame([], self::site()->withOtherAdmins([])->otherAdmins, 'none is a record too');
        self::assertNull(self::site()->withOtherAdmins(['owner'])->withOtherAdmins(null)->otherAdmins);
    }

    public function testRecordingThemChangesNothingElse(): void
    {
        $site = self::site();
        $recorded = $site->withOtherAdmins(['owner']);

        self::assertEquals($site, $recorded->withOtherAdmins(null));
        self::assertNotSame($site, $recorded);
    }

    public function testEveryOtherCopyKeepsThem(): void
    {
        $site = self::site()->withOtherAdmins(['owner', 'editor']);

        foreach ([
            'withState' => $site->withState(State::Failed, 'reason'),
            'at' => $site->at('https://www.clientsite.example'),
            'retried' => $site->retried(),
            'connecting' => $site->connecting(),
            'offDashboard' => $site->offDashboard(),
            'uncounted' => $site->uncounted(),
            'inFlight' => $site->inFlight('2026-11-10 14:00:00'),
            'attempting' => $site->attempting(),
            'failed' => $site->failed('reason'),
            'connectedAs' => $site->connectedAs(9, '2026-11-10 14:00:00'),
            'removed' => $site->removed(),
            'failedWithRetry' => $site->failedWithRetry('reason', '2026-11-10 14:00:00'),
        ] as $copy => $copied) {
            self::assertSame(['owner', 'editor'], $copied->otherAdmins, $copy);
        }
    }
}
