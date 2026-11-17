<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * Why a client's site refused or could not be reached: one kind per cause, and a message in plain
 * words with the next step, safe to log and to show to the client and staff (no password, no file path).
 */
final class SiteFailure extends \RuntimeException
{
    /** No answer: DNS, connection, timeout or certificate. */
    public const UNREACHABLE = 'unreachable';
    /** The address is not https://; the module never sends a password over plain HTTP. */
    public const NOT_HTTPS = 'not https';
    /** The address sends every visitor on to another host (for example the "www." form): the site lives there. */
    public const MOVED = 'moved';
    /** wp-login.php answers 404 or sends the browser elsewhere: the login page was moved. */
    public const LOGIN_NOT_FOUND = 'login not found';
    /** The login page carries a captcha. */
    public const CAPTCHA = 'captcha';
    /** A login limiter has locked logins out. */
    public const LIMITER = 'limiter';
    /** The login asks for a second step. */
    public const TWO_FACTOR = 'two-factor';
    /** WordPress did not accept the username or password. */
    public const LOGIN_REFUSED = 'login refused';
    /** HTTP authentication or a firewall in front of WordPress. */
    public const BLOCKED = 'blocked';
    /** The account is not an administrator. */
    public const NOT_ADMIN = 'not admin';
    /** WordPress refused the plugin install (file changes forbidden). */
    public const INSTALL_FORBIDDEN = 'install forbidden';
    /** The install or the activation failed for another reason. */
    public const INSTALL_FAILED = 'install failed';
    /** WordPress did not accept the key (HTTP 401): it is wrong or gone, or the server does not pass the Authorization header on. */
    public const KEY_REFUSED = 'key refused';
    /** WordPress refused to make, reset or remove the care account. */
    public const ACCOUNT_REFUSED = 'account refused';
    /** The site answered something WordPress does not answer (REST API missing or blocked, no session). */
    public const BAD_ANSWER = 'bad answer';

    /**
     * @param string|null $movedTo for MOVED: the address the site sends visitors to
     * @param string $staffNote what only staff need to know about the cause; it goes to the log, never to the client
     */
    public function __construct(public readonly string $kind, string $message, public readonly ?string $movedTo = null, public readonly string $staffNote = '')
    {
        parent::__construct($message);
    }
}
