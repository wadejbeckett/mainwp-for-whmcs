<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * A site as the dashboard holds it: its id, address, the dashboard client it is filed under, and its
 * connection state ("connected", "disconnected" or "suspended", as MainWP reports it), with the time
 * of the last sync and the sync error, if any, as one plain line. A site MainWP has flagged as staging answers with a
 * shorter row (no sync error) and is missing from the list (seen on a test bench).
 */
final class DashboardSite
{
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly ?int $clientId,
        public readonly string $status,
        public readonly ?string $lastSync,
        public readonly string $syncErrors,
    ) {
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /** The dashboard's last sync of the site failed; $syncErrors says why, in MainWP's words. */
    public function isDisconnected(): bool
    {
        return $this->status === 'disconnected';
    }
}
