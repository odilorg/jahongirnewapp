<?php

namespace App\Services;

use App\Enums\FxSyncPushStatus;
use App\Exceptions\Beds24RateLimitException;
use App\Models\Beds24Booking;
use App\Models\BookingFxSync;
use App\Models\DailyExchangeRate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single service responsible for keeping booking_fx_syncs up to date.
 *
 * Two public methods:
 *
 *   ensureFresh()  — checks staleness, refreshes if needed, returns sync row.
 *                    Used by print flow and cashier bot. Holds a per-booking lock
 *                    so concurrent calls (print + bot, two print clicks) don't
 *                    double-push to Beds24.
 *
 *   pushNow()      — unconditionally recalculates and pushes. Used by the scheduled
 *                    job and force-refresh admin action. Does not hold a lock.
 *
 * Rate limiting: Beds24RateLimitException (HTTP 429) is re-thrown so FxSyncJob's
 * backoff() can handle the retry delay. No sleeps here.
 */
class FxSyncService
{
    public function __construct(
        private readonly BookingPaymentOptionsService $calcService,
        private readonly Beds24BookingService         $beds24Service,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Return a fresh BookingFxSync for the given booking.
     * Pushes to Beds24 only when staleness rules say it is needed.
     * Holds a 30-second per-booking lock to prevent concurrent pushes.
     *
     * @throws Beds24RateLimitException  — caller (FxSyncJob) handles backoff
     * @throws \Throwable
     */
    public function ensureFresh(Beds24Booking $booking, string $trigger = 'manual'): BookingFxSync
    {
        $sync = BookingFxSync::firstOrNew(
            ['beds24_booking_id' => (string) $booking->beds24_booking_id]
        );

        if (! $sync->exists || ! $sync->isStale($booking)) {
            return $sync->exists ? $sync : $this->pushNow($booking, $trigger);
        }

        // Acquire per-booking lock — prevents print + bot or two print clicks racing
        $lock = Cache::lock("fx-sync-booking:{$booking->beds24_booking_id}", 30);

        return $lock->block(10, function () use ($booking, $sync, $trigger): BookingFxSync {
            // Re-check inside lock — another process may have just refreshed
            $sync->refresh();
            if ($sync->exists && ! $sync->isStale($booking)) {
                return $sync;
            }
            return $this->pushNow($booking, $trigger);
        });
    }

    /**
     * Unconditionally recalculate and push, regardless of staleness.
     * Used by:
     *   - FxSyncJob (queued, handles rate-limit backoff)
     *   - CalculateAndPushDailyPaymentOptions artisan command
     *   - Admin force-refresh endpoint
     *   - BotPaymentService::preparePayment() when group-aware amount differs from stored
     *
     * @param  float|null $usdAmountOverride  When set, use this USD total instead of
     *                                         booking->effectiveUsdAmount(). Used by the cashier
     *                                         bot for group bookings (sum of all siblings).
     *                                         All other callers pass null (per-room behavior).
     * @throws Beds24RateLimitException  — re-thrown so FxSyncJob's backoff() fires
     */
    public function pushNow(Beds24Booking $booking, string $trigger = 'manual', ?float $usdAmountOverride = null): BookingFxSync
    {
        $rate = DailyExchangeRate::where('rate_date', today())->first();

        if ($rate === null) {
            $rate = DailyExchangeRate::orderByDesc('rate_date')->first();

            if ($rate === null) {
                Log::error('FxSyncService: no DailyExchangeRate exists — FX sync aborted', [
                    'beds24_booking_id' => $booking->beds24_booking_id,
                    'trigger'           => $trigger,
                ]);
                throw new \RuntimeException('No DailyExchangeRate available. Run fx:push-payment-options to seed rates.');
            }

            Log::warning('FxSyncService: today\'s rate missing — using fallback', [
                'beds24_booking_id' => $booking->beds24_booking_id,
                'rate_date_used'    => $rate->rate_date->toDateString(),
                'trigger'           => $trigger,
            ]);
        }

        // Use caller-supplied override when present (cashier group path),
        // otherwise fall back to per-room amount (all other callers).
        $usdAmount = $usdAmountOverride ?? $booking->effectiveUsdAmount();
        $options   = $this->calcService->calculate($usdAmount, $rate);
        $infoItems = $this->calcService->formatForBeds24($options, $rate->rate_date->toDateString());

        // Push to Beds24 — throws Beds24RateLimitException on HTTP 429
        $this->beds24Service->writePaymentOptionsToInfoItems(
            (int) $booking->beds24_booking_id,
            $infoItems
        );

        // Upsert local record — done after successful push to stay consistent
        return BookingFxSync::updateOrCreate(
            ['beds24_booking_id' => (string) $booking->beds24_booking_id],
            [
                'fx_rate_date'           => $rate->rate_date,
                'daily_exchange_rate_id' => $rate->id,
                'usd_amount_used'        => $usdAmount,
                'arrival_date_used'      => $booking->arrival_date,
                'uzs_final'              => $options['uzs_final'],
                'eur_final'              => $options['eur_final'],
                'rub_final'              => $options['rub_final'],
                'usd_final'              => $options['usd_amount'],
                'push_status'            => 'pushed',
                'fx_last_pushed_at'      => now(),
                'last_push_error'        => null,
                'push_attempts'          => DB::raw('COALESCE(push_attempts, 0) + 1'),
                'last_source_trigger'    => $trigger,
                'infoitems_version'      => (int) config('fx.infoitems_version', 1),
            ]
        );
    }

    /**
     * Mark an existing sync record as failed. Called by FxSyncJob::failed().
     *
     * Uses UPDATE-only (not updateOrCreate) to avoid inserting a partial row
     * with NOT NULL columns missing (e.g. fx_rate_date, usd_final).
     * If no row exists yet the failure is logged — the record will be created
     * on the next successful pushNow().
     */
    public function markFailed(string $beds24BookingId, string $error): void
    {
        $updated = BookingFxSync::where('beds24_booking_id', $beds24BookingId)
            ->update([
                'push_status'     => FxSyncPushStatus::Failed->value,
                'last_push_error' => $error,
            ]);

        if ($updated === 0) {
            Log::warning('FxSyncService: markFailed skipped — no sync row exists yet', [
                'beds24_booking_id' => $beds24BookingId,
                'error'             => $error,
            ]);
        }
    }
}
