<?php

declare(strict_types=1);

namespace App\Exceptions\Departures;

use DomainException;

/**
 * Thrown when a departure fails the Q7 pre-flight validation gate
 * (missing tour, capacity invalid, no pickup point, etc.). Carries
 * the ValidationReport for operator-facing display.
 */
class InvalidDepartureConfiguration extends DomainException
{
}
