<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

/**
 * The service's name, which is the host's own: the name of the care product add-on in WHMCS, read
 * live each time and never stored by the module. Where no single add-on is at hand, the name of the
 * only care add-on stands in, else the neutral words.
 */
final class ServiceName
{
    /** The neutral words for care where no add-on names it; also the User-Agent a client's site is called with. */
    public const NEUTRAL = 'Website Care';

    /** The name of the row's product add-on as WHMCS shows it; the fallback when there is no row, or its add-on is gone or has no name. */
    public static function of(Repository $repo, ?AddonRow $row): string
    {
        $name = $row === null ? '' : trim($repo->productAddons()[$row->addonId] ?? '');
        return $name !== '' ? $name : self::fallback($repo);
    }

    /** The name of the only care add-on, or the neutral words when there is none, or more than one, or it has no name. */
    public static function fallback(Repository $repo): string
    {
        $ids = $repo->careAddonIds();
        $name = count($ids) === 1 ? trim($repo->productAddons()[$ids[0]] ?? '') : '';
        return $name !== '' ? $name : self::NEUTRAL;
    }

    /**
     * A name as it is handed to WHMCS's own page parts (a sidebar label, the page title, the
     * breadcrumb, a staff field's label), which WHMCS's themes print as they are given: encoded,
     * as WHMCS keeps its own names. The module's own templates escape and take the name as it is.
     */
    public static function forTheme(string $name): string
    {
        return htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    }

    /**
     * The name a group of rows shares: the one name when every row has it, else the fallback.
     *
     * @param list<AddonRow> $rows
     */
    public static function shared(Repository $repo, array $rows): string
    {
        $names = array_unique(array_map(static fn (AddonRow $row): string => self::of($repo, $row), $rows));
        return count($names) === 1 ? (string) reset($names) : self::fallback($repo);
    }
}
