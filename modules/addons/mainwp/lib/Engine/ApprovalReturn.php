<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * What became of a return from the client's own site: how it ended, one plain sentence, the add-on
 * it belongs to when the link was known, and whether a key that came back may still be on the site
 * (it could not be deleted there, or was never sent anywhere), so that the client can be told.
 */
final class ApprovalReturn
{
    /** The site is connected. */
    public const CONNECTED = 'connected';
    /** The key was used and the connection failed; the care site holds the reason. */
    public const FAILED = 'failed';
    /** The client declined on their site. */
    public const REJECTED = 'rejected';
    /** No such link. */
    public const UNKNOWN = 'unknown';
    /** What came back is not a key and a login name. */
    public const INVALID = 'invalid';
    /** The key was approved on another site than the one the link was made for. */
    public const MISMATCH = 'mismatch';
    /** The link ran out of time or was replaced by a newer one. */
    public const LAPSED = 'lapsed';
    /** The link was used before. */
    public const USED = 'used';
    /** The site is not waiting to be connected, or care is not active for it. */
    public const REFUSED = 'refused';
    /** Another request is working on the add-on right now; the key that came back was not used. */
    public const BUSY = 'busy';
    /** This same return is already being worked on by an earlier request (a reload): nothing is done twice. */
    public const RUNNING = 'running';
    /** Dry-run: nothing was used. */
    public const DRY_RUN = 'dry-run';

    public function __construct(
        public readonly string $result,
        public readonly string $message,
        public readonly ?int $addonRowId = null,
        public readonly bool $keyLeft = false,
    ) {
    }
}
