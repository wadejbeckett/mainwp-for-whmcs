<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * One care add-on as the billing system holds it, as plain data for the daily check: whose it is,
 * the site's default address, and how it stands. Active: care runs (the add-on is active on an
 * active hosting service). Suspended: the add-on or its service is suspended. Ended: terminated,
 * cancelled, or gone. Pending: anything else. Billed is active with nothing left to pay; only a
 * billed add-on is provisioned. $why says, in plain words, why it is not active.
 */
final class AddonState
{
    public const ACTIVE = 'active';
    public const SUSPENDED = 'suspended';
    public const ENDED = 'ended';
    public const PENDING = 'pending';

    public function __construct(
        public readonly int $addonRowId,
        public readonly int $serviceId,
        public readonly Client $client,
        public readonly string $siteUrl,
        public readonly string $standing,
        public readonly bool $billed,
        public readonly string $why = '',
    ) {
    }
}
