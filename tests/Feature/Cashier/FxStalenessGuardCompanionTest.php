<?php

declare(strict_types=1);

namespace Tests\Feature\Cashier;

use App\Models\DailyExchangeRate;
use App\Services\Fx\FxStalenessGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Coverage for the non-throwing `isFresh()` / `getFreshOrNull()`
 * companions to `FxStalenessGuard::ensureFreshOrFail()`.
 *
 * Single-source-of-truth invariant: the companions must agree
 * BYTE-FOR-BYTE with the throwing path's verdict on every fixture.
 * Anything else means listing-screen freshness can drift away from
 * payment-screen freshness, which would let stale rates surface in
 * the cashier-bot guest list while the payment guard still refuses.
 */
final class FxStalenessGuardCompanionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(CarbonImmutable::create(2026, 5, 11, 14, 0, 0, 'Asia/Tashkent'));

        DailyExchangeRate::query()->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function fresh_row_passes_both_companions(): void
    {
        DailyExchangeRate::create([
            'rate_date' => Carbon::today()->toDateString(),
            'usd_uzs_rate' => 12500,
            'source' => 'cbu',
            'fetched_at' => Carbon::now()->subHours(7),
        ]);

        $guard = app(FxStalenessGuard::class);

        $this->assertTrue($guard->isFresh());
        $rate = $guard->getFreshOrNull();
        $this->assertNotNull($rate);
        $this->assertSame('12500.0000', (string) $rate->usd_uzs_rate);
    }

    /** @test */
    public function yesterdays_row_fails_both_companions(): void
    {
        DailyExchangeRate::create([
            'rate_date' => Carbon::yesterday()->toDateString(),
            'usd_uzs_rate' => 12500,
            'source' => 'cbu',
            'fetched_at' => Carbon::now()->subHours(7),
        ]);

        $guard = app(FxStalenessGuard::class);

        $this->assertFalse($guard->isFresh());
        $this->assertNull($guard->getFreshOrNull());
    }

    /** @test */
    public function todays_row_with_absurd_fetched_at_fails_both_companions(): void
    {
        DailyExchangeRate::create([
            'rate_date' => Carbon::today()->toDateString(),
            'usd_uzs_rate' => 12500,
            'source' => 'manual',
            'fetched_at' => Carbon::now()->subDays(3),
        ]);

        $guard = app(FxStalenessGuard::class);

        $this->assertFalse($guard->isFresh());
        $this->assertNull($guard->getFreshOrNull());
    }

    /** @test */
    public function empty_table_fails_both_companions(): void
    {
        $guard = app(FxStalenessGuard::class);

        $this->assertFalse($guard->isFresh());
        $this->assertNull($guard->getFreshOrNull());
    }

    /** @test */
    public function get_fresh_or_null_returns_the_newest_when_two_rows_today(): void
    {
        // Cron at 07:00, then a manual override at 11:00 same day.
        // `getFreshOrNull` should return the manual row (newer id),
        // matching the throwing path's tie-breaker.
        DailyExchangeRate::create([
            'rate_date' => Carbon::today()->toDateString(),
            'usd_uzs_rate' => 12500,
            'source' => 'cbu',
            'fetched_at' => Carbon::now()->setTime(7, 0),
        ]);
        DailyExchangeRate::create([
            'rate_date' => Carbon::today()->toDateString(),
            'usd_uzs_rate' => 12750,
            'source' => 'manual',
            'fetched_at' => Carbon::now()->setTime(11, 0),
        ]);

        $guard = app(FxStalenessGuard::class);
        $rate = $guard->getFreshOrNull();

        $this->assertNotNull($rate);
        $this->assertSame('manual', $rate->source);
        $this->assertSame('12750.0000', (string) $rate->usd_uzs_rate);
    }
}
