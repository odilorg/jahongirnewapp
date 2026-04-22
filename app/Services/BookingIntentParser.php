<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\BookingBot\DeepSeekIntentParser;
use App\Services\BookingBot\IntentParseException;

/**
 * Coordinator that routes bot-intent parsing between strategies.
 *
 * Phase 10.4 commit 1 — this class is now a thin coordinator. The full
 * DeepSeek body has moved to `DeepSeekIntentParser`; this class simply
 * delegates. Commits 2–5 will add `LocalIntentParser`, `MessageNormalizer`,
 * wire a try-local-first path, log which parser handled each message, and
 * surface an operator-friendly error via `IntentParseException`.
 *
 * Public API (`parse()`, `validate()`) is the single seam every future
 * wrapper (retry, cache, metrics) must compose around. Keep stable.
 */
class BookingIntentParser
{
    public function __construct(
        private readonly DeepSeekIntentParser $remote,
    ) {}

    /**
     * @return array<string, mixed>
     * @throws IntentParseException
     */
    public function parse(string $message): array
    {
        return $this->remote->parse($message);
    }

    public function validate(array $parsed): bool
    {
        $validIntents = [
            'check_availability',
            'create_booking',
            'modify_booking',
            'cancel_booking',
            'view_bookings',
        ];

        return isset($parsed['intent']) && in_array($parsed['intent'], $validIntents, true);
    }
}
