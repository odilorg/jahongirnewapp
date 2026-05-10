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
        $this->seedRate([
            'rate_date' => Carbon::today()->toDateString(),
            'fetched_at' => Carbon::now()->subHours(7),
        ]);

        $guard = app(FxStalenessGuard::class);

        $this->assertTrue($guard->isFresh());
        $rate = $guard->getFreshOrNull();
        $this->assertNotNull($rate);
        $this->assertSame('12115.0000', (string) $rate->usd_uzs_rate);
    }

    /** @test */
    public function yesterdays_row_fails_both_companions(): void
    {
        $this->seedRate([
            'rate_date' => Carbon::yesterday()->toDateString(),
            'fetched_at' => Carbon::now()->subHours(7),
        ]);

        $guard = app(FxStalenessGuard::class);

        $this->assertFalse($guard->isFresh());
        $this->assertNull($guard->getFreshOrNull());
    }

    /** @test */
    public function todays_row_with_absurd_fetched_at_fails_both_companions(): void
    {
        $this->seedRate([
            'rate_date' => Carbon::today()->toDateString(),
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

    // The "two rows on the same rate_date" scenario hypothesised by the
    // throwing-path's `orderByDesc('rate_date')->orderByDesc('id')`
    // tie-breaker comment cannot actually occur: the
    // `daily_exchange_rates_rate_date_unique` index makes one row per
    // date the only legal shape. Both the morning cron and the Filament
    // admin manual-override path go through `updateOrCreate(['rate_date'])`
    // so the unique key is respected. No test added for that hypothetical.

    /**
     * Mirror of `StaleFxRateGuardTest::seedRate` so this test exercises
     * the same realistic row shape that the throwing-path test uses —
     * any drift between the two test files would make the
     * single-source-of-truth invariant unprovable.
     */
    private function seedRate(array $overrides = []): DailyExchangeRate
    {
        return DailyExchangeRate::create(array_merge([
            'rate_date' => Carbon::today()->toDateString(),
            'usd_uzs_rate' => 12_115.0,
            'eur_uzs_cbu_rate' => 14_261.0,
            'eur_margin' => 200.0,
            'eur_effective_rate' => 14_061.0,
            'rub_uzs_cbu_rate' => 162.0,
            'rub_margin' => 20.0,
            'rub_effective_rate' => 142.0,
            'uzs_rounding_increment' => 1000,
            'eur_rounding_increment' => 1,
            'rub_rounding_increment' => 100,
            'source' => 'cbu',
            'fetched_at' => Carbon::now(),
        ], $overrides));
    }
}
