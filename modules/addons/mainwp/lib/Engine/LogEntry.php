<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;


/** One line of the module's log: when, who, what, on what, how it ended, and whether it was a dry run. Never a secret. */
final class LogEntry
{
    public function __construct(
        public readonly string $time,
        public readonly string $actor,
        public readonly string $action,
        public readonly string $target,
        public readonly string $outcome,
        public readonly bool $dryRun,
        public readonly string $detail = '',
    ) {
    }
}
