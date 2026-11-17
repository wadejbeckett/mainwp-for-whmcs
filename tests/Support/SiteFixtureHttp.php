<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Support;

use MainWpForWhmcs\Engine\Http;
use MainWpForWhmcs\Engine\HttpResponse;

/**
 * Answers each request to a WordPress site with an exchange recorded on the bench
 * (tests/fixtures/wordpress/<name>.json: status, headers, body), matched on "METHOD /path?query".
 * Records every request with its headers and form, so tests can check what went out. An
 * unexpected request fails the test loudly.
 */
final class SiteFixtureHttp implements Http
{
    /** @var array<string, list<string|\Throwable|HttpResponse>> */
    private array $answers = [];
    /** @var list<array{method: string, url: string, headers: array<string, string>, form: array<string, string>|string}> */
    public array $sent = [];

    public function on(string $method, string $pathAndQuery, string|\Throwable|HttpResponse ...$answers): self
    {
        $this->answers[$method . ' ' . $pathAndQuery] = array_merge($this->answers[$method . ' ' . $pathAndQuery] ?? [], $answers);
        return $this;
    }

    public static function fixture(string $name): HttpResponse
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/wordpress/' . $name . '.json'), true, 512, JSON_THROW_ON_ERROR);
        $headers = [];
        foreach ((array) $data['headers'] as $header => $value) {
            $headers[strtolower((string) $header)] = array_values(array_map('strval', (array) $value));
        }
        $body = is_string($data['body']) ? $data['body'] : json_encode($data['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new HttpResponse((int) $data['status'], $body, $headers);
    }

    public function request(string $method, string $url, array $headers, array|string $form = []): HttpResponse
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'form' => $form];
        $parts = parse_url($url);
        $key = $method . ' ' . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        if (($this->answers[$key] ?? []) === []) {
            throw new \LogicException('No fixture for ' . $key);
        }
        $next = array_shift($this->answers[$key]);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next instanceof HttpResponse ? $next : self::fixture($next);
    }

    /** @return list<string> "METHOD /path?query" of every request sent, in order */
    public function requests(): array
    {
        return array_map(static function (array $r): string {
            $p = parse_url($r['url']);
            return $r['method'] . ' ' . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
        }, $this->sent);
    }
}
