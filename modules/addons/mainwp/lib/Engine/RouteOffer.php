<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * Which route a site is offered. The key route when its public REST index shows an approval
 * screen; otherwise the password route, with the reason in plain words for the client. When the
 * address only sends visitors on to another host, $movedTo is the address to use instead. When
 * the site's index reports another address as its own, $answersAt is that address.
 */
final class RouteOffer
{
    public function __construct(
        public readonly bool $keyRoute,
        public readonly string $reason,
        public readonly bool $wordfence = false,
        public readonly ?string $approvalUrl = null,
        public readonly ?string $movedTo = null,
        public readonly ?string $answersAt = null,
    ) {
    }
}
