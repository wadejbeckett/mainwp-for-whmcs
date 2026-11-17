<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * A request that the far end took (the connection was made and the request sent) and did not answer
 * within the time limit. A refused connection, or one that could not be made in time, is a plain
 * \RuntimeException.
 */
final class HttpTimeout extends \RuntimeException
{
}
