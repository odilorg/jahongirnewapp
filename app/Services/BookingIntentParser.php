<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\BookingBot\DeepSeekIntentParser;
use App\Services\BookingBot\IntentParseException;
use App\Services\BookingBot\LocalIntentParser;
use App\Support\BookingBot\MessageNormalizer;

/**
 * Coordinator: try the local regex parser first; on no-match, fall
 * through to the DeepSeek LLM adapter. Same public API as before the
 * split so every handler + test keeps working unchanged.
 *
 * Phase 10.4 commits 1–4 build this class up incrementally. Commit 5
 * adds the observability log line and the operator-friendly error
 * surface in ProcessBookingMessage.
 *
 * Stable seam (PROTECT across future phases): `parse(string): array`.
 * Retry / caching / metrics wrappers in later phases MUST compose
 * around DeepSeekIntentParser without changing this contract.
 */
class BookingIntentParser
{
    public function __construct(
        private readonly LocalIntentParser $local,
        private readonly DeepSeekIntentParser $remote,
        private readonly MessageNormalizer $normalizer,
    ) {}

    /**
     * @return array<string, mixed>
     * @throws IntentParseException
     */
    public function parse(string $message): array
    {
        $normalized = $this->normalizer->normalize($message);

        $local = $this->local->tryParse($normalized);
        if ($local !== null) {
            return $local;
        }

        // Pass the ORIGINAL message to the LLM, not the lowercased
        // normalized form — DeepSeek's prompt examples preserve case
        // sensitivity for guest names and property names, and we don't
        // want to hand it a pre-mangled input.
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
