<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Admin;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Admin\Template;
use MainWpForWhmcs\Module;

/**
 * An upgrade by unzip keeps the files' times, so a new template can be older than the copy Smarty
 * compiled from the old one; Smarty recompiles only when the template is newer. The compiled copies
 * carry the module's version, so a new version never uses an older version's copy.
 */
final class TemplateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/mainwp-template-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testATemplateReplacedByAnOlderDatedFileIsShownAsItIsNowUnderANewVersion(): void
    {
        $file = $this->dir . '/page.tpl';
        file_put_contents($file, 'OLD {$word|escape}');
        touch($file, time() - 10 * 86400);
        // The copy an earlier version compiled, with no version on it, as 0.4.2 did.
        $earlier = new \Smarty();
        $earlier->setTemplateDir($this->dir);
        $earlier->setCompileDir($GLOBALS['templates_compiledir'] . '/mainwp');
        $earlier->assign('word', 'x');
        self::assertSame('OLD x', $earlier->fetch('page.tpl', null, null));

        // The upgrade's template, dated before that copy was made.
        file_put_contents($file, 'NEW {$word|escape}');
        touch($file, time() - 5 * 86400);
        clearstatcache();

        self::assertSame('NEW x', Template::render('page.tpl', ['word' => 'x'], $this->dir));
        $versioned = glob($GLOBALS['templates_compiledir'] . '/mainwp/' . str_replace('.', '_', Module::VERSION) . '^*');
        self::assertNotEmpty($versioned, 'the compiled copy carries the module\'s version');
    }
}
