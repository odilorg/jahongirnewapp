<?php

declare(strict_types=1);

namespace App\Exceptions\Departures;

use DomainException;

/**
 * Thrown by ConfirmDepartureAction when required supplier slots
 * (driver / guide / vehicle, per project policy) are missing.
 */
class SuppliersNotAssigned extends DomainException
{
}
