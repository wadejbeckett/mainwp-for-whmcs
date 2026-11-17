<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * An answer that passed CurlHttp::MAX_BODY: the far end answered, and nothing of it is used. A failed
 * request like any other, told apart only so that its words do not say the far end could not be reached.
 */
final class HttpTooLarge extends \RuntimeException
{
}
