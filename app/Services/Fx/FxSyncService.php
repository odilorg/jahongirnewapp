<?php

namespace App\Services\Fx;

use App\DTOs\Fx\PaymentPresentation;
use App\Enums\Currency;
use App\Enums\FxSourceTrigger;
use App\Enums\FxSyncPushStatus;
use App\Models\BookingFxSync;
use App\Models\ExchangeRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the booking_fx_syncs upsert and PaymentPresentation construction.
 *
 * Advisory lock: we use pg_advisory_xact_lock(crc32(beds24_booking_id)) inside a
 * transaction to prevent two concurrent processes from double-inserting / double-
 * calculating for the same booking.
 *
 * The lock is transaction-scoped (xact_lock), so it releases automatically on
 * COMMIT or ROLLBACK — no manual cleanup needed.
 */
class FxSyncService
{
    /**
     * Return a valid (non-stale) FX snapshot for a booking.
     *
     * If a snapshot already exists for today's rate date, it is returned as-is.
     * If it is stale or absent, we recalculate from the current ExchangeRate rows
     * and upsert the booking_fx_syncs row.
     *
     * @param  string  $beds24BookingId
     * @param  float   $usdAmount        Booking total from Beds24
     * @param  Carbon  $arrivalDate
     * @param  string  $guestName
     * @param  string  $roomNumber
     * @param  FxSourceTrigger  $trigger  Why this snapshot is being refreshed
     */
    public function getOrRefresh(
        string          $beds24BookingId,
        float           $usdAmount,
        Carbon          $arrivalDate,
        string          $guestName,
        string          $roomNumber,
        FxSourceTrigger $trigger,
    ): PaymentPresentation {
        return DB::transaction(function () use (
            $beds24BookingId, $usdAmount, $arrivalDate,
            $guestName, $roomNumber, $trigger,
        ) {
            // Advisory lock: serialise concurrent refreshes for this booking
            $lockKey = abs(crc32($beds24BookingId));
            DB::statement("SELECT pg_advisory_xact_lock({$lockKey})");

            $existing = BookingFxSync::where('beds24_booking_id', $beds24BookingId)
                ->lockForUpdate()
                ->first();

            $todayRate = $this->fetchTodayRate();

            if ($existing && $this->isSnapshotFresh($existing, $todayRate)) {
                return $this->buildPresentation($existing, $guestName, $roomNumber);
            }

            // Recalculate
            [$uzs, $eur, $rub, $rateRow] = $this->calculateAmounts($usdAmount, $todayRate);

            $syncData = [
                'fx_rate_date'         => $todayRate->effective_date->toDateString(),
                'exchange_rate_id'     => $rateRow->id,
                'usd_amount_used'      => $usdAmount,
                'arrival_date_used'    => $arrivalDate->toDateString(),
                'uzs_final'            => $uzs,
                'eur_final'            => $eur,
                'rub_final'            => $rub,
                'usd_final'            => $usdAmount,
                'last_source_trigger'  => $trigger->value,
                // Reset push status so infoItems gets re-pushed with fresh amounts
                'push_status'          => FxSyncPushStatus::Pending->value,
                'push_attempts'        => 0,
                'last_push_error'      => null,
                'infoitems_version'    => config('fx.infoitems_version', 1),
            ];

            $sync = BookingFxSync::updateOrCreate(
                ['beds24_booking_id' => $beds24BookingId],
                $syncData,
            );

            return $this->buildPresentation($sync, $guestName, $roomNumber);
        });
    }

    /**
     * Stamp the printed_rate_date on the snapshot — called by PrintPreparationService
     * after a successful registration form render.
     */
    public function markPrinted(BookingFxSync $sync): void
    {
        $sync->update([
            'printed_rate_date'      => $sync->fx_rate_date->toDateString(),
            'last_print_prepared_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function fetchTodayRate(): ExchangeRate
    {
        // We use USD→UZS as the anchor rate row (covers all we need via cross-multiplication)
        $rate = ExchangeRate::active()
            ->effectiveOn(now())
            ->forCurrencyPair(Currency::USD, Currency::UZS)
            ->orderBy('effective_date', 'desc')
            ->first();

        if (! $rate) {
            throw new \RuntimeException('No active USD→UZS exchange rate found for today.');
        }

        return $rate;
    }

    private function isSnapshotFresh(BookingFxSync $sync, ExchangeRate $todayRate): bool
    {
        // Fresh if the snapshot's rate date matches today's published rate effective date
        return $sync->fx_rate_date !== null
            && $sync->fx_rate_date->isSameDay($todayRate->effective_date);
    }

    /**
     * Calculate UZS, EUR, RUB from USD amount using today's rates.
     *
     * @return array{int, float, float, ExchangeRate}  [uzs, eur, rub, anchorRateRow]
     */
    private function calculateAmounts(float $usdAmount, ExchangeRate $anchorRate): array
    {
        // UZS: USD × (USD→UZS rate)
        $uzs = (int) round($usdAmount * (float) $anchorRate->rate);

        // EUR: USD ÷ (USD→EUR rate), via live rate lookup
        $usdToEurRate = ExchangeRate::getCurrentRate(Currency::USD, Currency::EUR) ?? 0.0;
        $eur = $usdToEurRate > 0 ? round($usdAmount * $usdToEurRate, 2) : 0.0;

        // RUB: USD × (USD→RUB rate)
        $usdToRubRate = ExchangeRate::getCurrentRate(Currency::USD, Currency::RUB) ?? 0.0;
        $rub = $usdToRubRate > 0 ? round($usdAmount * $usdToRubRate, 2) : 0.0;

        return [$uzs, (float) $eur, (float) $rub, $anchorRate];
    }

    private function buildPresentation(
        BookingFxSync $sync,
        string        $guestName,
        string        $roomNumber,
    ): PaymentPresentation {
        return new PaymentPresentation(
            beds24BookingId:  $sync->beds24_booking_id,
            guestName:        $guestName,
            roomNumber:       $roomNumber,
            uzsAmount:        (int) $sync->uzs_final,
            eurAmount:        (float) $sync->eur_final,
            rubAmount:        (float) $sync->rub_final,
            usdAmount:        (float) $sync->usd_final,
            usdBookingAmount: (float) $sync->usd_amount_used,
            exchangeRateId:   $sync->exchange_rate_id,
            rateDate:         Carbon::parse($sync->fx_rate_date),
            preparedAt:       $sync->last_print_prepared_at ?? now(),
            isPrinted:        $sync->printed_rate_date !== null
                              && $sync->printed_rate_date->isSameDay($sync->fx_rate_date),
        );
    }
}
