<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * HTTP through PHP's curl, HTTPS only, certificates checked, redirects not followed, with a time
 * limit and a limit on the answer's size; the answer's headers are kept. The caller's name (the
 * User-Agent) is given by whoever builds it, because the far end can read it: a client's site is
 * told the service's name.
 */
final class CurlHttp implements Http
{
    /** No answer is read past this many bytes: a site that answers without end must not take the module's memory. */
    public const MAX_BODY = 2 * 1024 * 1024;

    public function __construct(public readonly string $userAgent, private readonly int $timeoutSeconds = 30)
    {
    }

    public function request(string $method, string $url, array $headers, array|string $form = []): HttpResponse
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP\'s curl extension is not installed');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => $this->userAgent,
        ]);
        $received = [];
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($curl, string $line) use (&$received): int {
            if (str_starts_with($line, 'HTTP/')) {
                $received = []; // a new answer (after 100 Continue): keep only the last one's headers
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $received[strtolower(trim($name))][] = trim($value);
            }
            return strlen($line);
        });
        if (is_string($form)) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $form);
        } elseif ($form !== []) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));
        }
        $body = '';
        $tooLarge = false;
        curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($curl, string $data) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($data) > self::MAX_BODY) {
                $tooLarge = true;
                return 0; // fewer bytes taken than given: curl stops the transfer
            }
            $body .= $data;
            return strlen($data);
        });
        if (curl_exec($handle) === false) {
            $error = curl_error($handle);
            // Taken and not answered in time: the request was on its way when the time ran out.
            $taken = curl_errno($handle) === CURLE_OPERATION_TIMEDOUT && (float) curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME) > 0;
            curl_close($handle);
            if ($tooLarge) {
                throw new HttpTooLarge('the answer was larger than 2 MB, so it was not read');
            }
            throw $taken ? new HttpTimeout($error) : new \RuntimeException($error !== '' ? $error : 'no answer');
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return new HttpResponse($status, $body, $received);
    }
}
