<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CashierBot;

use App\Models\DailyExchangeRate;
use App\Services\BookingPaymentOptionsService;
use App\Services\CashierBot\GuestListPriceFormatter;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Unit coverage for the cashier-bot guest-list price formatter.
 *
 * Pinned behaviors:
 *  - no payments yet (or invoiceItems missing) → "к оплате: ... ($total)"
 *  - partial payment → "к оплате: outstanding UZS ($out из $total)"
 *  - fully paid → "оплачено: total UZS ($total)"
 *  - missing/zero/non-numeric price → "—"
 *  - non-USD booking currency → native formatting, no UZS conversion
 *  - stale/null rate → USD-only fall-back (label preserved, no UZS)
 *  - corrupt rate row (zero/negative usd_uzs_rate) → USD-only fall-back
 *  - UZS rounding uses BookingPaymentOptionsService::ceilToIncrement
 *    and reads uzs_rounding_increment from the rate row (matches
 *    payment-prep byte-for-byte for the same USD source)
 */
final class GuestListPriceFormatterTest extends TestCase
{
    private GuestListPriceFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new GuestListPriceFormatter(new BookingPaymentOptionsService);
    }

    /** @test */
    public function no_payments_shows_full_total_as_payable(): void
    {
        $rate = $this->makeRate(12_500.0, 1000);
        $booking = ['price' => 120, 'currency' => 'USD'];

        // 120 × 12 500 = 1 500 000, ceil to 1 000 increment = 1 500 000
        $this->assertSame(
            'к оплате: 1 500 000 UZS ($120)',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function partial_payment_shows_outstanding_first_with_total_in_context(): void
    {
        $rate = $this->makeRate(12_500.0, 1000);
        $booking = [
            'price' => 120,
            'currency' => 'USD',
            // Beds24 stores payments as NEGATIVE invoiceItems.
            // $60 prepaid → outstanding $60 of $120.
            'invoiceItems' => [
                ['amount' => -60.0, 'type' => 'payment'],
            ],
        ];

        // outstanding 60 × 12 500 = 750 000
        $this->assertSame(
            'к оплате: 750 000 UZS ($60 из $120)',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function fully_paid_shows_paid_label_with_total(): void
    {
        $rate = $this->makeRate(12_500.0, 1000);
        $booking = [
            'price' => 120,
            'currency' => 'USD',
            'invoiceItems' => [
                ['amount' => -120.0, 'type' => 'payment'],
            ],
        ];

        $this->assertSame(
            'оплачено: 1 500 000 UZS ($120)',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function overpaid_treated_as_fully_paid_with_total_shown(): void
    {
        // Rare but defensive: guest overpaid (price=$120, paid=$130 →
        // outstanding=-$10). Treat as fully paid; cashier should not
        // be prompted to "collect" a negative amount.
        $rate = $this->makeRate(12_500.0, 1000);
        $booking = [
            'price' => 120,
            'currency' => 'USD',
            'invoiceItems' => [
                ['amount' => -130.0, 'type' => 'payment'],
            ],
        ];

        $this->assertSame(
            'оплачено: 1 500 000 UZS ($120)',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function missing_invoice_items_falls_back_to_total_as_outstanding(): void
    {
        // Older Beds24 response shapes, or listing-fetch that didn't
        // request invoiceItems, may omit the array entirely. Safe
        // default: treat as no payments yet.
        $rate = $this->makeRate(12_500.0, 1000);
        $booking = ['price' => 120, 'currency' => 'USD'];

        $this->assertSame(
            'к оплате: 1 500 000 UZS ($120)',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function empty_invoice_items_treated_as_no_payments_yet(): void
    {
        $rate = $this->makeRate(12_500.0, 1000);
        $booking = ['price' => 120, 'currency' => 'USD', 'invoiceItems' => []];

        $this->assertSame(
            'к оплате: 1 500 000 UZS ($120)',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function positive_invoice_items_treated_as_charges_not_payments(): void
    {
        // Beds24 convention: positive=charge, negative=payment.
        // A booking with only positive items (no prepayments) is
        // unpaid — outstanding == total.
        $rate = $this->makeRate(12_500.0, 1000);
        $booking = [
            'price' => 120,
            'currency' => 'USD',
            'invoiceItems' => [
                ['amount' => 100.0, 'type' => 'charge'],
                ['amount' => 20.0, 'type' => 'extra'],
            ],
        ];

        $this->assertSame(
            'к оплате: 1 500 000 UZS ($120)',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function uzs_rounding_uses_ceil_not_round_half_up(): void
    {
        // $80 × 12 115 = 969 200. Half-up rounding to 1 000 would
        // round DOWN to 969 000. Payment-prep uses ceil → 970 000.
        // The listing must match the payment-prep value.
        $rate = $this->makeRate(12_115.0, 1000);
        $booking = ['price' => 80, 'currency' => 'USD'];

        $this->assertSame(
            'к оплате: 970 000 UZS ($80)',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function uzs_rounding_respects_custom_increment_from_rate_row(): void
    {
        // Operator-tunable increment via daily_exchange_rates.uzs_rounding_increment.
        // Bumping it to 5 000 must apply to the listing same as to
        // payment-prep — no hard-coded 1 000 anywhere.
        $rate = $this->makeRate(12_115.0, 5000);
        $booking = ['price' => 80, 'currency' => 'USD'];

        // ceil(969 200 / 5 000) × 5 000 = 970 000 (still 970 000 here,
        // since the exact value lies between 965 000 and 970 000).
        $this->assertSame(
            'к оплате: 970 000 UZS ($80)',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function uzs_rounding_with_10000_increment_rounds_up_clearly(): void
    {
        $rate = $this->makeRate(12_115.0, 10_000);
        $booking = ['price' => 80, 'currency' => 'USD'];

        // ceil(969 200 / 10 000) × 10 000 = 970 000
        $this->assertSame(
            'к оплате: 970 000 UZS ($80)',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function partial_payment_uses_ceil_rounding_on_outstanding_only(): void
    {
        $rate = $this->makeRate(12_115.0, 1000);
        $booking = [
            'price' => 120,
            'currency' => 'USD',
            'invoiceItems' => [
                ['amount' => -60.0, 'type' => 'payment'],
            ],
        ];

        // outstanding 60 × 12 115 = 726 900 → ceil(726.9) × 1000 = 727 000
        $this->assertSame(
            'к оплате: 727 000 UZS ($60 из $120)',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function stale_rate_falls_back_to_usd_only_no_payments(): void
    {
        // Soft-degrade: listing must keep working when FX rate is
        // stale/missing. Hard FX guard still fires later at payment
        // time, so cashier can browse but won't be able to record
        // a payment until the rate is refreshed.
        $booking = ['price' => 120, 'currency' => 'USD'];

        $this->assertSame(
            'к оплате: $120',
            $this->formatter->format($booking, null),
        );
    }

    /** @test */
    public function stale_rate_falls_back_to_usd_only_partial_paid(): void
    {
        $booking = [
            'price' => 120,
            'currency' => 'USD',
            'invoiceItems' => [
                ['amount' => -60.0, 'type' => 'payment'],
            ],
        ];

        $this->assertSame(
            'к оплате: $60 из $120',
            $this->formatter->format($booking, null),
        );
    }

    /** @test */
    public function stale_rate_falls_back_to_usd_only_fully_paid(): void
    {
        $booking = [
            'price' => 120,
            'currency' => 'USD',
            'invoiceItems' => [
                ['amount' => -120.0, 'type' => 'payment'],
            ],
        ];

        $this->assertSame(
            'оплачено: $120',
            $this->formatter->format($booking, null),
        );
    }

    /** @test */
    public function corrupt_rate_with_zero_value_falls_back_to_usd_only(): void
    {
        $rate = $this->makeRate(0.0, 1000);
        $booking = ['price' => 120, 'currency' => 'USD'];

        $this->assertSame(
            'к оплате: $120',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function negative_rate_falls_back_to_usd_only(): void
    {
        $rate = $this->makeRate(-12_500.0, 1000);
        $booking = ['price' => 120, 'currency' => 'USD'];

        $this->assertSame(
            'к оплате: $120',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function missing_price_returns_dash(): void
    {
        $rate = $this->makeRate(12_500.0, 1000);

        $this->assertSame(
            '—',
            $this->formatter->format(['currency' => 'USD'], $rate),
        );
    }

    /** @test */
    public function null_price_returns_dash(): void
    {
        $rate = $this->makeRate(12_500.0, 1000);

        $this->assertSame(
            '—',
            $this->formatter->format(['price' => null, 'currency' => 'USD'], $rate),
        );
    }

    /** @test */
    public function zero_price_returns_dash_not_zero_dollar(): void
    {
        $rate = $this->makeRate(12_500.0, 1000);

        $this->assertSame(
            '—',
            $this->formatter->format(['price' => 0, 'currency' => 'USD'], $rate),
        );
    }

    /** @test */
    public function non_numeric_price_returns_dash(): void
    {
        $rate = $this->makeRate(12_500.0, 1000);

        $this->assertSame(
            '—',
            $this->formatter->format(['price' => 'TBD', 'currency' => 'USD'], $rate),
        );
    }

    /** @test */
    public function uzs_native_booking_skips_conversion(): void
    {
        $rate = $this->makeRate(12_500.0, 1000);

        $this->assertSame(
            'к оплате: 1 500 000 UZS',
            $this->formatter->format(['price' => 1_500_000, 'currency' => 'UZS'], $rate),
        );
    }

    /** @test */
    public function eur_native_booking_skips_conversion(): void
    {
        $rate = $this->makeRate(12_500.0, 1000);

        $this->assertSame(
            'к оплате: €100',
            $this->formatter->format(['price' => 100, 'currency' => 'EUR'], $rate),
        );
    }

    /** @test */
    public function unknown_currency_uses_generic_format(): void
    {
        $rate = $this->makeRate(12_500.0, 1000);

        $this->assertSame(
            'к оплате: 500 GBP',
            $this->formatter->format(['price' => 500, 'currency' => 'GBP'], $rate),
        );
    }

    /** @test */
    public function output_never_contains_html_reserved_chars_for_typical_inputs(): void
    {
        // Belt-and-suspenders: the controller wraps formatter output in
        // e() before sending HTML-mode Telegram messages, but the
        // formatter itself should never produce <, >, or & for any
        // realistic input. Pins that contract.
        $rate = $this->makeRate(12_500.0, 1000);

        $cases = [
            ['price' => 120, 'currency' => 'USD'],
            ['price' => 120, 'currency' => 'USD', 'invoiceItems' => [['amount' => -60.0]]],
            ['price' => 120, 'currency' => 'USD', 'invoiceItems' => [['amount' => -120.0]]],
            ['price' => 0, 'currency' => 'USD'],
            ['price' => 1_500_000, 'currency' => 'UZS'],
            ['price' => 100, 'currency' => 'EUR'],
            ['price' => 500, 'currency' => 'GBP'],
            ['price' => 'TBD', 'currency' => 'USD'],
            ['price' => null, 'currency' => 'USD'],
        ];

        foreach ($cases as $booking) {
            $output = $this->formatter->format($booking, $rate);

            $this->assertStringNotContainsString('<', $output);
            $this->assertStringNotContainsString('>', $output);
            $this->assertStringNotContainsString('&', $output);
        }
    }

    /**
     * Build a `DailyExchangeRate` model instance without touching the DB.
     * Includes all fields the rate row needs for the formatter contract
     * (usd_uzs_rate + uzs_rounding_increment).
     */
    private function makeRate(float $usdUzsRate, int $uzsRoundingIncrement): DailyExchangeRate
    {
        $rate = new DailyExchangeRate;
        $rate->forceFill([
            'rate_date' => Carbon::today()->toDateString(),
            'usd_uzs_rate' => $usdUzsRate,
            'uzs_rounding_increment' => $uzsRoundingIncrement,
            'source' => 'cbu',
            'fetched_at' => Carbon::now(),
        ]);

        return $rate;
    }
}
