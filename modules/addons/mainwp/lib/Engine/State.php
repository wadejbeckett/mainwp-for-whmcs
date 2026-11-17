<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;


/** The states of a care site (GLOSSARY.md). "Billed" is not a state: it is read from the billing system each time. */
enum State: string
{
    case Provisioned = 'provisioned';
    case Connecting = 'connecting';
    case Connected = 'connected';
    case Suspended = 'suspended';
    case Removed = 'removed';
    case Failed = 'failed';
}
