<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;


/**
 * What a command did: whether it ended well and one plain sentence saying what happened. For a
 * connect: something the client should know or do (what could not be removed on their site),
 * whether the attempt ran at all (not when the add-on was busy or the site was not waiting to be
 * connected), when the address only redirects, the address it sends visitors to, and whether it
 * failed because of the dashboard itself (unreachable, its key refused, not MainWP's answer): a
 * fault that is nobody's site's, and that the daily check does not count as a retry.
 */
final class Outcome
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly string $note = '',
        public readonly bool $attempted = false,
        public readonly ?string $movedTo = null,
        public readonly bool $dashboardFault = false,
    ) {
    }
}
