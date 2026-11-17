<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;

/** The engine is plain PHP so another billing system can sit on it */
final class EngineIsolationTest extends TestCase
{
    public function testNoEngineFileNamesWhmcs(): void
    {
        $files = glob(__DIR__ . '/../../modules/addons/mainwp/lib/Engine/*.php') ?: [];
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            $code = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression('/WHMCS\\\\|Capsule|localAPI|logActivity|add_hook|\$_(GET|POST|SERVER|REQUEST|SESSION)/', $code, basename($file));
            if (basename($file) !== 'Load.php') {
                self::assertStringContainsString('namespace MainWpForWhmcs\Engine;', $code, basename($file));
            }
        }
    }

    public function testTheEngineRunsWithoutWhmcsLoaded(): void
    {
        $script = 'require ' . var_export(realpath(__DIR__ . '/../../modules/addons/mainwp/lib/Engine'), true) . ' . "/Load.php"; echo class_exists("MainWpForWhmcs\\\\Engine\\\\CareEngine") && !function_exists("localAPI") ? "ok" : "no";';
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');
        self::assertSame('ok', $out);
    }
}
