<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * Why a dashboard call failed: one kind per cause, and a message in plain words that is safe to log
 * and show (no key, no file path).
 */
final class DashboardFailure extends \RuntimeException
{
    /** The address or the key is not set. */
    public const NOT_CONFIGURED = 'not configured';
    /** The address is not https://; nothing was sent. */
    public const NOT_HTTPS = 'not https';
    /** No answer: no connection, a connection that could not be made in time, a certificate refused, an answer too large to read. */
    public const UNREACHABLE = 'unreachable';
    /**
     * The dashboard took the call and did not answer within the module's time limit: it is slow
     * itself, or it waited in vain for a client's site that the call reaches out to.
     */
    public const TIMED_OUT = 'timed out';
    /** HTTP 401 or 403: the key is wrong, missing or lacks the right. */
    public const KEY_REFUSED = 'key refused';
    /** MainWP's own HTTP 404: the dashboard has no such site or client. A 404 that is not MainWP's is a bad answer. */
    public const NOT_FOUND = 'not found';
    /** HTTP 200 with success 0: MainWP said no, with its reason. */
    public const REFUSED = 'refused';
    /** MainWP refused to add a site because it already holds one at that address: nothing about the login it was given. */
    public const ALREADY_HELD = 'already held';
    /**
     * An earlier connection is in the way, which no retry and no login can clear: MainWP's plugin on
     * the site refused the dashboard (MainWP hides its words then), or the dashboard cannot mend the
     * site it still holds. Staff clear it by hand.
     */
    public const EARLIER_CONNECTION = 'earlier connection';
    /** Any other status, or an answer that is not MainWP's JSON. */
    public const BAD_ANSWER = 'bad answer';

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}
