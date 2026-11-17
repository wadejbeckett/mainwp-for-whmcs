<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

use MainWpForWhmcs\Engine\Client;

/**
 * One product add-on row of WHMCS (tblhostingaddons) as the WHMCS layer reads it: the row, its
 * hosting service, its client, how many unpaid invoices itemise it, and whether it is set to free
 * (GLOSSARY.md, "Free care add-on"). Read fresh each time; "billed" is never stored.
 */
final class AddonRow
{
    public function __construct(
        public readonly int $id,
        public readonly int $serviceId,
        public readonly int $clientId,
        public readonly int $addonId,
        public readonly string $status,
        public readonly string $serviceStatus,
        public readonly string $domain,
        public readonly string $clientName,
        public readonly string $clientEmail,
        public readonly int $unpaidInvoices,
        public readonly bool $free = false,
    ) {
    }

    /** Active, on an Active hosting service, with no unpaid invoice (GLOSSARY.md, "Billed"). */
    public function billed(): bool
    {
        return $this->status === 'Active' && $this->serviceStatus === 'Active' && $this->unpaidInvoices === 0;
    }

    /**
     * Active on an Active hosting service: care runs, so the site may be connected and a suspended
     * site resumed. An open invoice does not stop that; WHMCS suspends the add-on when it is overdue.
     */
    public function active(): bool
    {
        return $this->status === 'Active' && $this->serviceStatus === 'Active';
    }

    /** Suspended itself, or on a suspended hosting service. */
    public function suspended(): bool
    {
        return $this->status === 'Suspended' || ($this->status === 'Active' && $this->serviceStatus === 'Suspended');
    }

    /** Why the row is not active(), in plain words, or null when it is. */
    public function notActiveBecause(): ?string
    {
        return match (true) {
            $this->status !== 'Active' => 'the add-on is ' . $this->status . ', not Active',
            $this->serviceStatus !== 'Active' => 'its hosting service is ' . $this->serviceStatus . ', not Active',
            default => null,
        };
    }

    /** Why the row is not billed, in plain words, or null when it is. */
    public function notBilledBecause(): ?string
    {
        return $this->notActiveBecause() ?? ($this->unpaidInvoices > 0 ? 'it has an unpaid invoice' : null);
    }

    /**
     * Why the row would not be billed once WHMCS sets it Active, or null: notBilledBecause() with the
     * add-on's own status left out (its hosting service not Active, or an unpaid invoice). WHMCS calls
     * the provisioning module's CreateAccount while the row is still Pending, so billed() cannot be
     * asked there.
     */
    public function notBilledOnceActiveBecause(): ?string
    {
        return match (true) {
            $this->serviceStatus !== 'Active' => 'its hosting service is ' . $this->serviceStatus . ', not Active',
            $this->unpaidInvoices > 0 => 'it has an unpaid invoice',
            default => null,
        };
    }

    /** The care site's default address: the hosting service's domain over HTTPS, or '' when the service has none. */
    public function siteUrl(): string
    {
        $domain = strtolower(trim($this->domain, " \t\n\r\0\x0B/"));
        return $domain === '' ? '' : 'https://' . $domain;
    }

    public function client(): Client
    {
        return new Client($this->clientId, $this->clientName, $this->clientEmail);
    }
}
