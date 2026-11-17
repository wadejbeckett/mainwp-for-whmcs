<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The integration test's stand-in WHMCS tables are written in a syntax MySQL 8 takes as well as
 * MariaDB.
 */
final class IntegrationFixtureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/integration/CapsuleRepositoryTest.php';
    }

    /** @return array<string, string> */
    private static function tables(): array
    {
        $tables = (new \ReflectionClassConstant(Integration\CapsuleRepositoryTest::class, 'WHMCS_TABLES'))->getValue();
        self::assertIsArray($tables);
        return $tables;
    }

    public function testNoTextColumnHasALiteralDefaultThatMySqlRefuses(): void
    {
        foreach (self::tables() as $table => $ddl) {
            // MySQL 8 refuses DEFAULT '' on a TEXT column (error 1101); DEFAULT ('') is an expression it takes, as MariaDB does.
            self::assertDoesNotMatchRegularExpression('/\b(TINY|MEDIUM|LONG)?TEXT\b[^,)]*\bDEFAULT\s+\'/i', $ddl, $table);
        }
        self::assertStringContainsString("firstname TEXT NOT NULL DEFAULT ('')", self::tables()['tbladmins']);
    }
}
