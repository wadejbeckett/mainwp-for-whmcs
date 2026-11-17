<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

/** What mainwp_upgrade() changes for an install at an older version; kept apart so it runs against any Repository. */
final class Upgrade
{
    /**
     * Runs every step newer than $from, in order, skipping a step whose guard column is already
     * there or whose change found nothing to change, and returns one sentence per change for the
     * activity log.
     *
     * @param list<array{version: string, sentence: string, statements?: list<string>, unless?: array{string, string}, change?: \Closure(Repository): bool}>|null $steps defaults to Schema::upgradeSteps()
     * @return list<string>
     * @throws \InvalidArgumentException when $from is not a version number, so no statement runs on a guess
     */
    public static function run(Repository $repo, string $from, ?array $steps = null): array
    {
        if (preg_match('/^\d+\.\d+\.\d+$/', $from) !== 1) {
            throw new \InvalidArgumentException('the installed version "' . $from . '" is not a version number; nothing was changed');
        }
        $changes = [];
        foreach ($steps ?? Schema::upgradeSteps() as $step) {
            if (version_compare($from, $step['version'], '>=')) {
                continue;
            }
            if (isset($step['unless']) && $repo->hasColumn(...$step['unless'])) {
                continue;
            }
            foreach ($step['statements'] ?? [] as $sql) {
                $repo->execute($sql);
            }
            if (isset($step['change']) && !($step['change'])($repo)) {
                continue;
            }
            $changes[] = $step['sentence'];
        }
        return $changes;
    }
}
