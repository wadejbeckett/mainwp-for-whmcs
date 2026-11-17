<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Support/FakeWhmcs.php';
require_once __DIR__ . '/../modules/addons/mainwp/lib/Bootstrap.php';
require_once __DIR__ . '/Support/MemoryRepository.php';

// Compiled templates are kept per test process and thrown away with it. A compiled copy from an
// earlier run must never stand in for a template that changed since: Smarty tells them apart by
// the file's time alone, to the second (seen on 10 November 2026, when a template was changed and
// restored within one second).
$GLOBALS['templates_compiledir'] = sys_get_temp_dir() . '/mainwp-tests-' . getmypid();
if (!is_dir($GLOBALS['templates_compiledir'])) {
    mkdir($GLOBALS['templates_compiledir'], 0700, true);
}
register_shutdown_function(static function (): void {
    $dir = $GLOBALS['templates_compiledir'];
    if (!is_string($dir) || !str_contains($dir, '/mainwp-tests-') || !is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
});

