<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * What one run of the daily check found: its lines, in the order they were found, and, when the
 * dashboard itself was at fault (unreachable, its key refused, not MainWP's answer), that fault in
 * plain words. A run that found another one already going did nothing and says so.
 */
final class Report
{
    /** @var list<ReportLine> */
    private array $lines = [];
    public ?string $fault = null;
    public bool $alreadyRunning = false;

    public function add(ReportLine $line): void
    {
        $this->lines[] = $line;
    }

    /** @return list<ReportLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [] && $this->fault === null;
    }
}
