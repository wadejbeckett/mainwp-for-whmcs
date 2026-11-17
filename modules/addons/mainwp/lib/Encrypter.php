<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

interface Encrypter
{
    public function encrypt(#[\SensitiveParameter] string $plain): string;

    /** @throws \RuntimeException when the text cannot be decrypted */
    public function decrypt(string $cipher): string;
}
