<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/** Makes a text safe for the log and the screen: absolute file paths become "(path)" and every given secret "(hidden)". */
final class LogText
{
    /** An absolute path of two or more parts, with an optional ":line"; not the path part of a URL. */
    private const PATH = '~(?<![\w:/.\-])/(?:[^\s:\'"/]+/)+[^\s:\'"/]*(?::\d+)?~';

    public static function clean(string $text, string ...$secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '(hidden)', $text);
            }
        }
        return (string) preg_replace(self::PATH, '(path)', $text);
    }
}
