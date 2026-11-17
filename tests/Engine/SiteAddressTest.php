<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\SiteAddress;

/** The address a client or staff type for the site must be the service's domain, or a subdomain or path under it. */
final class SiteAddressTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function accepted(): iterable
    {
        yield 'the domain itself' => ['clientsite.example', 'https://clientsite.example'];
        yield 'with https and a trailing slash' => ['https://clientsite.example/', 'https://clientsite.example'];
        yield 'capitals and spaces' => ['  HTTPS://ClientSite.Example  ', 'https://clientsite.example'];
        yield 'a subdomain' => ['shop.clientsite.example', 'https://shop.clientsite.example'];
        yield 'www' => ['https://www.clientsite.example', 'https://www.clientsite.example'];
        yield 'a path' => ['clientsite.example/blog/', 'https://clientsite.example/blog'];
        yield 'plain http is kept as typed' => ['http://clientsite.example', 'http://clientsite.example'];
        // Code review, 16 November 2026: the default port of the address's own scheme is the address without it.
        yield 'https with its default port' => ['https://clientsite.example:443/', 'https://clientsite.example'];
        yield 'http with its default port' => ['http://clientsite.example:80/blog', 'http://clientsite.example/blog'];
    }

    /** @dataProvider accepted */
    public function testAnAddressUnderTheServicesDomainIsAccepted(string $typed, string $expected): void
    {
        self::assertSame($expected, SiteAddress::under($typed, 'clientsite.example'));
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        yield 'another domain' => ['example.com'];
        yield 'a domain that only ends the same' => ['notclientsite.example'];
        yield 'the domain as a subdomain of another' => ['clientsite.example.example.com'];
        yield 'the domain in the path' => ['example.com/clientsite.example'];
        yield 'a login in the address' => ['https://user:pw@clientsite.example'];
        yield 'a port' => ['https://clientsite.example:8443'];
        yield 'the other scheme\'s default port' => ['https://clientsite.example:80'];
        yield 'a port with no scheme typed' => ['clientsite.example:443'];
        yield 'a query' => ['https://clientsite.example/?p=1'];
        yield 'another scheme' => ['ftp://clientsite.example'];
        yield 'empty' => [''];
        yield 'a path that climbs' => ['clientsite.example/../x'];
    }

    /** @dataProvider refused */
    public function testAnyOtherAddressIsRefused(string $typed): void
    {
        self::assertNull(SiteAddress::under($typed, 'clientsite.example'));
    }

    public function testAServiceWithoutADomainAcceptsNothing(): void
    {
        self::assertNull(SiteAddress::under('clientsite.example', ''));
    }

    public function testAnAddressASiteMovedToIsFollowedOnlyOverHttpsUnderTheSameService(): void
    {
        self::assertSame('https://www.clientsite.example', SiteAddress::movedWithin('https://www.clientsite.example', 'https://clientsite.example', 'clientsite.example'));
        self::assertSame('https://www.clientsite.example/blog', SiteAddress::movedWithin('https://www.clientsite.example/blog/', 'https://clientsite.example/blog', 'clientsite.example'));
        self::assertNull(SiteAddress::movedWithin('http://www.clientsite.example', 'https://clientsite.example', 'clientsite.example'), 'a login is never sent over plain http');
        self::assertNull(SiteAddress::movedWithin('https://elsewhere.example', 'https://clientsite.example', 'clientsite.example'), 'not part of the service');
        self::assertNull(SiteAddress::movedWithin('https://clientsite.example', 'https://clientsite.example', 'clientsite.example'), 'the same address is no move');
    }

    public function testTheSameAddressIgnoresTheDefaultPortOfItsOwnScheme(): void
    {
        self::assertTrue(SiteAddress::same('https://clientsite.example:443', 'https://clientsite.example'));
        self::assertTrue(SiteAddress::same('http://www.clientsite.example:80/', 'https://clientsite.example'));
        self::assertTrue(SiteAddress::same('https://clientsite.example:443/blog', 'http://clientsite.example:80/blog/'));
        self::assertFalse(SiteAddress::same('https://clientsite.example:80', 'https://clientsite.example'), 'port 80 is not https\'s default');
        self::assertFalse(SiteAddress::same('https://clientsite.example:8443', 'https://clientsite.example'));
        self::assertFalse(SiteAddress::same('//clientsite.example:443', 'https://clientsite.example'), 'an address with no scheme keeps its port');
        self::assertTrue(SiteAddress::same('//clientsite.example:443', '//www.clientsite.example:443/'));
    }

    public function testTheHostAsSameComparesIt(): void
    {
        self::assertSame('clientsite.example', SiteAddress::host('https://WWW.ClientSite.Example:443/blog'));
        self::assertSame('clientsite.example', SiteAddress::host('http://clientsite.example:80'));
        self::assertSame('clientsite.example:8443', SiteAddress::host('https://clientsite.example:8443'));
        self::assertSame('clientsite.example:80', SiteAddress::host('https://clientsite.example:80'));
        self::assertNull(SiteAddress::host('not an address'));
    }
}
