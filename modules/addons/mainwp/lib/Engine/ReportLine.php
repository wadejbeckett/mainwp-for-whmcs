<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * One thing the daily check found that staff should see: the client, the site, the state, and
 * what is wrong (SPEC.md, "Daily check"). A line about a site that is only on the dashboard has
 * no add-on row. $kind names the sort of finding in a word or two. Never a secret, and never the
 * dashboard's raw diagnostics.
 */
final class ReportLine
{
    public function __construct(
        public readonly ?int $addonRowId,
        public readonly string $client,
        public readonly string $site,
        public readonly string $state,
        public readonly string $problem,
        public readonly string $kind = 'mismatch',
    ) {
    }

    /**
     * What tells this finding apart from another, and the same finding on a later day from a new
     * one: its kind and what it is about. The words of the problem may change from day to day (a
     * retry count, a date) without the finding being new.
     */
    public function key(): string
    {
        return $this->kind . ':' . ($this->addonRowId !== null ? 'add-on ' . $this->addonRowId : $this->site);
    }

    public function text(): string
    {
        // A line with no site (an add-on whose hosting service has no domain) names the client alone.
        return trim(($this->client !== '' ? $this->client . ($this->site !== '' ? ', ' : '') : '') . $this->site) . ' (' . $this->state . '): ' . $this->problem;
    }
}
