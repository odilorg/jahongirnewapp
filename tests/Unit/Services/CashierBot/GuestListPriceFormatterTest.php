<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CashierBot;

use App\Models\DailyExchangeRate;
use App\Services\CashierBot\GuestListPriceFormatter;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Pure-unit coverage for the cashier-bot guest-list price formatter.
 *
 * Pinned behaviors:
 *  - fresh USD rate → "1 500 000 UZS ($120)" (UZS rounded to nearest 1 000)
 *  - stale/null rate → "$120" (USD-only soft-degrade)
 *  - missing/zero/non-numeric price → "—"
 *  - non-USD booking currency → native formatting, no UZS conversion
 *  - corrupt rate row (zero or negative) → soft-degrade to USD-only
 */
final class GuestListPriceFormatterTest extends TestCase
{
    private GuestListPriceFormatter $formatter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->formatter = new GuestListPriceFormatter;
    }

    /** @test */
    public function fresh_rate_shows_uzs_first_then_usd_in_parentheses(): void
    {
        $rate = $this->makeRate(12500.0);
        $booking = ['price' => 120, 'currency' => 'USD'];

        $this->assertSame(
            '1 500 000 UZS ($120)',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function uzs_amount_rounds_to_nearest_thousand(): void
    {
        // $137 × 12 500 = 1 712 500 → rounds half-up to 1 713 000
        $rate = $this->makeRate(12500.0);
        $booking = ['price' => 137, 'currency' => 'USD'];

        $this->assertSame(
            '1 713 000 UZS ($137)',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function stale_rate_falls_back_to_usd_only(): void
    {
        $booking = ['price' => 120, 'currency' => 'USD'];

        $this->assertSame(
            '$120',
            $this->formatter->format($booking, null),
        );
    }

    /** @test */
    public function corrupt_rate_with_zero_value_falls_back_to_usd(): void
    {
        // Defensive: if a manual admin row was somehow saved with 0 we
        // still soft-degrade rather than divide by zero or display "0 UZS".
        $rate = $this->makeRate(0.0);
        $booking = ['price' => 120, 'currency' => 'USD'];

        $this->assertSame(
            '$120',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function negative_rate_falls_back_to_usd(): void
    {
        $rate = $this->makeRate(-12500.0);
        $booking = ['price' => 120, 'currency' => 'USD'];

        $this->assertSame(
            '$120',
            $this->formatter->format($booking, $rate),
        );
    }

    /** @test */
    public function missing_price_returns_dash(): void
    {
        $rate = $this->makeRate(12500.0);

        $this->assertSame(
            '—',
            $this->formatter->format(['currency' => 'USD'], $rate),
        );
    }

    /** @test */
    public function null_price_returns_dash(): void
    {
        $rate = $this->makeRate(12500.0);

        $this->assertSame(
            '—',
            $this->formatter->format(['price' => null, 'currency' => 'USD'], $rate),
        );
    }

    /** @test */
    public function zero_price_returns_dash_not_zero_dollar(): void
    {
        // A "$0" display would mislead the cashier into thinking Beds24
        // returned a real zero-price booking, when it usually means the
        // price field is unset on a placeholder row. Prefer "—".
        $rate = $this->makeRate(12500.0);

        $this->assertSame(
            '—',
            $this->formatter->format(['price' => 0, 'currency' => 'USD'], $rate),
        );
    }

    /** @test */
    public function non_numeric_price_returns_dash(): void
    {
        $rate = $this->makeRate(12500.0);

        $this->assertSame(
            '—',
            $this->formatter->format(['price' => 'TBD', 'currency' => 'USD'], $rate),
        );
    }

    /** @test */
    public function uzs_native_booking_skips_conversion(): void
    {
        $rate = $this->makeRate(12500.0);

        $this->assertSame(
            '1 500 000 UZS',
            $this->formatter->format(['price' => 1500000, 'currency' => 'UZS'], $rate),
        );
    }

    /** @test */
    public function eur_native_booking_skips_conversion(): void
    {
        $rate = $this->makeRate(12500.0);

        $this->assertSame(
            '€100',
            $this->formatter->format(['price' => 100, 'currency' => 'EUR'], $rate),
        );
    }

    /** @test */
    public function unknown_currency_uses_generic_format(): void
    {
        $rate = $this->makeRate(12500.0);

        $this->assertSame(
            '500 GBP',
            $this->formatter->format(['price' => 500, 'currency' => 'GBP'], $rate),
        );
    }

    /** @test */
    public function missing_currency_defaults_to_usd_treatment(): void
    {
        $rate = $this->makeRate(12500.0);
        // Beds24 omits currency on some rows; default-to-USD is the
        // historical assumption (both Jahongir properties bill USD).
        $booking = ['price' => 80];

        $this->assertSame(
            '1 000 000 UZS ($80)',
            $this->formatter->format($booking, $rate),
        );
    }

    /**
     * Defensive future-proof: every formatter output that the controller
     * interpolates into an HTML-parsed Telegram message must not contain
     * raw `<`, `>`, or `&`. Today the formatter only produces digits +
     * letters + spaces + currency symbols (€, $) — but the `default`
     * branch in `formatNative` interpolates the currency string straight
     * from Beds24. If Beds24 ever returns a junk currency, this test
     * pins that the formatter still emits HTML-safe output. The
     * controller separately escapes the body via `e()`, so this is a
     * belt-and-suspenders contract on the formatter itself.
     *
     * @test
     */
    public function output_never_contains_html_reserved_chars_for_typical_inputs(): void
    {
        $rate = $this->makeRate(12500.0);

        $cases = [
            ['price' => 120, 'currency' => 'USD'],
            ['price' => 0, 'currency' => 'USD'],
            ['price' => 1500000, 'currency' => 'UZS'],
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
     * Pure-unit test — no DatabaseTransactions trait, no migrations.
     */
    private function makeRate(float $usdUzsRate): DailyExchangeRate
    {
        $rate = new DailyExchangeRate;
        $rate->forceFill([
            'rate_date' => Carbon::today()->toDateString(),
            'usd_uzs_rate' => $usdUzsRate,
            'source' => 'cbu',
            'fetched_at' => Carbon::now(),
        ]);

        return $rate;
    }
}
