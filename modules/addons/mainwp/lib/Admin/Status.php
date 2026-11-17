<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Admin;

use MainWpForWhmcs\Engine\State;

/**
 * The status staff read for a site. The module's own states keep their names (GLOSSARY.md;
 * "provisioned" and "connected" are this module's own words); where the MainWP Dashboard
 * has a word for a site's condition it is MainWP's: Connected, Disconnected, Suspended. A billed
 * add-on with no care site yet is "Not provisioned". Each status has the class of one of WHMCS's
 * own status labels, so the admin theme colours it, and one line for the key under the list.
 */
final class Status
{
    public const NOT_PROVISIONED = 'notprovisioned';
    public const DISCONNECTED = 'disconnected';

    /** @var array<string, array{label: string, class: string, means: string}> in the order of a site's life */
    public const ALL = [
        self::NOT_PROVISIONED => ['label' => 'Not provisioned', 'class' => 'pending', 'means' => 'Paid for. The client is added to MainWP at the next check.'],
        'provisioned' => ['label' => 'Provisioned', 'class' => 'pending', 'means' => 'Paid for, and the client is in MainWP. The site is not connected yet.'],
        'connecting' => ['label' => 'Connecting', 'class' => 'pending', 'means' => 'Connecting, or waiting for the client to approve on their site.'],
        'connected' => ['label' => 'Connected', 'class' => 'active', 'means' => 'Connected to MainWP and syncing.'],
        self::DISCONNECTED => ['label' => 'Disconnected', 'class' => 'terminated', 'means' => 'MainWP cannot reach the site.'],
        'failed' => ['label' => 'Failed', 'class' => 'terminated', 'means' => 'Connecting did not work. It is tried again daily, up to seven times.'],
        'suspended' => ['label' => 'Suspended', 'class' => 'suspended', 'means' => 'Suspended in MainWP while the add-on or its hosting is suspended in WHMCS.'],
        'removed' => ['label' => 'Removed', 'class' => 'cancelled', 'means' => 'Removed from MainWP because the add-on ended in WHMCS.'],
    ];

    /** The status of a care site in this state; a connected site the dashboard reports as disconnected is Disconnected. */
    public static function of(?State $state, bool $disconnected = false): string
    {
        return match (true) {
            $state === null => self::NOT_PROVISIONED,
            $state === State::Connected && $disconnected => self::DISCONNECTED,
            default => $state->value,
        };
    }

    public static function label(string $status): string
    {
        return self::ALL[$status]['label'] ?? '';
    }

    /** MainWP's own word for a site it holds ("connected", "disconnected", "suspended"), as a status; '' for any other word. */
    public static function ofDashboard(string $word): string
    {
        return in_array($word, ['connected', self::DISCONNECTED, 'suspended'], true) ? $word : '';
    }
}
