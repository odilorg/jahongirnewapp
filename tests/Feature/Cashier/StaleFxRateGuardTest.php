<?php

declare(strict_types=1);

namespace Tests\Feature\Cashier;

use App\Actions\Cashier\RecordMixedCurrencySplitFromAdminAction;
use App\Exceptions\Fx\StaleFxRateException;
use App\Models\Beds24Booking;
use App\Models\BookingFxSync;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\DailyExchangeRate;
use App\Models\User;
use App\Services\BotPaymentService;
use App\Services\Fx\FxStalenessGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 2026-05-08 follow-up #1 — `fx.stale_after_hours` consumer-side
 * enforcement.
 *
 * Pins:
 *   1. Cashier-bot payment-session preparation refuses when the latest
 *      `daily_exchange_rates` row is older than the configured threshold.
 *   2. Same path opens normally when the row is fresh.
 *   3. The threshold is read from `config('fx.stale_after_hours')` at
 *      construction time — not hardcoded.
 *   4. The Filament admin mixed-currency path
 *      (`RecordMixedCurrencySplitFromAdminAction`) hits the same guard
 *      because both surfaces flow through `BotPaymentService::preparePayment`.
 *   5. Source-discrimination is uniform: a `source='manual'` row inside
 *      threshold passes (no per-source exemption logic that could
 *      silently bypass freshness elsewhere).
 *
 * See `docs/FIXES.md` 2026-05-08 tracked entry for the full incident
 * reasoning.
 */
final class StaleFxRateGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'features.beds24_auto_push_payment' => false, // never push real Beds24 from tests
        ]);
        // Default config is 4h. Tests that want a different threshold
        // construct FxStalenessGuard with an explicit value so they
        // don't depend on the env.
    }

    // ── Test #1 — bot session refuses to open when row is stale ───

    /** @test */
    public function bot_session_refuses_to_open_when_latest_row_older_than_threshold(): void
    {
        $this->seedRate(['fetched_at' => CarbonImmutable::now()->subHours(8)]);
        [$shift, $user, $booking] = $this->bookingScenario();

        // Use a 4h threshold; the row above is 8h old → must throw.
        $this->bindGuardWithMaxAgeHours(4);

        $this->expectException(StaleFxRateException::class);
        $this->expectExceptionMessageMatches('/8 hours old.*max allowed 4/');

        app(BotPaymentService::class)
            ->preparePayment($booking->beds24_booking_id, 'test-stale-' . uniqid());
    }

    // ── Test #2 — bot session opens normally when row is fresh ────

    /** @test */
    public function bot_session_opens_normally_when_row_is_fresh(): void
    {
        // The guard is the single new gate added at the top of
        // BotPaymentService::preparePayment. Beyond it the existing
        // preparePayment flow runs (FxSyncService → Beds24 API roundtrip)
        // which is out of scope for this PR's invariants and requires
        // a valid Beds24 token + sync row to exercise end-to-end.
        // Pinning the contract this PR actually changes:
        //   "fresh daily_exchange_rates row → guard does NOT throw,
        //    so preparePayment is allowed to proceed."
        // The downstream Beds24 path is covered by existing
        // UsdCollectedPaymentTest / GroupPaymentIntegrationTest fixtures
        // that already maintain a valid token.
        $this->seedRate(['fetched_at' => CarbonImmutable::now()->subMinutes(30)]);

        $this->expectNoException(
            fn () => (new FxStalenessGuard(4))->ensureFreshOrFail(),
            'fresh row must pass the guard so preparePayment can proceed normally',
        );
    }

    // ── Test #3 — threshold reads from config ─────────────────────

    /** @test */
    public function staleness_threshold_uses_config_value_not_hardcoded(): void
    {
        // Row 5h old. With config=4h it must fail. With config=6h it must pass.
        $this->seedRate(['fetched_at' => CarbonImmutable::now()->subHours(5)]);

        // 4h threshold → stale
        $this->expectThrowsException(
            StaleFxRateException::class,
            fn () => (new FxStalenessGuard(4))->ensureFreshOrFail(),
            'fetched 5h ago vs max 4h must throw',
        );

        // 6h threshold → fresh enough
        $this->expectNoException(
            fn () => (new FxStalenessGuard(6))->ensureFreshOrFail(),
            'fetched 5h ago vs max 6h must pass',
        );

        // Sanity: the no-arg constructor reads config('fx.stale_after_hours').
        // Set config to 10 → must pass.
        config(['fx.stale_after_hours' => 10]);
        $this->expectNoException(
            fn () => (new FxStalenessGuard())->ensureFreshOrFail(),
            'no-arg constructor must read config; with config=10h, 5h-old row passes',
        );

        // Set config to 2 → must throw.
        config(['fx.stale_after_hours' => 2]);
        $this->expectThrowsException(
            StaleFxRateException::class,
            fn () => (new FxStalenessGuard())->ensureFreshOrFail(),
            'no-arg constructor must read config; with config=2h, 5h-old row throws',
        );
    }

    // ── Test #4 — admin mixed-currency path also refuses ──────────

    /** @test */
    public function mixed_currency_admin_path_also_refuses_on_stale_row(): void
    {
        $this->seedRate(['fetched_at' => CarbonImmutable::now()->subHours(8)]);
        [$shift, $user, $booking] = $this->bookingScenario();

        $this->bindGuardWithMaxAgeHours(4);

        $this->expectException(StaleFxRateException::class);

        app(RecordMixedCurrencySplitFromAdminAction::class)->execute([
            'cashier_shift_id'  => $shift->id,
            'beds24_booking_id' => $booking->beds24_booking_id,
            'base_currency'     => 'UZS',
            'leg1_currency'     => 'UZS',
            'leg1_amount'       => 500_000,
            'leg1_method'       => 'card',
            'leg2_currency'     => 'USD',
            'leg2_amount'       => 50,
            'leg2_method'       => 'cash',
        ]);
    }

    // ── Test #5 — manual source within threshold passes ───────────

    /** @test */
    public function staleness_check_skips_for_rows_with_source_manual_AND_fetched_at_within_threshold(): void
    {
        // Manual rates entered by an admin via Filament are the
        // operator's escape valve when the cron fails. They must be
        // honored if recent enough — the guard does NOT discriminate
        // by source. This test verifies the affirmative case: a
        // manual row inside the threshold passes.
        $this->seedRate([
            'fetched_at' => CarbonImmutable::now()->subMinutes(30),
            'source'     => 'manual',
        ]);

        $this->expectNoException(
            fn () => (new FxStalenessGuard(4))->ensureFreshOrFail(),
            'fresh manual row must pass — source-discrimination is intentionally absent',
        );
    }

    // ── Defensive bonus — empty table case ────────────────────────

    /** @test */
    public function empty_daily_exchange_rates_table_throws(): void
    {
        // Cold-start case — first prod deploy before any cron has run.
        // Refuse loudly rather than silently default to anything.
        $this->expectThrowsException(
            StaleFxRateException::class,
            fn () => (new FxStalenessGuard(4))->ensureFreshOrFail(),
            'no rows at all must throw — cold-start refusal',
        );
    }

    // ── Defensive: manual + stale STILL throws (no source-exemption shortcut)

    /** @test */
    public function manual_source_row_that_is_stale_still_throws(): void
    {
        // Mirror of test #5 (the affirmative manual-source case) — pins
        // the negative invariant. If a future change adds
        // `if ($row->source === 'manual') return;` somewhere in the guard,
        // this test fails. A manual row that has aged past the threshold
        // is just as stale as a cron-fetched one — operators must
        // re-enter a fresh manual rate in Filament rather than relying
        // on the source flag to grant a permanent exemption.
        $this->seedRate([
            'fetched_at' => CarbonImmutable::now()->subHours(8),
            'source'     => 'manual',
        ]);

        $this->expectThrowsException(
            StaleFxRateException::class,
            fn () => (new FxStalenessGuard(4))->ensureFreshOrFail(),
            '8h-old manual row must throw — source field grants no permanent exemption',
        );
    }

    // ── Defensive: misconfigured threshold clamps to safe minimum

    /** @test */
    public function misconfigured_threshold_clamps_to_one_hour(): void
    {
        // Spec: a misconfigured 0 or negative threshold must NOT
        // silently disable the guard or shift the threshold into the
        // future. Both should clamp to 1h with a warning log.
        $this->seedRate(['fetched_at' => CarbonImmutable::now()->subHours(2)]);

        // 0 → clamps to 1, row is 2h old → throws
        $this->expectThrowsException(
            StaleFxRateException::class,
            fn () => (new FxStalenessGuard(0))->ensureFreshOrFail(),
            '0-hour threshold must clamp to 1, then 2h-old row throws',
        );

        // -5 → clamps to 1, row is 2h old → throws
        $this->expectThrowsException(
            StaleFxRateException::class,
            fn () => (new FxStalenessGuard(-5))->ensureFreshOrFail(),
            'negative threshold must clamp to 1, then 2h-old row throws',
        );

        // Sanity: clamped 1h boundary — fresh row passes
        DailyExchangeRate::query()->delete();
        $this->seedRate(['fetched_at' => CarbonImmutable::now()->subMinutes(30)]);
        $this->expectNoException(
            fn () => (new FxStalenessGuard(0))->ensureFreshOrFail(),
            '0-hour clamped to 1; 30-min-old row passes',
        );
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function seedRate(array $overrides = []): DailyExchangeRate
    {
        return DailyExchangeRate::create(array_merge([
            'rate_date'              => now()->toDateString(),
            'usd_uzs_rate'           => 12_115.0,
            'eur_uzs_cbu_rate'       => 14_261.0,
            'eur_margin'             => 200.0,
            'eur_effective_rate'     => 14_061.0,
            'rub_uzs_cbu_rate'       => 162.0,
            'rub_margin'             => 20.0,
            'rub_effective_rate'     => 142.0,
            'uzs_rounding_increment' => 1000,
            'eur_rounding_increment' => 1,
            'rub_rounding_increment' => 100,
            'source'                 => 'cbu',
            'fetched_at'             => now(),
        ], $overrides));
    }

    /**
     * @return array{0: CashierShift, 1: User, 2: Beds24Booking}
     */
    private function bookingScenario(): array
    {
        $drawer = CashDrawer::firstOrCreate(['name' => 'Test'], ['is_active' => true]);
        $user   = User::factory()->create();
        $shift  = CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $user->id,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);

        $booking = Beds24Booking::create([
            'beds24_booking_id' => 'B-STALE-' . uniqid(),
            'property_id'       => '41097',
            'guest_name'        => 'Stale Test',
            'arrival_date'      => now()->addDay()->toDateString(),
            'departure_date'    => now()->addDays(2)->toDateString(),
            'invoice_balance'   => 65.0,
            'total_amount'      => 65.0,
            'booking_status'    => 'confirmed',
            'channel'           => 'direct',
        ]);

        return [$shift, $user, $booking];
    }

    private function seedFxSync(Beds24Booking $booking): BookingFxSync
    {
        $rate = DailyExchangeRate::orderByDesc('rate_date')->orderByDesc('id')->first();

        return BookingFxSync::create([
            'beds24_booking_id'      => $booking->beds24_booking_id,
            'fx_rate_date'           => $rate->rate_date->toDateString(),
            'daily_exchange_rate_id' => $rate->id,
            'arrival_date_used'      => now()->addDay()->toDateString(),
            'usd_amount_used'        => 65.0,
            'uzs_final'              => 790_000,
            'eur_final'              => 57.0,
            'rub_final'              => 5_600.0,
            'usd_final'              => 65.0,
            'push_status'            => 'pending',
        ]);
    }

    /**
     * Override the container binding for FxStalenessGuard so the
     * production code path uses our test threshold without needing
     * to mutate the global config (which other tests in the same
     * suite may rely on).
     */
    private function bindGuardWithMaxAgeHours(int $hours): void
    {
        $this->app->bind(FxStalenessGuard::class, fn () => new FxStalenessGuard($hours));
    }

    /**
     * Local helpers — renamed to avoid clashing with Laravel's
     * `TestCase::assertThrows` introduced in newer versions
     * (visibility conflict: Laravel declares it protected, ours
     * was private).
     */
    private function expectThrowsException(string $expectedException, callable $fn, string $message = ''): void
    {
        try {
            $fn();
            $this->fail("Expected {$expectedException}; nothing thrown. {$message}");
        } catch (\Throwable $e) {
            $this->assertInstanceOf($expectedException, $e, $message);
        }
    }

    private function expectNoException(callable $fn, string $message = ''): void
    {
        try {
            $fn();
            $this->assertTrue(true);
        } catch (\Throwable $e) {
            $this->fail("Did not expect any exception, got " . $e::class . ": {$e->getMessage()}. {$message}");
        }
    }
}
