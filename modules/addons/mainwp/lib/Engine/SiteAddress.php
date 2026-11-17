<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * The rule for the address typed for a care site (SPEC.md, Provisioning): the hosting service's
 * domain, or a subdomain or a path under it. Anything else is refused, so that care is never
 * pointed at a site the service does not hold.
 */
final class SiteAddress
{
    /**
     * The typed address in one form (scheme, lower-case host, path without a trailing slash; https
     * when no scheme was typed), or null when it is not under $domain.
     */
    public static function under(string $typed, string $domain): ?string
    {
        $domain = strtolower(trim($domain, " \t\n\r\0\x0B/."));
        $typed = trim($typed);
        if ($domain === '' || $typed === '') {
            return null;
        }
        $schemeTyped = preg_match('~^[a-z][a-z0-9+.\-]*://~i', $typed) === 1;
        if (!$schemeTyped) {
            $typed = 'https://' . $typed;
        }
        $parts = parse_url($typed);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        // The default port of the scheme typed is the address without it; any other port is refused.
        $port = isset($parts['port']) && (!$schemeTyped || self::ownPort($parts) !== null);
        if (!in_array($scheme, ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass']) || $port || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if ($host !== $domain && !str_ends_with($host, '.' . $domain)) {
            return null;
        }
        $path = rtrim($parts['path'] ?? '', '/');
        if ($path !== '' && preg_match('#^(/[A-Za-z0-9._~\-]+)+$#', $path) !== 1 || preg_match('~/\.\.?(/|$)~', $path) === 1) {
            return null;
        }
        return $scheme . '://' . $host . $path;
    }

    /** The port each scheme uses when an address names none. */
    private const DEFAULT_PORTS = ['https' => 443, 'http' => 80];

    /**
     * Are $a and $b the same site's address? Equal but for the scheme (a site may report http:// while
     * it is read over https://), the case of the host, a leading "www.", the default port of the
     * address's own scheme and a trailing slash; a path keeps its case. An address with no host is
     * the same as nothing.
     */
    public static function same(string $a, string $b): bool
    {
        $key = static function (string $address): ?string {
            $host = self::host($address);
            return $host === null ? null : $host . rtrim((string) parse_url(trim($address), PHP_URL_PATH), '/');
        };
        $first = $key($a);
        return $first !== null && $first === $key($b);
    }

    /**
     * The host of an address as same() compares it: lower case, without a leading "www.", with its
     * port unless that is the default of the address's own scheme (:443 for https, :80 for http; an
     * address with no scheme keeps its port). Null when the address has no host.
     */
    public static function host(string $address): ?string
    {
        $parts = parse_url(trim($address));
        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return null;
        }
        $host = strtolower($parts['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        return $host . (self::ownPort($parts) !== null ? ':' . self::ownPort($parts) : '');
    }

    /**
     * The port an address names, or null when it names none or the default of its own scheme.
     *
     * @param array<string, int|string> $parts what parse_url() made of the address
     */
    public static function ownPort(array $parts): ?int
    {
        if (!isset($parts['port'])) {
            return null;
        }
        $port = (int) $parts['port'];
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        return (self::DEFAULT_PORTS[$scheme] ?? null) === $port ? null : $port;
    }

    /**
     * The address to use when a site at $from only sends visitors on to $movedTo: the target in one
     * form when it is an https:// address under $domain and another address than $from; null when
     * the move leads out of the service or to plain http, where nothing is sent.
     */
    public static function movedWithin(string $movedTo, string $from, string $domain): ?string
    {
        if (!str_starts_with(strtolower(trim($movedTo)), 'https://')) {
            return null;
        }
        $address = self::under($movedTo, $domain);
        return $address !== null && $address !== self::under($from, $domain) ? $address : null;
    }
}
