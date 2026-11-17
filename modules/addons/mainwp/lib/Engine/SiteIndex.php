<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * What a site's public REST index says: where the REST API is, WordPress's approval screen for a
 * key (null when the site offers none, or names one that is not on its own host over HTTPS),
 * whether Wordfence's REST namespace shows, and the addresses WordPress reports as its own: "home",
 * where visitors go, and "url", where its files are (null when the index names none).
 */
final class SiteIndex
{
    public function __construct(
        public readonly string $restRoot,
        public readonly ?string $approvalUrl,
        public readonly bool $wordfence = false,
        public readonly ?string $home = null,
        public readonly ?string $siteUrl = null,
    ) {
    }

    /**
     * The address the WordPress site answers at when $address is on the host of an address the site
     * reports as its own, yet is none of them: a folder with no WordPress of its own reads the main
     * site's index. Named with $address's scheme. Null when $address is one of the site's own, when
     * the index reports no address, and when it reports only another host (a domain alias served
     * without a redirect, a mapped address): nothing on the typed host says where its WordPress is,
     * and such a site is connected as before. SiteAddress::same() and SiteAddress::host() decide what is the same address and host.
     */
    public function answersElsewhere(string $address): ?string
    {
        // Only what is an address at all: same() holds no address the same as itself.
        $own = array_values(array_filter([$this->home, $this->siteUrl], static fn (?string $a): bool => $a !== null && SiteAddress::same($a, $a)));
        foreach ($own as $reported) {
            if (SiteAddress::same($reported, $address)) {
                return null;
            }
        }
        $host = SiteAddress::host($address);
        foreach ($own as $reported) {
            if ($host !== null && SiteAddress::host($reported) === $host) {
                $parts = (array) parse_url(trim($reported));
                $port = SiteAddress::ownPort($parts);
                $scheme = strtolower((string) parse_url(trim($address), PHP_URL_SCHEME));
                return ($scheme !== '' ? $scheme : 'https') . '://' . strtolower((string) ($parts['host'] ?? '')) . ($port !== null ? ':' . $port : '') . rtrim((string) ($parts['path'] ?? ''), '/');
            }
        }
        return null;
    }
}
