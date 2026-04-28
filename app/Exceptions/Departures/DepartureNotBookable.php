<?php

declare(strict_types=1);

namespace App\Exceptions\Departures;

use DomainException;

/**
 * Thrown by ReserveSeatsForDepartureAction when the departure cannot
 * accept new bookings (status not in PUBLIC_STATUSES, or cutoff_at
 * has passed).
 */
class DepartureNotBookable extends DomainException
{
}
