<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * A small HTTPS server on 127.0.0.1 for CurlHttpTest, one connection at a time, until it is killed.
 * Arguments: the certificate file and its key. It prints its port on the first line. By path:
 *   /exact  an answer of exactly 2 MB (2,097,152 bytes)
 *   /one-more  an answer one byte larger than that (2,097,153 bytes)
 *   /over   an answer with no end, written until the client stops reading
 *   /hang   takes the request and answers nothing for 5 seconds
 */

[, $cert, $key] = $argv;
$context = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key]]);
$server = stream_socket_server('tls://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}
echo substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1), "\n";
flush();

while (true) {
    $client = @stream_socket_accept($server, -1);
    if ($client === false) {
        continue; // a handshake that failed
    }
    $request = '';
    while (!str_contains($request, "\r\n\r\n") && !feof($client)) {
        $line = fgets($client);
        if ($line === false) {
            break;
        }
        $request .= $line;
    }
    $path = (string) (explode(' ', $request)[1] ?? '/');
    if ($path === '/hang') {
        sleep(5);
    } elseif ($path === '/exact' || $path === '/one-more') {
        $size = 2 * 1024 * 1024 + ($path === '/one-more' ? 1 : 0);
        @fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: " . $size . "\r\nConnection: close\r\n\r\n");
        for ($sent = 0; $sent < $size; $sent += 65536) {
            if (@fwrite($client, str_repeat('a', min(65536, $size - $sent))) === false) {
                break;
            }
        }
    } else {
        @fwrite($client, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nConnection: close\r\n\r\n");
        $chunk = str_repeat('b', 65536);
        for ($i = 0; $i < 4096; $i++) { // 256 MB at most
            $written = @fwrite($client, $chunk);
            if ($written === false || $written === 0) {
                break;
            }
        }
    }
    @fclose($client);
}
