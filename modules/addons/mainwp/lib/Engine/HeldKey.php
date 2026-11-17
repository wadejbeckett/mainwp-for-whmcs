<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * What the key route keeps while a connection is not finished: the key the client approved (their
 * login name and the application password) and the login of the care account made with it.
 * Stored encrypted by the storage; deleted when the site is connected or the retries end.
 */
final class HeldKey
{
    public function __construct(
        public readonly Login $key,
        public readonly Login $care,
    ) {
    }
}
