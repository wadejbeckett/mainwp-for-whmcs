<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * What starting an approval gave: the address on the client's own site to send their browser to,
 * or why not; and, when there is one, something the client should read before they go (what an
 * earlier attempt left on their site that could not be removed). When the address only redirects
 * to another host, $movedTo is the address it sends visitors to; when the site reports another
 * address as its own, $answersAt is that address.
 */
final class ApprovalStart
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?string $url = null,
        public readonly string $note = '',
        public readonly ?string $movedTo = null,
        public readonly ?string $answersAt = null,
    ) {
    }
}
