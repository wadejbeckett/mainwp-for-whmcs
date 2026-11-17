<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Support;

use MainWpForWhmcs\Engine\Http;
use MainWpForWhmcs\Engine\HttpResponse;

/**
 * Answers each request with a fixture recorded on the bench (tests/fixtures/mainwp/<name>.json),
 * matched on "METHOD path" with the query string. Records every request it was sent, headers
 * included, so tests can check what went out (a JSON body as the string it was sent as). An
 * unexpected request fails the test loudly, with one exception: a later page of a list whose first
 * page was given, and for which nothing was given, is answered as MainWP answers a page past the
 * end, with an empty list (the module reads a list until a page brings nothing new).
 */
final class FixtureHttp implements Http
{
    /** @var array<string, list<string|\Throwable|HttpResponse>> "METHOD path?query" => fixture names (or a transport error, or an answer made by hand), used in order */
    private array $answers = [];
    /** @var list<array{method: string, url: string, headers: array<string, string>, form: array<string, string>|string}> */
    public array $sent = [];

    public function on(string $method, string $path, string|\Throwable|HttpResponse ...$fixtures): self
    {
        $this->answers[$method . ' ' . $path] = array_merge($this->answers[$method . ' ' . $path] ?? [], $fixtures);
        return $this;
    }

    public static function fixture(string $name): HttpResponse
    {
        $file = __DIR__ . '/../fixtures/mainwp/' . $name . '.json';
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        return new HttpResponse((int) $data['status'], is_string($data['body']) ? $data['body'] : json_encode($data['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function request(string $method, string $url, array $headers, array|string $form = []): HttpResponse
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'form' => $form];
        $parts = parse_url($url);
        $path = preg_replace('~^/wp-json/mainwp/v2/~', '', (string) ($parts['path'] ?? '')) . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $key = $method . ' ' . $path;
        if (!isset($this->answers[$key]) && $method === 'GET' && preg_match('~^(.+\?page=)([2-9]|[1-9]\d+)(&per_page=\d+)$~', $path, $m) === 1 && isset($this->answers['GET ' . $m[1] . '1' . $m[3]])) {
            return new HttpResponse(200, '{"success":1,"total":0,"data":[]}');
        }
        if (($this->answers[$key] ?? []) === []) {
            throw new \LogicException('No fixture for ' . $key);
        }
        $next = array_shift($this->answers[$key]);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next instanceof HttpResponse ? $next : self::fixture($next);
    }
}
