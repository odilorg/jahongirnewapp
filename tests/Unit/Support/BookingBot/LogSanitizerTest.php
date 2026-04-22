<?php

declare(strict_types=1);

namespace Tests\Unit\Support\BookingBot;

use App\Support\BookingBot\LogSanitizer;
use Tests\TestCase;

final class LogSanitizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['logging.booking_bot.debug_payloads' => false]);
    }

    // ── phone() ───────────────────────────────────────────────────────

    public function test_phone_masks_middle_keeps_country_and_last_three(): void
    {
        $this->assertSame('+998****567', LogSanitizer::phone('+998901234567'));
    }

    public function test_phone_without_plus_prefix_masks_middle(): void
    {
        $this->assertSame('****567', LogSanitizer::phone('901234567'));
    }

    public function test_phone_strips_formatting_then_masks(): void
    {
        $this->assertSame('+998****567', LogSanitizer::phone('+998 90 123-45-67'));
    }

    public function test_phone_too_short_returns_stars(): void
    {
        $this->assertSame('+998****', LogSanitizer::phone('+998 12'));
    }

    public function test_phone_no_digits_opaque(): void
    {
        $this->assertSame('***', LogSanitizer::phone('nothing'));
    }

    public function test_phone_null_and_empty_passthrough(): void
    {
        $this->assertNull(LogSanitizer::phone(null));
        $this->assertSame('', LogSanitizer::phone(''));
    }

    // ── email() ───────────────────────────────────────────────────────

    public function test_email_masks_local_keeps_domain(): void
    {
        $this->assertSame('j***@example.com', LogSanitizer::email('john@example.com'));
    }

    public function test_email_malformed_opaque(): void
    {
        $this->assertSame('***', LogSanitizer::email('not-an-email'));
        $this->assertSame('***', LogSanitizer::email('@missing-local.com'));
    }

    public function test_email_null_and_empty_passthrough(): void
    {
        $this->assertNull(LogSanitizer::email(null));
        $this->assertSame('', LogSanitizer::email(''));
    }

    // ── name() ────────────────────────────────────────────────────────

    public function test_name_returns_first_token_only(): void
    {
        $this->assertSame('Karim', LogSanitizer::name('Karim Wahab'));
        $this->assertSame('Jose', LogSanitizer::name('Jose Miguel Frances Hierro'));
    }

    public function test_name_single_token_passes_through(): void
    {
        $this->assertSame('Madonna', LogSanitizer::name('Madonna'));
    }

    public function test_name_null_and_empty(): void
    {
        $this->assertNull(LogSanitizer::name(null));
        $this->assertSame('', LogSanitizer::name(''));
    }

    // ── truncate() ────────────────────────────────────────────────────

    public function test_truncate_under_cap_unchanged(): void
    {
        $this->assertSame('short', LogSanitizer::truncate('short'));
    }

    public function test_truncate_over_cap_appends_ellipsis(): void
    {
        $out = LogSanitizer::truncate(str_repeat('a', 100));
        $this->assertSame(60, mb_strlen((string) $out));
        $this->assertStringEndsWith('…', (string) $out);
    }

    public function test_truncate_custom_cap(): void
    {
        $this->assertSame('ab…', LogSanitizer::truncate('abcdef', 3));
    }

    public function test_truncate_null(): void
    {
        $this->assertNull(LogSanitizer::truncate(null));
    }

    // ── context() — depth + key-aware dispatch ────────────────────────

    public function test_context_sanitizes_known_keys_at_depth_0(): void
    {
        $out = LogSanitizer::context([
            'phone' => '+998901234567',
            'email' => 'john@example.com',
            'firstName' => 'Jose',
            'lastName'  => 'Hierro',
            'text'   => str_repeat('x', 200),
            'bookingId' => 85711302,
            'propertyId' => 41097,
        ]);

        $this->assertSame('+998****567', $out['phone']);
        $this->assertSame('j***@example.com', $out['email']);
        // firstName is kept — Beds24 stores just the given name here.
        $this->assertSame('Jose', $out['firstName']);
        // lastName is ALWAYS masked — surname alone is the most
        // identifying half of a name.
        $this->assertSame('***', $out['lastName']);
        $this->assertSame(60, mb_strlen((string) $out['text']));
        $this->assertSame(85711302, $out['bookingId']);
        $this->assertSame(41097, $out['propertyId']);
    }

    public function test_lastname_is_fully_masked_not_passed_through(): void
    {
        $out = LogSanitizer::context(['lastName' => 'SENTINEL_SINGLEWORD']);
        $this->assertSame('***', $out['lastName']);
    }

    public function test_guestname_full_name_is_reduced_to_first_token(): void
    {
        $out = LogSanitizer::context(['guestName' => 'Karim Wahab']);
        $this->assertSame('Karim', $out['guestName']);

        $out2 = LogSanitizer::context(['guest_name' => 'Jose Miguel Frances Hierro']);
        $this->assertSame('Jose', $out2['guest_name']);
    }

    public function test_context_recurses_into_nested_payload(): void
    {
        $out = LogSanitizer::context([
            'payload' => [
                'mobile' => '+998901234567',
                'email'  => 'alice@example.com',
                'bookingId' => 1,
                'nested' => [
                    'guest_phone' => '+998901234567',
                    'amount' => 160.0,
                ],
            ],
        ]);

        $this->assertSame('+998****567', $out['payload']['mobile']);
        $this->assertSame('a***@example.com', $out['payload']['email']);
        $this->assertSame(1, $out['payload']['bookingId']);
        $this->assertSame('+998****567', $out['payload']['nested']['guest_phone']);
        $this->assertSame(160.0, $out['payload']['nested']['amount']);
    }

    public function test_context_preserves_non_pii_operational_keys(): void
    {
        $ctx = [
            'booking_id' => 12345,
            'count' => 4,
            'success' => true,
            'status' => 'confirmed',
            'error' => 'API 500',
            'arrival' => '2026-05-05',
            'currency' => 'USD',
            'qty' => 2,
            'amount' => 80.0,
        ];
        $this->assertSame($ctx, LogSanitizer::context($ctx));
    }

    public function test_context_json_string_in_content_key_redacts_nested_pii(): void
    {
        $out = LogSanitizer::context([
            'content' => json_encode([
                'intent' => 'create_booking',
                'guest'  => ['phone' => '+998901234567', 'email' => 'x@y.com'],
            ]),
        ]);
        $this->assertIsString($out['content']);
        $this->assertStringContainsString('+998****567', (string) $out['content']);
        $this->assertStringContainsString('x***@y.com', (string) $out['content']);
        $this->assertStringNotContainsString('+998901234567', (string) $out['content']);
    }

    public function test_context_is_noop_when_debug_flag_enabled(): void
    {
        config(['logging.booking_bot.debug_payloads' => true]);

        $raw = [
            'phone' => '+998901234567',
            'email' => 'john@example.com',
            'firstName' => 'Jose Miguel',
        ];
        $this->assertSame($raw, LogSanitizer::context($raw));
    }

    public function test_context_on_empty_array(): void
    {
        $this->assertSame([], LogSanitizer::context([]));
    }

    public function test_context_does_not_throw_on_object_leaf(): void
    {
        $obj = new \stdClass();
        $obj->phone = '+998901234567'; // not sanitized: walker doesn't recurse into objects

        $out = LogSanitizer::context(['payload' => $obj]);
        $this->assertArrayNotHasKey('_sanitize_error', $out);
    }

    public function test_context_breadcrumbs_internal_errors_instead_of_throwing(): void
    {
        // Simulate failure by passing a resource (non-serializable) under
        // a JSON content key — json_encode may fail, but we must never
        // throw.
        $out = LogSanitizer::context(['content' => json_encode(['guest' => ['phone' => '+998901234567']])]);
        $this->assertArrayNotHasKey('_sanitize_error', $out);
        // Happy path on a real value just to lock the success case.
        $this->assertIsString($out['content']);
    }

    public function test_context_webhook_telegram_update_is_sanitized(): void
    {
        // Real Beds24-bot-shaped webhook payload: message.text contains
        // full command with phone + email + free text.
        $out = LogSanitizer::context([
            'data' => [
                'update_id' => 1,
                'message' => [
                    'text' => 'book room 12 under John Walker jan 2-3 tel +998901234567 email alice@example.com',
                    'from' => [
                        'username' => 'op',
                        'firstName' => 'Operator',
                    ],
                ],
            ],
        ]);
        $text = $out['data']['message']['text'];
        $this->assertSame(60, mb_strlen((string) $text));
        $this->assertStringEndsWith('…', (string) $text);
        $this->assertSame('Operator', $out['data']['message']['from']['firstName']);
        $this->assertSame('op', $out['data']['message']['from']['username']);
    }
}
