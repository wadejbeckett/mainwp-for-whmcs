<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * An approval the module waits for: a client was sent to their own site with a one-time token in
 * the return address. Only the token's hash is kept. "Used" means a key came back on it; it holds
 * the key until the site is connected and the key deleted there, or the attempt is given up. The
 * key's SHA-256 stays with it, so that the same return arriving again is told from a second key.
 */
final class Approval
{
    public function __construct(
        public readonly string $tokenHash,
        public readonly int $addonRowId,
        public readonly string $expiresAt,
        public readonly bool $used,
        public readonly bool $holdsKey = false,
        public readonly ?string $keyHash = null,
    ) {
    }

    /** Past its time, or replaced by a newer approval, without having been used. */
    public function lapsed(string $now): bool
    {
        return !$this->used && $now >= $this->expiresAt;
    }
}
