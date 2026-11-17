<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/** One HTTP request out. Throws \RuntimeException when no answer came back (no connection, timeout, TLS refused). */
interface Http
{
    /**
     * @param array<string, string> $headers
     * @param array<string, string>|string $form an array is sent as application/x-www-form-urlencoded when not
     *                                           empty; a string is sent as it is, its Content-Type named in $headers
     */
    public function request(string $method, string $url, array $headers, array|string $form = []): HttpResponse;
}
