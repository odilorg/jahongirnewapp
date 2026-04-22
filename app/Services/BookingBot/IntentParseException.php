<?php

declare(strict_types=1);

namespace App\Services\BookingBot;

use RuntimeException;

/**
 * Raised by intent parsers (remote LLM or local regex) when the input
 * cannot be parsed into a structured intent. Callers convert this into
 * an operator-friendly Telegram reply — see ProcessBookingMessage.
 *
 * Kept in this namespace (rather than App\Exceptions\BookingBot) so it
 * travels alongside the parsers it belongs to; the App\Exceptions tree
 * is reserved for cross-layer domain exceptions.
 */
final class IntentParseException extends RuntimeException
{
}
