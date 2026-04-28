<?php

declare(strict_types=1);

namespace App\Exceptions\Departures;

use DomainException;

/**
 * Q1 enforcement: thrown by OpenDepartureAction when no matching
 * tour_price_tiers row exists for (tour_product_id, direction_id, type,
 * group_size <= capacity). Operator must add the tier in Tour Catalog
 * before opening the departure.
 */
class TourProductPricingMissing extends DomainException
{
}
