<?php

declare(strict_types=1);

namespace Tests\Feature\BookingBot;

use App\Services\BookingBot\LocalIntentParser;
use App\Services\Beds24BookingService;
use App\Support\BookingBot\LogSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Integration assertion for Phase 10.7 — verifies no sentinel PII
 * value leaks into the structured log context from any booking-bot
 * pipeline call when logging.booking_bot.debug_payloads is false
 * (default). Also proves the escape-hatch flag restores full payload
 * visibility for debugging sessions.
 *
 * Deliberately narrow: we exercise the call sites that previously
 * leaked, not the whole Job-dispatch flow, to keep the test fast and
 * focused. Booking-bot integration tests elsewhere already cover
 * end-to-end wiring.
 */
final class LogRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const SENTINEL_PHONE    = '+998000000SENTINEL';
    private const SENTINEL_EMAIL    = 'sentinel@example.test';
    private const SENTINEL_LASTNAME = 'SENTINEL_LAST';

    // Deliberately > 60 chars so truncation actually fires and the
    // tail never appears in logs. Short free-text (< 60 chars) is
    // intentional design: operator notes like "Transferred from X to
    // Y" are operationally useful and stay visible.
    private const SENTINEL_FREE = 'SENTINEL_FREETEXT_MUST_NOT_APPEAR_AND_THIS_SECOND_HALF_IS_PII_LEAK_PROOF';
    private const SENTINEL_FREE_TAIL = 'AND_THIS_SECOND_HALF_IS_PII_LEAK_PROOF';

    /** @var array<int, array{level: string, message: ?string, context: array<string,mixed>}> */
    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->captured = [];
        config(['logging.booking_bot.debug_payloads' => false]);

        // Swap Log with a lightweight capturing double so we can scan
        // every context payload for sentinel leaks.
        $sink = new class($this->captured) {
            /** @param array<int, mixed> $captured */
            public function __construct(private array &$captured) {}

            public function __call(string $method, array $args): void
            {
                $this->captured[] = [
                    'level'   => $method,
                    'message' => $args[0] ?? null,
                    'context' => $args[1] ?? [],
                ];
            }
        };
        Log::swap($sink);
    }

    public function test_log_swap_sink_actually_captures_default_channel(): void
    {
        // Sanity guard — if someone reroutes booking-bot logs to a
        // named channel in the future, Log::swap() here would miss
        // them and every PII assertion in the next test would silently
        // pass. This probe fails loudly when the sink isn't wired.
        Log::info('probe', ['leak_sentinel' => 'PROBE_MARKER']);
        $this->assertNotEmpty($this->captured);
        $this->assertSame('probe', $this->captured[0]['message']);
        $this->assertSame('PROBE_MARKER', $this->captured[0]['context']['leak_sentinel']);
    }

    public function test_sentinel_pii_does_not_appear_in_any_log_context_when_flag_off(): void
    {
        // Exercise the 4 call sites that previously leaked PII, via
        // real Beds24BookingService + LocalIntentParser code paths.

        // 1. Emulate an outbound "Creating Beds24 booking" style log —
        //    call Beds24BookingService::createBookingFromPayload() with
        //    a sentinel-laden payload. Http is not mocked, so this will
        //    throw; we only care that the pre-HTTP "Beds24 Create
        //    Booking Request" log runs through the sanitizer.
        try {
            app(Beds24BookingService::class)->createBookingFromPayload([
                'propertyId' => 41097,
                'roomId'     => 555,
                'arrival'    => '2030-05-05',
                'departure'  => '2030-05-07',
                'firstName'  => 'SentinelFirst',
                'lastName'   => self::SENTINEL_LASTNAME,
                'mobile'     => self::SENTINEL_PHONE,
                'email'      => self::SENTINEL_EMAIL,
                'status'     => 'confirmed',
                'notes'      => self::SENTINEL_FREE,
            ]);
        } catch (\Throwable) {
            // Expected — we don't stub HTTP. The log WAS emitted.
        }

        // 2. Local-parser path for a fuzzy view command — goes through
        //    the coordinator, which logs 'booking_bot.intent_parsed'.
        app(LocalIntentParser::class)->tryParse('bookings today ' . self::SENTINEL_FREE);

        // Now scan every captured context for any sentinel string.
        $this->assertNotEmpty($this->captured, 'expected at least one log call');

        foreach ($this->captured as $entry) {
            $encoded = json_encode($entry['context'] ?? [], JSON_UNESCAPED_UNICODE);
            $this->assertIsString($encoded);

            $this->assertStringNotContainsString(
                self::SENTINEL_PHONE, $encoded,
                "phone sentinel leaked in log '{$entry['message']}'"
            );
            $this->assertStringNotContainsString(
                self::SENTINEL_EMAIL, $encoded,
                "email sentinel leaked in log '{$entry['message']}'"
            );
            $this->assertStringNotContainsString(
                self::SENTINEL_LASTNAME, $encoded,
                "last-name sentinel leaked in log '{$entry['message']}'"
            );
            // Tail of the long free-text MUST be truncated away. Head
            // may survive (by design — short notes are operational).
            $this->assertStringNotContainsString(
                self::SENTINEL_FREE_TAIL, $encoded,
                "free-text tail leaked past 60-char truncation in log '{$entry['message']}'"
            );
        }
    }

    public function test_process_booking_message_generic_catch_does_not_leak_update_pii(): void
    {
        // Dispatch a ProcessBookingMessage with a PII-laden update,
        // force the handler to hit the generic \Exception catch by
        // supplying a malformed update that trips the chat_id access.
        // The goal: the catch's Log::error MUST sanitize $this->update.
        $update = [
            'update_id' => 99,
            'message' => [
                // Intentionally missing 'message_id'/'chat' — forces an
                // unhandled \Exception after initial PII-bearing text
                // has been captured into the log scope.
                'text' => 'book room 12 tel ' . self::SENTINEL_PHONE
                        . ' email ' . self::SENTINEL_EMAIL
                        . ' note ' . self::SENTINEL_FREE,
            ],
        ];

        try {
            (new \App\Jobs\ProcessBookingMessage($update))->handle(
                app(\App\Services\StaffAuthorizationService::class),
                app(\App\Services\BookingIntentParser::class),
                app(\App\Services\TelegramBotService::class),
                app(\App\Services\TelegramKeyboardService::class),
            );
        } catch (\Throwable) {
            // Don't care if the Job bubbles — we only need to observe
            // whatever the generic catch emitted.
        }

        foreach ($this->captured as $entry) {
            $encoded = json_encode($entry['context'] ?? [], JSON_UNESCAPED_UNICODE);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString(self::SENTINEL_PHONE, $encoded,
                'phone leaked via generic \\Exception catch in ProcessBookingMessage');
            $this->assertStringNotContainsString(self::SENTINEL_EMAIL, $encoded,
                'email leaked via generic \\Exception catch in ProcessBookingMessage');
            $this->assertStringNotContainsString(self::SENTINEL_FREE_TAIL, $encoded,
                'free-text tail leaked via generic \\Exception catch in ProcessBookingMessage');
        }
    }

    public function test_debug_flag_restores_full_payload_for_debugging(): void
    {
        config(['logging.booking_bot.debug_payloads' => true]);

        // Manually invoke the sanitizer the way a real call site does —
        // with flag ON, it should be a no-op and keep the sentinels.
        $sanitized = LogSanitizer::context([
            'mobile' => self::SENTINEL_PHONE,
            'email'  => self::SENTINEL_EMAIL,
            'text'   => self::SENTINEL_FREE,
        ]);

        $encoded = json_encode($sanitized, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($encoded);
        $this->assertStringContainsString(self::SENTINEL_PHONE, $encoded);
        $this->assertStringContainsString(self::SENTINEL_EMAIL, $encoded);
        // With flag ON, the FULL free-text (tail included) survives.
        $this->assertStringContainsString(self::SENTINEL_FREE_TAIL, $encoded);
    }
}
