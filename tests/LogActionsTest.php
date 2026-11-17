<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every action the module writes to its log fits the log's action column whole: "dashboard: point site at an administrator" was
 * once stored cut to 32 characters, and a database in strict mode would refuse the row.
 */
final class LogActionsTest extends TestCase
{
    private const LIB = __DIR__ . '/../modules/addons/mainwp/lib';

    /** The width of mod_mainwp_log.action, as the schema creates it. */
    private static function width(): int
    {
        self::assertSame(1, preg_match('/^\s*action VARCHAR\((\d+)\) NOT NULL,$/m', (string) file_get_contents(self::LIB . '/Schema.php'), $m), 'the log\'s action column is found in the schema');
        return (int) $m[1];
    }

    /** @return list<string> every action label written as a literal anywhere in the module */
    private static function labels(): array
    {
        $patterns = [
            '/\'action\' => \'([^\']+)\'/',                       // rows the WHMCS layer adds itself
            '/->log\(\$\w+, \'([^\']+)\'/',                       // CareEngine::log()
            '/(?:wouldRun|busyEvent)\(\'([^\']+)\'/',             // CareEngine's helpers that log under the action given
            '/new LogEntry\([^;]*?\$actor, \'([^\']+)\'/',        // DailyCheck
            '/\'AfterModule\w+\' => \'([^\']+)\'/',               // Lifecycle::unheard()
        ];
        $labels = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $source, $m);
                array_push($labels, ...$m[1]);
            }
            if ($file->getFilename() === 'LoggedDashboard.php') {
                // Each dashboard call is logged as "dashboard: " and its own label.
                self::assertStringContainsString('\'dashboard: \' . $action', $source);
                preg_match_all('/->logged\(\'([^\']+)\'/', $source, $m);
                self::assertNotEmpty($m[1]);
                foreach ($m[1] as $label) {
                    $labels[] = 'dashboard: ' . $label;
                }
            }
        }
        return array_values(array_unique($labels));
    }

    public function testEveryActionTheModuleLogsFitsTheLogsActionColumn(): void
    {
        $width = self::width();
        $labels = self::labels();

        self::assertGreaterThan(25, count($labels), 'the labels are found in the code');
        foreach (['connect', 'approve', 'daily check', 'dashboard: add site', 'suspend', 'resume', 'remove', 'provision'] as $known) {
            self::assertContains($known, $labels);
        }
        $tooLong = array_values(array_filter($labels, static fn (string $label): bool => mb_strlen($label) > $width));
        self::assertSame([], $tooLong, 'longer than the ' . $width . ' characters of the log\'s action column');
    }

    public function testTheDashboardCallThatPointsASiteAtAnAdministratorIsLoggedWhole(): void
    {
        self::assertContains('dashboard: switch administrator', self::labels());
    }
}
