<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/** A client as the dashboard holds it, with the billing-system client id read from its note (null when the note carries none). */
final class DashboardClient
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly ?int $billingClientId,
    ) {
    }
}
