<?php

namespace App\Services\Fx;

use App\DTOs\Fx\PrintPaymentData;
use App\Enums\FxSourceTrigger;
use App\Enums\FxSyncPushStatus;
use App\Models\BookingFxSync;
use Carbon\Carbon;

/**
 * Prepares the data needed to render a registration form PDF.
 *
 * Rules:
 * - If arrival is within fx.print_require_push_within_days days AND the last push has
 *   permanently failed, throw rather than silently print stale data.
 * - Otherwise always return a PrintPaymentData built from the current (or freshly
 *   refreshed) booking_fx_syncs snapshot, and stamp printed_rate_date.
 */
class PrintPreparationService
{
    public function __construct(
        private readonly FxSyncService $fxSync,
    ) {}

    /**
     * @param  string  $beds24BookingId
     * @param  float   $usdAmount        Booking total from Beds24
     * @param  Carbon  $arrivalDate
     * @param  string  $guestName
     * @param  string  $roomNumber
     *
     * @throws \RuntimeException  If near-arrival booking has a permanently failed push
     */
    public function prepare(
        string $beds24BookingId,
        float  $usdAmount,
        Carbon $arrivalDate,
        string $guestName,
        string $roomNumber,
    ): PrintPaymentData {
        $presentation = $this->fxSync->getOrRefresh(
            beds24BookingId: $beds24BookingId,
            usdAmount:       $usdAmount,
            arrivalDate:     $arrivalDate,
            guestName:       $guestName,
            roomNumber:      $roomNumber,
            trigger:         FxSourceTrigger::Print,
        );

        $sync = BookingFxSync::where('beds24_booking_id', $beds24BookingId)->firstOrFail();

        // Guard: near-arrival + permanently failed push = don't print
        $pushRequiredDays = (int) config('fx.print_require_push_within_days', 2);
        $nearArrival      = now()->diffInDays($arrivalDate, false) <= $pushRequiredDays
                            && $arrivalDate->isFuture();

        if ($nearArrival && $sync->push_status === FxSyncPushStatus::Failed) {
            throw new \RuntimeException(
                "Cannot print: infoItems push has permanently failed for booking {$beds24BookingId}. "
                . 'Ops attention required before printing.'
            );
        }

        // Stamp that this snapshot was printed
        $this->fxSync->markPrinted($sync);

        return new PrintPaymentData(
            beds24BookingId: $beds24BookingId,
            guestName:       $guestName,
            roomNumber:      $roomNumber,
            arrivalDate:     $arrivalDate,
            rateDate:        $presentation->rateDate,
            usdBookingAmount: $presentation->usdBookingAmount,
            uzsAmount:       $presentation->uzsAmount,
            eurAmount:       $presentation->eurAmount,
            rubAmount:       $presentation->rubAmount,
            usdAmount:       $presentation->usdAmount,
            preparedAt:      now(),
        );
    }
}
