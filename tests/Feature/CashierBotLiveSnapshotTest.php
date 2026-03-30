<?php

namespace Tests\Feature;

use App\Http\Controllers\CashierBotController;
use Tests\TestCase;

/**
 * Unit-style tests for CashierBotController::extractLiveBookingSnapshot().
 *
 * These do not require a database or HTTP stack — they exercise the parsing
 * logic directly via a thin test subclass that exposes the protected method.
 */
class CashierBotLiveSnapshotTest extends TestCase
{
    // ── harness ──────────────────────────────────────────────────────────────

    /** Expose the protected helper for direct testing. */
    private function extract(?array $liveGuest, string $bid = '99999'): array
    {
        $controller = new class extends CashierBotController {
            public function __construct() {} // skip DI

            public function callExtract(?array $g, string $bid): array
            {
                return $this->extractLiveBookingSnapshot($g, $bid);
            }
        };

        return $controller->callExtract($liveGuest, $bid);
    }

    // ── null / missing payload ────────────────────────────────────────────────

    public function test_null_payload_returns_nulls_and_no_live_payload_source(): void
    {
        $snap = $this->extract(null);

        $this->assertNull($snap['guest_name']);
        $this->assertNull($snap['booking_amount']);
        $this->assertNull($snap['booking_currency']);
        $this->assertSame('no_live_payload', $snap['currency_source']);
    }

    // ── guest name ────────────────────────────────────────────────────────────

    public function test_guest_name_is_trimmed_first_and_last(): void
    {
        $snap = $this->extract([
            'firstName' => '  JOHN  ',
            'lastName'  => '  DOE  ',
            'price'     => 100,
            'deposit'   => 0,
        ]);

        $this->assertSame('JOHN DOE', $snap['guest_name']);
    }

    public function test_guest_name_is_null_when_both_fields_empty(): void
    {
        $snap = $this->extract(['firstName' => '', 'lastName' => '', 'price' => 50, 'deposit' => 0]);

        $this->assertNull($snap['guest_name']);
    }

    // ── outstanding amount ────────────────────────────────────────────────────

    public function test_amount_is_price_minus_deposit(): void
    {
        $snap = $this->extract(['price' => 100.0, 'deposit' => 30.0, 'firstName' => 'A', 'lastName' => 'B']);

        $this->assertSame(70.0, $snap['booking_amount']);
    }

    public function test_amount_falls_back_to_price_when_deposit_exceeds_price(): void
    {
        // Edge case: deposit > price should not produce a negative outstanding
        $snap = $this->extract(['price' => 50.0, 'deposit' => 80.0, 'firstName' => 'A', 'lastName' => 'B']);

        $this->assertSame(50.0, $snap['booking_amount']);
    }

    public function test_amount_is_null_when_price_is_zero(): void
    {
        $snap = $this->extract(['price' => 0, 'deposit' => 0, 'firstName' => 'A', 'lastName' => 'B']);

        $this->assertNull($snap['booking_amount']);
    }

    // ── currency extraction ───────────────────────────────────────────────────

    public function test_currency_extracted_from_usd_rate_description(): void
    {
        $snap = $this->extract([
            'price'           => 50.2,
            'deposit'         => 0,
            'firstName'       => 'VITALII',
            'lastName'        => 'K',
            'rateDescription' => '2026-06-07 (1141607 Standard Rate) USD 43.20 genius',
        ]);

        $this->assertSame('USD', $snap['booking_currency']);
        $this->assertSame('live_rate_description', $snap['currency_source']);
    }

    public function test_currency_extracted_from_eur_rate_description(): void
    {
        $snap = $this->extract([
            'price'           => 95.0,
            'deposit'         => 0,
            'firstName'       => 'ANNA',
            'lastName'        => 'M',
            'rateDescription' => '2026-07-01 (999 Deluxe Rate) EUR 95.00',
        ]);

        $this->assertSame('EUR', $snap['booking_currency']);
        $this->assertSame('live_rate_description', $snap['currency_source']);
    }

    public function test_currency_extracted_from_rub_rate_description(): void
    {
        $snap = $this->extract([
            'price'           => 8700.0,
            'deposit'         => 0,
            'firstName'       => 'IVAN',
            'lastName'        => 'P',
            'rateDescription' => 'Standard Rate RUB 8700.00',
        ]);

        $this->assertSame('RUB', $snap['booking_currency']);
        $this->assertSame('live_rate_description', $snap['currency_source']);
    }

    public function test_currency_is_null_when_rate_description_missing(): void
    {
        $snap = $this->extract([
            'price'     => 50.0,
            'deposit'   => 0,
            'firstName' => 'A',
            'lastName'  => 'B',
            // no rateDescription key
        ]);

        $this->assertNull($snap['booking_currency']);
        $this->assertSame('live_payload_present_but_currency_regex_failed', $snap['currency_source']);
    }

    public function test_currency_is_null_when_rate_description_has_unknown_format(): void
    {
        $snap = $this->extract([
            'price'           => 50.0,
            'deposit'         => 0,
            'firstName'       => 'A',
            'lastName'        => 'B',
            'rateDescription' => 'Some internal rate without ISO code',
        ]);

        $this->assertNull($snap['booking_currency']);
        $this->assertSame('live_payload_present_but_currency_regex_failed', $snap['currency_source']);
    }

    public function test_currency_is_null_when_rate_description_empty_string(): void
    {
        $snap = $this->extract([
            'price'           => 50.0,
            'deposit'         => 0,
            'firstName'       => 'A',
            'lastName'        => 'B',
            'rateDescription' => '',
        ]);

        $this->assertNull($snap['booking_currency']);
        $this->assertSame('live_payload_present_but_currency_regex_failed', $snap['currency_source']);
    }

    // ── full realistic payload ────────────────────────────────────────────────

    public function test_full_realistic_beds24_payload_extracts_all_fields(): void
    {
        $snap = $this->extract([
            'id'              => '84615711',
            'firstName'       => 'VITALII',
            'lastName'        => 'KALIUSHKO',
            'price'           => 50.2,
            'deposit'         => 0,
            'tax'             => 0,
            'commission'      => 6.48,
            'rateDescription' => "2026-06-07 (1141607 Standard Rate) USD 43.20 genius\nTotal Commission: 6.48",
            'channel'         => 'booking',
            'arrival'         => '2026-06-07',
            'departure'       => '2026-06-08',
        ]);

        $this->assertSame('VITALII KALIUSHKO', $snap['guest_name']);
        $this->assertSame(50.2, $snap['booking_amount']);
        $this->assertSame('USD', $snap['booking_currency']);
        $this->assertSame('live_rate_description', $snap['currency_source']);
    }
}
