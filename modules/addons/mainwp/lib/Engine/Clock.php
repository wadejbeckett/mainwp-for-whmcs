<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;


interface Clock
{
    public function now(): \DateTimeImmutable;
}
