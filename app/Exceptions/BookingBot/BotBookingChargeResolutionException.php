<?php

declare(strict_types=1);

namespace App\Exceptions\BookingBot;

use RuntimeException;

/**
 * Thrown by ResolveBotBookingChargeAction when a bot booking's charge
 * cannot be resolved safely (invalid stay dates, invalid manual price,
 * unsupported currency, or a required-but-missing charge).
 *
 * CreateBookingFromMessageAction catches this and converts it into an
 * operator-facing Telegram reply; no Beds24 call is made.
 */
final class BotBookingChargeResolutionException extends RuntimeException
{
}
