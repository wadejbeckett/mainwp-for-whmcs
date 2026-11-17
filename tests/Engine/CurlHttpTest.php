<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\HttpTimeout;
use MainWpForWhmcs\Engine\HttpTooLarge;

/**
 * The engine's real HTTP client against a small HTTPS server of the test's own on 127.0.0.1, each
 * request in a process of its own that trusts the server's certificate. A site or a dashboard that
 * answers without end must not take the module's memory, and a
 * call that was taken and then not answered in time is told apart from one that found nobody.
 */
final class CurlHttpTest extends TestCase
{
    private static string $dir = '';
    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        if (!function_exists('curl_init') || !function_exists('proc_open') || trim((string) shell_exec('command -v openssl')) === '') {
            return;
        }
        self::$dir = sys_get_temp_dir() . '/mainwp-curl-' . getmypid();
        @mkdir(self::$dir, 0700, true);
        $cert = self::$dir . '/cert.pem';
        $key = self::$dir . '/key.pem';
        exec('openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=localhost -addext subjectAltName=DNS:localhost'
            . ' -keyout ' . escapeshellarg($key) . ' -out ' . escapeshellarg($cert) . ' 2>/dev/null', $out, $code);
        if ($code !== 0) {
            return;
        }
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/../Support/tls-server.php', $cert, $key], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return;
        }
        self::$server = $process;
        self::$port = (int) trim((string) fgets($pipes[1]));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server, 9);
            proc_close(self::$server);
            self::$server = null;
        }
        foreach (glob(self::$dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        if (self::$dir !== '') {
            @rmdir(self::$dir);
        }
    }

    protected function setUp(): void
    {
        if (self::$port === 0) {
            self::markTestSkipped('needs curl, proc_open and the openssl command to run an HTTPS server of its own');
        }
    }

    /** @return array<string, mixed> what one request came to, in a process with this memory limit */
    private function fetch(string $url, int $seconds = 30, string $memory = '64M'): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=' . $memory . ' -d curl.cainfo=' . escapeshellarg(self::$dir . '/cert.pem')
            . ' ' . escapeshellarg(__DIR__ . '/../Support/curl-client.php') . ' ' . escapeshellarg($url) . ' ' . $seconds . ' 2>&1';
        $out = (string) shell_exec($command);
        $result = json_decode($out, true);
        self::assertIsArray($result, 'the client process ended without an answer: ' . substr($out, 0, 300));
        return $result;
    }

    public function testAnAnswerOfTwoMegabytesIsReadWhole(): void
    {
        $result = $this->fetch('https://localhost:' . self::$port . '/exact');

        self::assertSame([true, 200, 2 * 1024 * 1024], [$result['ok'], $result['status'] ?? null, $result['bytes'] ?? null]);
    }

    public function testTheCapIsTwoMegabytesToTheByte(): void
    {
        self::assertSame(2 * 1024 * 1024, \MainWpForWhmcs\Engine\CurlHttp::MAX_BODY);

        $exact = $this->fetch('https://localhost:' . self::$port . '/exact');
        self::assertSame([true, \MainWpForWhmcs\Engine\CurlHttp::MAX_BODY], [$exact['ok'], $exact['bytes'] ?? null], 'an answer of exactly the cap is read');

        $over = $this->fetch('https://localhost:' . self::$port . '/one-more');
        self::assertSame([false, HttpTooLarge::class], [$over['ok'], $over['class'] ?? null], 'one byte more fails as too large');
    }

    public function testAnAnswerWithoutEndFailsAsAFailedRequestAndTheMemoryStaysBounded(): void
    {
        $result = $this->fetch('https://localhost:' . self::$port . '/over', 30, '32M');

        self::assertFalse($result['ok']);
        self::assertSame(HttpTooLarge::class, $result['class']);
        self::assertInstanceOf(\RuntimeException::class, new HttpTooLarge('the failure every caller already handles'));
        self::assertSame('the answer was larger than 2 MB, so it was not read', $result['message']);
        self::assertLessThan(32 * 1024 * 1024, $result['peak']);
    }

    public function testACallTakenAndNotAnsweredInTimeIsATimeout(): void
    {
        $result = $this->fetch('https://localhost:' . self::$port . '/hang', 1);

        self::assertFalse($result['ok']);
        self::assertSame(HttpTimeout::class, $result['class']);
        self::assertInstanceOf(\RuntimeException::class, new HttpTimeout('a timeout is a failed request too'));
    }

    public function testAConnectionThatCouldNotBeMadeInTimeIsNoTimeout(): void
    {
        // Taken by the system (the TCP connection is made) and never by a server: the TLS handshake gets no answer.
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);

        $result = $this->fetch('https://127.0.0.1:' . $port . '/', 1);
        fclose($socket);

        self::assertFalse($result['ok']);
        self::assertSame(\RuntimeException::class, $result['class'], 'the request was never sent');
    }

    public function testACallNobodyTookIsNoTimeout(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $result = $this->fetch('https://127.0.0.1:' . $port . '/', 1);

        self::assertFalse($result['ok']);
        self::assertSame(\RuntimeException::class, $result['class'], 'a refused connection is not that the far end was slow');
    }
}
