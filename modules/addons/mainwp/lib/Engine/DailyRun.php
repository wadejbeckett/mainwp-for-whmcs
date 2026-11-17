<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * What one live run knows and counts as it goes.
 */
final class DailyRun
{
    public int $provisioned = 0;
    public int $retried = 0;
    public int $connected = 0;
    public int $mended = 0;
    public int $takenOver = 0;
    /** The dashboard failed during the run: no further retries or mends. */
    public bool $halted = false;
    /** How many care sites' dashboard sites the dashboard was seen to hold in this run. */
    public int $found = 0;
    /** @var list<string> dashboard sites that are at another address than the care site they are on record for, in words */
    public array $foreign = [];
    /** @var list<AddonState> active add-ons whose dashboard site the dashboard says it does not have */
    public array $missing = [];
    /** @var list<int> add-on rows whose dashboard site the dashboard said it does not have */
    public array $gone = [];

    /**
     * @param array<int, AddonState> $states by add-on row
     * @param array<int, DashboardSite> $rows the dashboard's sites by their id
     */
    public function __construct(public readonly array $states, public readonly array $rows, public readonly string $today)
    {
    }
}
