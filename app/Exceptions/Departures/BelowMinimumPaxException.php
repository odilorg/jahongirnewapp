<?php

declare(strict_types=1);

namespace App\Exceptions\Departures;

use DomainException;

/**
 * Thrown by MarkDepartureGuaranteedAction when seats_booked is below
 * minimum_pax. Group departures only — DeparturePolicy::allowsAutoCancel()
 * gates whether the auto-cancel cron may then act.
 */
class BelowMinimumPaxException extends DomainException
{
}
