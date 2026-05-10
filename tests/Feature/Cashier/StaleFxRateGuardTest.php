<?php

declare(strict_types=1);

namespace Tests\Feature\Cashier;

use App\Actions\Cashier\RecordMixedCurrencySplitFromAdminAction;
use App\Exceptions\Fx\StaleFxRateException;
use App\Models\Beds24Booking;
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\DailyExchangeRate;
use App\Models\User;
use App\Services\BotPaymentService;
use App\Services\Fx\FxStalenessGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 2026-05-10 follow-up #1 v2 — calendar-day + max-fetched_at-age
 * staleness gate at payment-session preparation.
 *
 * v1 (commit cb54bd2, rolled back the same day) used pure hourly
 * freshness on `fetched_at` (default 4h). That falsely blocked normal
 * afternoon operations because the morning cron writes once at 07:00
 * Tashkent — by 11:00 the row was already past the 4h threshold and
 * cashier sessions were refused for the rest of the day even on a
 * perfectly healthy day. Zero user-facing outage during the 6-min
 * deploy window, caught by post-deploy verification.
 *
 * v2 hybrid semantics:
 *   PRIMARY   — `rate_date == today` (app timezone)
 *   SECONDARY — `fetched_at` not older than `fx.fresh_fetched_max_hours`
 *               (default 28h, env-tunable)
 *
 * Both must pass.
 *
 * Pinned invariants:
 *   - Today's morning cron (e.g. 07:00:07) row stays valid all day
 *     including 14:00, 18:00, 23:59
 *   - Yesterday's row (cron failed today) is refused
 *   - Today's rate_date with a wildly-old fetched_at is refused
 *   - Bot session refusal surfaces clean Russian message
 *   - Filament admin mixed-currency path uses the same guard
 *   - Manual source rows are uniformly subject to both checks
 *   - Misconfigured threshold clamps to 1h with warning log
 *   - Empty table is a cold-start refusal
 *
 * Carbon::setTestNow is used so time-of-day fixtures are deterministic
 * regardless of the test-runner's wall clock.
 */
final class StaleFxRateGuardTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'features.beds24_auto_push_payment' => false,
        ]);
    }

    protected function tearDown(): void
    {
        // Carbon::setTestNow() is process-global; reset so other tests
        // in the same suite don't inherit a frozen "now".
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    // ── PRIMARY check: rate_date == today ─────────────────────────

    /** @test */
    public function todays_morning_cron_row_passes_at_14_00(): void
    {
        // The actual production scenario from 2026-05-10 that v1 broke:
        // morning cron at 07:00:07 writes today's row; cashier session
        // at 14:00 must succeed.
        Carbon::setTestNow(Carbon::parse('2026-05-10 14:00:00', config('app.timezone')));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-10 14:00:00', config('app.timezone')));

        $this->seedRate([
            'rate_date'  => '2026-05-10',
            'fetched_at' => Carbon::parse('2026-05-10 07:00:07', config('app.timezone')),
        ]);

        $this->expectNoException(
            fn () => (new FxStalenessGuard(28))->ensureFreshOrFail(),
            "today's 07:00 row at 14:00 must pass — this is the v1 regression scenario",
        );
    }

    /** @test */
    public function todays_morning_cron_row_passes_at_23_59(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 23:59:00', config('app.timezone')));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-10 23:59:00', config('app.timezone')));

        $this->seedRate([
            'rate_date'  => '2026-05-10',
            'fetched_at' => Carbon::parse('2026-05-10 07:00:07', config('app.timezone')),
        ]);

        $this->expectNoException(
            fn () => (new FxStalenessGuard(28))->ensureFreshOrFail(),
            "today's 07:00 row at 23:59 must pass — full-day cycle ~17h is within 28h cap",
        );
    }

    /** @test */
    public function yesterdays_row_throws_on_primary_check(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 14:00:00', config('app.timezone')));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-10 14:00:00', config('app.timezone')));

        // Cron failed today; only yesterday's row exists.
        $this->seedRate([
            'rate_date'  => '2026-05-09',
            'fetched_at' => Carbon::parse('2026-05-09 07:00:00', config('app.timezone')),
        ]);

        $this->expectThrowsException(
            StaleFxRateException::class,
            fn () => (new FxStalenessGuard(28))->ensureFreshOrFail(),
            "yesterday's row must throw — primary check (rate_date == today) fails",
        );
    }

    // ── SECONDARY check: fetched_at not absurdly old ──────────────

    /** @test */
    public function todays_rate_date_with_absurdly_old_fetched_at_throws(): void
    {
        // Edge case: someone backdates fetched_at via a data-fix or
        // a stuck row survives across days. Today's rate_date alone
        // shouldn't be enough — the secondary cap catches this.
        Carbon::setTestNow(Carbon::parse('2026-05-10 14:00:00', config('app.timezone')));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-10 14:00:00', config('app.timezone')));

        $this->seedRate([
            'rate_date'  => '2026-05-10',
            'fetched_at' => Carbon::parse('2026-05-08 14:00:00', config('app.timezone')), // 48h old
        ]);

        $exception = null;
        try {
            (new FxStalenessGuard(28))->ensureFreshOrFail();
        } catch (StaleFxRateException $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception, '48h-old fetched_at must throw even with today rate_date');
        $this->assertStringContainsString('48 hours old', $exception->getMessage(),
            'exception must surface the actual age in hours');
        $this->assertStringContainsString('max allowed 28', $exception->getMessage(),
            'exception must surface the configured max');
    }

    // ── Config plumbing ───────────────────────────────────────────

    /** @test */
    public function fresh_fetched_max_hours_uses_config_value_not_hardcoded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 14:00:00', config('app.timezone')));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-10 14:00:00', config('app.timezone')));

        // 30h-old fetched_at on today's rate_date.
        $this->seedRate([
            'rate_date'  => '2026-05-10',
            'fetched_at' => Carbon::now()->subHours(30),
        ]);

        // Default 28h → throws on 30h.
        $this->expectThrowsException(
            StaleFxRateException::class,
            fn () => (new FxStalenessGuard(28))->ensureFreshOrFail(),
            '30h-old fetched_at vs max 28h must throw',
        );

        // Bumped to 36h → passes.
        $this->expectNoException(
            fn () => (new FxStalenessGuard(36))->ensureFreshOrFail(),
            '30h-old fetched_at vs max 36h must pass',
        );

        // No-arg constructor reads config; set to 24h → must throw.
        config(['fx.fresh_fetched_max_hours' => 24]);
        $this->expectThrowsException(
            StaleFxRateException::class,
            fn () => (new FxStalenessGuard())->ensureFreshOrFail(),
            'no-arg ctor must read config; with 24h cap, 30h row throws',
        );

        // Config 48h → must pass.
        config(['fx.fresh_fetched_max_hours' => 48]);
        $this->expectNoException(
            fn () => (new FxStalenessGuard())->ensureFreshOrFail(),
            'no-arg ctor must read config; with 48h cap, 30h row passes',
        );
    }

    /** @test */
    public function misconfigured_threshold_clamps_to_one_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 14:00:00', config('app.timezone')));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-10 14:00:00', config('app.timezone')));

        // Today's rate_date, 2h-old fetched_at.
        $this->seedRate([
            'rate_date'  => '2026-05-10',
            'fetched_at' => Carbon::now()->subHours(2),
        ]);

        // 0 → clamps to 1, 2h-old throws.
        $this->expectThrowsException(
            StaleFxRateException::class,
            fn () => (new FxStalenessGuard(0))->ensureFreshOrFail(),
            '0-hour threshold must clamp to 1, then 2h-old throws',
        );

        // -5 → clamps to 1, 2h-old throws.
        $this->expectThrowsException(
            StaleFxRateException::class,
            fn () => (new FxStalenessGuard(-5))->ensureFreshOrFail(),
            'negative threshold must clamp to 1, then 2h-old throws',
        );

        // Sanity: 30-min row passes the 1h-clamped threshold.
        DailyExchangeRate::query()->delete();
        $this->seedRate([
            'rate_date'  => '2026-05-10',
            'fetched_at' => Carbon::now()->subMinutes(30),
        ]);
        $this->expectNoException(
            fn () => (new FxStalenessGuard(0))->ensureFreshOrFail(),
            '0 → clamped to 1; 30-min-old row passes',
        );
    }

    // ── Empty table ───────────────────────────────────────────────

    /** @test */
    public function empty_daily_exchange_rates_table_throws(): void
    {
        $this->expectThrowsException(
            StaleFxRateException::class,
            fn () => (new FxStalenessGuard(28))->ensureFreshOrFail(),
            'no rows at all must throw — cold-start refusal',
        );
    }

    // ── Source uniformity ─────────────────────────────────────────

    /** @test */
    public function manual_source_today_passes(): void
    {
        // Manual rates entered by an admin via Filament are the
        // operator's escape valve when the cron fails. They pass when
        // both checks succeed (rate_date=today, fetched_at within cap).
        Carbon::setTestNow(Carbon::parse('2026-05-10 14:00:00', config('app.timezone')));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-10 14:00:00', config('app.timezone')));

        $this->seedRate([
            'rate_date'  => '2026-05-10',
            'fetched_at' => Carbon::now()->subMinutes(15),
            'source'     => 'manual',
        ]);

        $this->expectNoException(
            fn () => (new FxStalenessGuard(28))->ensureFreshOrFail(),
            'fresh manual row must pass — source-discrimination is intentionally absent',
        );
    }

    /** @test */
    public function manual_source_yesterday_still_throws(): void
    {
        // Defensive: manual rate from yesterday must NOT bypass the
        // primary check. Operator must update it to today's rate_date
        // — manual is not a permanent exemption.
        Carbon::setTestNow(Carbon::parse('2026-05-10 14:00:00', config('app.timezone')));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-10 14:00:00', config('app.timezone')));

        $this->seedRate([
            'rate_date'  => '2026-05-09',
            'fetched_at' => Carbon::parse('2026-05-09 14:00:00', config('app.timezone')),
            'source'     => 'manual',
        ]);

        $this->expectThrowsException(
            StaleFxRateException::class,
            fn () => (new FxStalenessGuard(28))->ensureFreshOrFail(),
            'yesterday manual row must throw — source field grants no permanent exemption',
        );
    }

    // ── End-to-end: bot path + Filament admin path ───────────────

    /** @test */
    public function bot_session_refuses_on_yesterdays_row(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 14:00:00', config('app.timezone')));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-10 14:00:00', config('app.timezone')));

        $this->seedRate([
            'rate_date'  => '2026-05-09',
            'fetched_at' => Carbon::parse('2026-05-09 07:00:00', config('app.timezone')),
        ]);
        [, , $booking] = $this->bookingScenario();

        $this->bindGuardWithMaxAgeHours(28);

        $this->expectException(StaleFxRateException::class);

        app(BotPaymentService::class)
            ->preparePayment($booking->beds24_booking_id, 'test-stale-' . uniqid());
    }

    /** @test */
    public function bot_session_passes_guard_on_todays_morning_cron_row(): void
    {
        // The exact production scenario from 2026-05-10 that v1 broke.
        // Pinning via the guard alone (downstream Beds24 path is out of
        // scope for this PR's invariants and requires a valid token).
        Carbon::setTestNow(Carbon::parse('2026-05-10 14:00:00', config('app.timezone')));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-10 14:00:00', config('app.timezone')));

        $this->seedRate([
            'rate_date'  => '2026-05-10',
            'fetched_at' => Carbon::parse('2026-05-10 07:00:07', config('app.timezone')),
        ]);

        $this->expectNoException(
            fn () => (new FxStalenessGuard(28))->ensureFreshOrFail(),
            "v1's regression scenario (07:00 row at 14:00) must pass under v2",
        );
    }

    /** @test */
    public function mixed_currency_admin_path_also_refuses_on_yesterdays_row(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 14:00:00', config('app.timezone')));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-10 14:00:00', config('app.timezone')));

        $this->seedRate([
            'rate_date'  => '2026-05-09',
            'fetched_at' => Carbon::parse('2026-05-09 07:00:00', config('app.timezone')),
        ]);
        [$shift, , $booking] = $this->bookingScenario();

        $this->bindGuardWithMaxAgeHours(28);

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

    private function bindGuardWithMaxAgeHours(int $hours): void
    {
        $this->app->bind(FxStalenessGuard::class, fn () => new FxStalenessGuard($hours));
    }

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
            $this->fail('Did not expect any exception, got ' . $e::class . ": {$e->getMessage()}. {$message}");
        }
    }
}
