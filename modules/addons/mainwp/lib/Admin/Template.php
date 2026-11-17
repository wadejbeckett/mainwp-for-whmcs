<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Admin;

/**
 * Renders the module's templates with Smarty 4 (WHMCS 9.1 bundles 4.5.3) under a policy at least
 * as strict as WHMCS's: of Smarty's tags only if and foreach, of its modifiers only escape, no PHP
 * function, static class, super-global, constant or stream, and no file outside the template
 * folder. Smarty's own syntax (assignment, literal blocks, object calls) is outside any policy;
 * tests/Admin/PageTest.php scans the template for it.
 */
final class Template
{
    /** Smarty checks the closing tags as "ifclose" and "foreachclose". */
    public const ALLOWED_TAGS = ['if', 'elseif', 'else', 'ifclose', 'foreach', 'foreachelse', 'foreachclose'];
    public const ALLOWED_MODIFIERS = ['escape'];

    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/templates';
    }

    /** @param array<string, mixed> $vars */
    public static function render(string $file, array $vars, ?string $templateDir = null): string
    {
        $templateDir ??= self::dir();
        $smarty = new \Smarty();
        $smarty->setTemplateDir($templateDir);
        $smarty->setCompileDir(self::compileDir());
        // Smarty recompiles only a template newer than its compiled copy; an upgrade by unzip keeps older
        // file times, so each version compiles copies of its own.
        $smarty->setCompileId(\MainWpForWhmcs\Module::VERSION);
        $smarty->caching = \Smarty::CACHING_OFF;
        $policy = new \Smarty_Security($smarty);
        $policy->secure_dir = [$templateDir];
        $policy->allowed_tags = self::ALLOWED_TAGS;
        $policy->allowed_modifiers = self::ALLOWED_MODIFIERS;
        // Smarty reads an empty list here as "all": a name nothing has allows none.
        $policy->php_functions = ['NoPhpFunctionIsAllowed'];
        $policy->php_modifiers = ['NoPhpModifierIsAllowed'];
        $policy->static_classes = ['NoStaticClassIsAllowed'];
        $policy->streams = ['NoStreamIsAllowed'];
        $policy->allow_super_globals = false;
        $policy->allow_constants = false;
        $smarty->enableSecurity($policy);
        $smarty->assign($vars);
        return $smarty->fetch($file);
    }

    /** WHMCS's own template cache folder ($templates_compiledir in configuration.php) when it can be written, else the system's temporary folder. */
    private static function compileDir(): string
    {
        $whmcs = $GLOBALS['templates_compiledir'] ?? null;
        $dir = is_string($whmcs) && is_dir($whmcs) && is_writable($whmcs)
            ? rtrim($whmcs, '/') . '/mainwp'
            : sys_get_temp_dir() . '/mainwp-smarty-' . getmyuid();
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('the template cache folder cannot be created.');
        }
        return $dir;
    }
}
