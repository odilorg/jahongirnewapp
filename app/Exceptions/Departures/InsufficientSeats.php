<?php

declare(strict_types=1);

namespace App\Exceptions\Departures;

use DomainException;

/**
 * Thrown by ReserveSeatsForDepartureAction when a reservation request
 * exceeds remaining seats. Carries both numbers so the API response can
 * tell the frontend how many are actually available.
 */
class InsufficientSeats extends DomainException
{
    public function __construct(
        public readonly int $seatsRequested,
        public readonly int $seatsRemaining,
    ) {
        parent::__construct(
            "Requested {$seatsRequested} seats, only {$seatsRemaining} remaining"
        );
    }
}
