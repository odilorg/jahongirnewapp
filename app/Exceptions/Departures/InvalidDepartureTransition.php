<?php

declare(strict_types=1);

namespace App\Exceptions\Departures;

use DomainException;

/**
 * Thrown when an action attempts a state transition the lifecycle forbids.
 * See PHASE_0 §3 for the full transition table. Forward-only past
 * `guaranteed`; terminal statuses cannot transition.
 */
class InvalidDepartureTransition extends DomainException
{
}
