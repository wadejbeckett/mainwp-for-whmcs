<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Admin;

use MainWpForWhmcs\Admin\Status;
use MainWpForWhmcs\Engine\State;
use PHPUnit\Framework\TestCase;

/** The words staff read for a site: the module's states by their own names, MainWP's words where it has them. */
final class StatusTest extends TestCase
{
    public function testEveryStateOfACareSiteHasAStatusByItsOwnName(): void
    {
        foreach (State::cases() as $state) {
            self::assertSame($state->value, Status::of($state));
            self::assertSame(ucfirst($state->value), Status::label(Status::of($state)), 'the glossary\'s word');
        }
    }

    public function testABilledAddOnWithoutACareSiteIsNotProvisioned(): void
    {
        self::assertSame('Not provisioned', Status::label(Status::of(null)));
    }

    public function testAConnectedSiteTheDashboardCannotReachIsDisconnectedInMainWpsWord(): void
    {
        self::assertSame('Disconnected', Status::label(Status::of(State::Connected, true)));
        foreach ([State::Provisioned, State::Connecting, State::Failed, State::Suspended, State::Removed] as $state) {
            self::assertSame($state->value, Status::of($state, true), 'only a connected site can be disconnected');
        }
    }

    public function testEveryStatusHasOneOfWhmcssOwnLabelColoursAndALineForTheKey(): void
    {
        self::assertSame(['Not provisioned', 'Provisioned', 'Connecting', 'Connected', 'Disconnected', 'Failed', 'Suspended', 'Removed'], array_column(Status::ALL, 'label'));
        foreach (Status::ALL as $status => $words) {
            self::assertContains($words['class'], ['pending', 'active', 'suspended', 'terminated', 'cancelled'], 'a status class both admin themes colour (span.label.<class>)');
            self::assertMatchesRegularExpression('/^[A-Z][^.]*\.( [A-Z][^.]*\.)?$/', $words['means'], $status . ': one or two plain sentences');
            self::assertLessThanOrEqual(90, strlen($words['means']), $status);
            self::assertStringNotContainsString('dashboard', $words['means'], 'the dashboard is called by its name: MainWP');
        }
        self::assertSame(['active', 'terminated', 'suspended'], [Status::ALL['connected']['class'], Status::ALL['disconnected']['class'], Status::ALL['suspended']['class']], 'green, red and blue as on the dashboard');
    }

    public function testMainWpsOwnWordForASiteIsAStatusAndAnyOtherWordIsNot(): void
    {
        self::assertSame(['connected', 'disconnected', 'suspended', '', ''], array_map(Status::ofDashboard(...), ['connected', 'disconnected', 'suspended', 'unknown', '']));
    }

    public function testAnUnknownStatusHasNoLabel(): void
    {
        self::assertSame('', Status::label('archived'));
    }
}
