<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\BookingBot\DeepSeekIntentParser;
use App\Services\BookingBot\IntentParseException;
use App\Services\BookingBot\LocalIntentParser;
use App\Support\BookingBot\MessageNormalizer;
use Illuminate\Support\Facades\Log;

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
            $this->logParse('local', $message, $local['intent'] ?? null);
            return $local;
        }

        // Pass the ORIGINAL message to the LLM, not the lowercased
        // normalized form — DeepSeek's prompt examples preserve case
        // sensitivity for guest names and property names, and we don't
        // want to hand it a pre-mangled input.
        $remote = $this->remote->parse($message);
        $this->logParse('llm', $message, $remote['intent'] ?? null);
        return $remote;
    }

    /**
     * Single log line per parse — operator-usage telemetry. After
     * a few days of production data we can measure local coverage
     * (target: 70–80%) and expand regex patterns where LLM handles
     * commands that have clear grammar.
     *
     * Operator inputs routinely carry PII (guest names, phone numbers,
     * emails). Default log shape omits the full payload — we record
     * length + a 40-char prefix only. Set
     * LOG_BOOKING_BOT_DEBUG_PAYLOADS=true (→ config logging.booking_bot.
     * debug_payloads) in the env for debugging sessions to opt into
     * full-message capture.
     */
    private function logParse(string $path, string $message, ?string $intent): void
    {
        $payload = [
            'path'           => $path,
            'message_len'    => mb_strlen($message),
            'message_prefix' => mb_substr($message, 0, 40),
            'intent'         => $intent,
        ];

        if ((bool) config('logging.booking_bot.debug_payloads', false)) {
            $payload['message'] = $message;
        }

        Log::info('booking_bot.intent_parsed', $payload);
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
