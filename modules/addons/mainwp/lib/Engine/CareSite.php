<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;


/**
 * One care site: the care add-on row it belongs to, the hosting service and client, the site's
 * address, its state, what the dashboard calls it once the module knows, and the site's other
 * administrators the dashboard can be pointed at when the care account is gone. The retry count is
 * the number of attempts the module itself has made since a person last acted: retries of a failed
 * connect, and, on a Connected site, whether the stored login was already tried for a connection
 * that broke.
 */
final class CareSite
{
    /**
     * @param list<string>|null $otherAdmins the logins of the site's other administrators (no care
     *                                      account), as MainWP listed them when the site was connected;
     *                                      null when they were never recorded
     */
    public function __construct(
        public readonly int $addonRowId,
        public readonly int $serviceId,
        public readonly int $clientId,
        public readonly string $siteUrl,
        public readonly State $state,
        public readonly ?int $dashboardClientId = null,
        public readonly ?int $dashboardSiteId = null,
        public readonly int $retryCount = 0,
        public readonly ?string $lastSync = null,
        public readonly ?string $lastError = null,
        public readonly ?string $retryAfter = null,
        public readonly ?array $otherAdmins = null,
    ) {
    }

    /** @param list<string>|null $logins the site's other administrators as MainWP listed them; null for never recorded */
    public function withOtherAdmins(?array $logins): self
    {
        return new self($this->addonRowId, $this->serviceId, $this->clientId, $this->siteUrl, $this->state, $this->dashboardClientId, $this->dashboardSiteId, $this->retryCount, $this->lastSync, $this->lastError, $this->retryAfter, $logins);
    }

    public function withState(State $state, ?string $lastError = null): self
    {
        return new self($this->addonRowId, $this->serviceId, $this->clientId, $this->siteUrl, $state, $this->dashboardClientId, $this->dashboardSiteId, $this->retryCount, $this->lastSync, $lastError, $this->retryAfter, $this->otherAdmins);
    }

    public function at(string $siteUrl): self
    {
        return new self($this->addonRowId, $this->serviceId, $this->clientId, $siteUrl, $this->state, $this->dashboardClientId, $this->dashboardSiteId, $this->retryCount, $this->lastSync, $this->lastError, $this->retryAfter, $this->otherAdmins);
    }

    public function retried(): self
    {
        return new self($this->addonRowId, $this->serviceId, $this->clientId, $this->siteUrl, $this->state, $this->dashboardClientId, $this->dashboardSiteId, $this->retryCount + 1, $this->lastSync, $this->lastError, $this->retryAfter, $this->otherAdmins);
    }

    /** Waiting for the client's approval on their own site: off the retry list for now, the last error cleared, and the retry count started again, as for any attempt a person makes. */
    public function connecting(): self
    {
        return new self($this->addonRowId, $this->serviceId, $this->clientId, $this->siteUrl, State::Connecting, $this->dashboardClientId, $this->dashboardSiteId, 0, $this->lastSync, null, null, $this->otherAdmins);
    }

    /** The dashboard no longer holds a site for it. */
    public function offDashboard(): self
    {
        return new self($this->addonRowId, $this->serviceId, $this->clientId, $this->siteUrl, $this->state, $this->dashboardClientId, null, $this->retryCount, $this->lastSync, $this->lastError, $this->retryAfter, $this->otherAdmins);
    }

    /** The retry just made does not count: it failed for a reason that was not the site's. */
    public function uncounted(): self
    {
        return new self($this->addonRowId, $this->serviceId, $this->clientId, $this->siteUrl, $this->state, $this->dashboardClientId, $this->dashboardSiteId, max(0, $this->retryCount - 1), $this->lastSync, $this->lastError, $this->retryAfter, $this->otherAdmins);
    }

    /**
     * A password attempt is under way: Connecting, the retry count started again, and the retry
     * already armed, so that an attempt that is cut off halfway is still tried again.
     */
    public function inFlight(string $retryAfter): self
    {
        return new self($this->addonRowId, $this->serviceId, $this->clientId, $this->siteUrl, State::Connecting, $this->dashboardClientId, $this->dashboardSiteId, 0, $this->lastSync, null, $retryAfter, $this->otherAdmins);
    }

    /** A new attempt by a person: the retry count starts again. */
    public function attempting(): self
    {
        return new self($this->addonRowId, $this->serviceId, $this->clientId, $this->siteUrl, $this->state, $this->dashboardClientId, $this->dashboardSiteId, 0, $this->lastSync, $this->lastError, $this->retryAfter, $this->otherAdmins);
    }

    /** Failed with the reason and nothing to retry: the client is prompted again. */
    public function failed(string $reason): self
    {
        return new self($this->addonRowId, $this->serviceId, $this->clientId, $this->siteUrl, State::Failed, $this->dashboardClientId, $this->dashboardSiteId, $this->retryCount, $this->lastSync, $reason, null, $this->otherAdmins);
    }

    public function connectedAs(int $dashboardSiteId, string $at): self
    {
        return new self($this->addonRowId, $this->serviceId, $this->clientId, $this->siteUrl, State::Connected, $this->dashboardClientId, $dashboardSiteId, 0, $at, null, null, $this->otherAdmins);
    }

    /** Care has ended for the site: nothing is left to retry, no reason stays, and the dashboard site is gone. */
    public function removed(): self
    {
        return new self($this->addonRowId, $this->serviceId, $this->clientId, $this->siteUrl, State::Removed, $this->dashboardClientId, null, 0, $this->lastSync, null, null, $this->otherAdmins);
    }

    /** Failed with the reason, and on the retry list: the daily check tries again after $retryAfter. */
    public function failedWithRetry(string $reason, string $retryAfter): self
    {
        return new self($this->addonRowId, $this->serviceId, $this->clientId, $this->siteUrl, State::Failed, $this->dashboardClientId, $this->dashboardSiteId, $this->retryCount, $this->lastSync, $reason, $retryAfter, $this->otherAdmins);
    }
}
