<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\LogText;

final class LogTextTest extends TestCase
{
    public function testPathsAndSecretsGoUrlsStay(): void
    {
        self::assertSame('Error in (path) at https://example.com/wp-json/wp/v2/plugins', LogText::clean('Error in /home/whmcs/public_html/modules/addons/mainwp/lib/Site.php:12 at https://example.com/wp-json/wp/v2/plugins'));
        self::assertSame('login (hidden) refused for admin', LogText::clean('login s3cr3t refused for admin', 's3cr3t'));
        self::assertSame('a/b and /single stay', LogText::clean('a/b and /single stay'));
        self::assertSame('nothing to hide', LogText::clean('nothing to hide', ''));
    }
}
