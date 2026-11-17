<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
 * One request through the engine's CurlHttp, in a process of its own (so that its memory limit and
 * its certificate authority are its own), for CurlHttpTest. Arguments: the URL and the time limit in
 * seconds. Prints what came of it as JSON.
 */

require __DIR__ . '/../../modules/addons/mainwp/lib/Engine/Load.php';

[, $url, $seconds] = $argv;
try {
    $answer = (new MainWpForWhmcs\Engine\CurlHttp('test', (int) $seconds))->request('GET', $url, []);
    $result = ['ok' => true, 'status' => $answer->status, 'bytes' => strlen($answer->body)];
} catch (\Throwable $e) {
    $result = ['ok' => false, 'class' => get_class($e), 'message' => $e->getMessage()];
}
echo json_encode($result + ['peak' => memory_get_peak_usage(true)]);
