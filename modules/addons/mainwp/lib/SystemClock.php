<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

/** The clock of the WHMCS server, in its own time zone, as WHMCS's dates are. */
final class SystemClock implements Engine\Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now');
    }
}
