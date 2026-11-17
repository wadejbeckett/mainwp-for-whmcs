<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;


/**
 * A WordPress administrator login. The password is never part of a log entry, an exception message
 * or a var_dump: __debugInfo hides it and LogText::clean removes it from any text it is given.
 */
final class Login
{
    public function __construct(
        public readonly string $username,
        #[\SensitiveParameter] public readonly string $password,
    ) {
    }

    /** @return array{username: string, password: string} */
    public function __debugInfo(): array
    {
        return ['username' => $this->username, 'password' => '(hidden)'];
    }
}
