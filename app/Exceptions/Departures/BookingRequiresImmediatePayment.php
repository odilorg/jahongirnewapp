<?php

declare(strict_types=1);

namespace App\Exceptions\Departures;

use DomainException;

/**
 * Q4 hold-cap enforcement: thrown when a reservation arrives within 1h of
 * cutoff_at. There is no time for a 24h seat hold; frontend must redirect
 * the customer to immediate Octobank payment instead of the normal
 * "we'll send you a payment link" flow.
 */
class BookingRequiresImmediatePayment extends DomainException
{
}
