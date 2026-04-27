<?php

declare(strict_types=1);

namespace App\Exceptions\Meters;

/**
 * Raised by MeterReadingChainService when a reading violates a chain
 * invariant — backdated, regressed without a reset, override missing
 * its reason, etc.
 *
 * Caught by Filament rules + UtilityUsage::saving so the operator sees
 * a clear validation error instead of a 500.
 */
final class InvalidMeterReadingException extends \DomainException
{
}
