<?php

namespace Tests\Feature\Fx;

use App\Enums\FxSyncPushStatus;
use App\Models\Beds24Booking;
use App\Models\BookingFxSync;
use App\Models\DailyExchangeRate;
use App\Services\Beds24BookingService;
use App\Services\BookingPaymentOptionsService;
use App\Services\FxSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Covers FxSyncService rate-resilience behaviour added during Phase 2 hardening.
 *
 * Four scenarios:
 *   (1) Happy path — today's rate present, no warning, sync row upserted
 *   (2) Today's rate missing, fallback to latest — warning logged, sync succeeds
 *   (3) No rates at all — RuntimeException thrown, error logged
 *   (4) isStale() returns expected values for various sync states
 */
class FxSyncServiceRateResilienceTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Minimal valid DailyExchangeRate row. Uses updateOrCreate to survive pre-existing rows. */
    private function makeRate(string $date): DailyExchangeRate
    {
        return DailyExchangeRate::updateOrCreate(
            ['rate_date' => $date],
            [
                'usd_uzs_rate'           => 12800.0,
                'eur_uzs_cbu_rate'       => 14000.0,
                'eur_margin'             => 200,
                'eur_effective_rate'     => 13800.0,
                'rub_uzs_cbu_rate'       => 145.0,
                'rub_margin'             => 20,
                'rub_effective_rate'     => 125.0,
                'uzs_rounding_increment' => 10000,
                'eur_rounding_increment' => 1,
                'rub_rounding_increment' => 100,
                'source'                 => 'cbu',
                'fetched_at'             => now(),
            ]
        );
    }

    /** Minimal confirmed booking arriving tomorrow. */
    private function makeBooking(): Beds24Booking
    {
        return Beds24Booking::factory()->create([
            'booking_status' => 'confirmed',
            'total_amount'   => 100.00,
            'invoice_balance' => 0,
            'arrival_date'   => today()->addDay()->toDateString(),
        ]);
    }

    /**
     * Build a real FxSyncService with the calculation and Beds24 push
     * side-effects mocked out so tests don't need live API keys.
     */
    private function makeService(): FxSyncService
    {
        // calcService: returns a predictable options array
        $calc = $this->createMock(BookingPaymentOptionsService::class);
        $calc->method('calculate')->willReturn([
            'usd_amount' => 100.00,
            'uzs_final'  => 1_280_000,
            'eur_final'  => 7.25,
            'rub_final'  => 800.0,
        ]);
        $calc->method('formatForBeds24')->willReturn([
            'UZS_AMOUNT' => '1 280 000',
            'EUR_AMOUNT' => '7.25',
            'RUB_AMOUNT' => '800',
        ]);

        // beds24Service: swallow the push call (void return type — no willReturn needed)
        $beds24 = $this->createMock(Beds24BookingService::class);
        $beds24->method('writePaymentOptionsToInfoItems')->willReturnCallback(fn () => null);

        return new FxSyncService($calc, $beds24);
    }

    // ── (1) Happy path ────────────────────────────────────────────────────────

    /** @test */
    public function push_now_uses_todays_rate_when_available(): void
    {
        $this->makeRate(today()->toDateString());
        $booking = $this->makeBooking();
        $service = $this->makeService();

        Log::shouldReceive('warning')->never();

        $sync = $service->pushNow($booking, 'manual');

        $this->assertInstanceOf(BookingFxSync::class, $sync);
        $this->assertEquals(FxSyncPushStatus::Pushed, $sync->push_status);
        $this->assertEquals(today()->toDateString(), $sync->fx_rate_date->toDateString());
    }

    /** @test */
    public function push_now_upserts_sync_row_with_all_required_fields(): void
    {
        $this->makeRate(today()->toDateString());
        $booking = $this->makeBooking();

        $sync = $this->makeService()->pushNow($booking, 'manual');

        $this->assertNotNull($sync->usd_final);
        $this->assertNotNull($sync->uzs_final);
        $this->assertNotNull($sync->eur_final);
        $this->assertNotNull($sync->rub_final);
        $this->assertNotNull($sync->fx_last_pushed_at);
        $this->assertEquals('manual', $sync->last_source_trigger->value ?? $sync->last_source_trigger);
    }

    // ── (2) Today's rate missing — fallback to latest ─────────────────────────

    /** @test */
    public function push_now_falls_back_to_latest_rate_when_today_is_missing(): void
    {
        // Remove any existing rates (including pre-existing today's row) then seed yesterday only
        DailyExchangeRate::query()->delete();
        $yesterday = today()->subDay()->toDateString();
        $this->makeRate($yesterday);

        $booking = $this->makeBooking();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($yesterday) {
                return str_contains($message, 'today\'s rate missing')
                    && ($context['rate_date_used'] ?? '') === $yesterday;
            });

        $sync = $this->makeService()->pushNow($booking, 'manual');

        $this->assertInstanceOf(BookingFxSync::class, $sync);
        $this->assertEquals(FxSyncPushStatus::Pushed, $sync->push_status);
        $this->assertEquals($yesterday, $sync->fx_rate_date->toDateString());
    }

    /** @test */
    public function push_now_falls_back_to_oldest_available_rate_not_just_yesterday(): void
    {
        // Remove any existing rates then seed only a 3-day-old row
        DailyExchangeRate::query()->delete();
        $threeDaysAgo = today()->subDays(3)->toDateString();
        $this->makeRate($threeDaysAgo);

        $booking = $this->makeBooking();

        Log::shouldReceive('warning')->once();

        $sync = $this->makeService()->pushNow($booking, 'manual');

        $this->assertEquals($threeDaysAgo, $sync->fx_rate_date->toDateString());
    }

    // ── (3) No rates at all ───────────────────────────────────────────────────

    /** @test */
    public function push_now_throws_runtime_exception_when_no_rates_exist(): void
    {
        // Delete any pre-existing rates so the table is truly empty for this test
        DailyExchangeRate::query()->delete();

        $booking = $this->makeBooking();

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message) {
                return str_contains($message, 'no DailyExchangeRate exists');
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No DailyExchangeRate available/');

        $this->makeService()->pushNow($booking, 'manual');
    }

    /** @test */
    public function push_now_does_not_create_partial_sync_row_when_no_rates_exist(): void
    {
        DailyExchangeRate::query()->delete();

        $booking = $this->makeBooking();

        Log::shouldReceive('error')->once();

        try {
            $this->makeService()->pushNow($booking, 'manual');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertDatabaseMissing('booking_fx_syncs', [
            'beds24_booking_id' => (string) $booking->beds24_booking_id,
        ]);
    }

    // ── (4) isStale() ─────────────────────────────────────────────────────────

    /** @test */
    public function is_stale_returns_true_when_push_status_is_not_pushed(): void
    {
        $booking = $this->makeBooking();

        foreach ([FxSyncPushStatus::Pending, FxSyncPushStatus::Failed] as $status) {
            $sync = new BookingFxSync([
                'push_status'  => $status,
                'fx_rate_date' => today()->toDateString(),
            ]);
            $this->assertTrue($sync->isStale($booking), "Expected stale for status {$status->value}");
        }
    }

    /** @test */
    public function is_stale_returns_true_when_fx_rate_date_is_null(): void
    {
        $booking = $this->makeBooking();

        $sync = new BookingFxSync([
            'push_status'  => FxSyncPushStatus::Pushed,
            'fx_rate_date' => null,
        ]);

        $this->assertTrue($sync->isStale($booking));
    }

    /** @test */
    public function is_stale_returns_true_when_rate_date_is_older_than_today(): void
    {
        $booking = $this->makeBooking();

        $sync = new BookingFxSync([
            'push_status'  => FxSyncPushStatus::Pushed,
            'fx_rate_date' => today()->subDay()->toDateString(),
        ]);

        $this->assertTrue($sync->isStale($booking));
    }

    /** @test */
    public function is_stale_returns_false_when_already_pushed_today(): void
    {
        $booking = $this->makeBooking();

        $sync = new BookingFxSync([
            'push_status'  => FxSyncPushStatus::Pushed,
            'fx_rate_date' => today()->toDateString(),
        ]);

        $this->assertFalse($sync->isStale($booking));
    }

    // ── (5) markFailed() ─────────────────────────────────────────────────────

    /** @test */
    public function mark_failed_updates_existing_sync_row(): void
    {
        $booking = $this->makeBooking();
        $this->makeRate(today()->toDateString());

        // Create a real sync row first
        $sync = $this->makeService()->pushNow($booking, 'manual');
        $this->assertEquals(FxSyncPushStatus::Pushed, $sync->push_status);

        $service = $this->makeService();
        $service->markFailed((string) $booking->beds24_booking_id, 'test error');

        $sync->refresh();
        $this->assertEquals(FxSyncPushStatus::Failed, $sync->push_status);
        $this->assertEquals('test error', $sync->last_push_error);
    }

    /** @test */
    public function mark_failed_does_not_insert_partial_row_when_no_sync_row_exists(): void
    {
        $booking = $this->makeBooking();

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $m) => str_contains($m, 'markFailed skipped'));

        $this->makeService()->markFailed((string) $booking->beds24_booking_id, 'test error');

        $this->assertDatabaseMissing('booking_fx_syncs', [
            'beds24_booking_id' => (string) $booking->beds24_booking_id,
        ]);
    }
}
