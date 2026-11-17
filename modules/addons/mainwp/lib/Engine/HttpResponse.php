<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/** An HTTP answer: status, headers (lower-case name => every value, in order) and body. */
final class HttpResponse
{
    /** @param array<string, list<string>> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /** The first value of a header, or null. */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return list<string> every value of a header */
    public function headers(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }
}
