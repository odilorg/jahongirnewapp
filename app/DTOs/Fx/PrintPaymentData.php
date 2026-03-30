<?php

namespace App\DTOs\Fx;

use Carbon\Carbon;

/**
 * Snapshot used to render the registration form PDF.
 * Identical to what the bot shows — populated from booking_fx_syncs.
 */
final class PrintPaymentData
{
    public function __construct(
        public readonly string $beds24BookingId,
        public readonly string $guestName,
        public readonly string $roomNumber,
        public readonly Carbon $arrivalDate,
        public readonly Carbon $rateDate,

        public readonly float  $usdBookingAmount,
        public readonly int    $uzsAmount,
        public readonly float  $eurAmount,
        public readonly float  $rubAmount,
        public readonly float  $usdAmount,

        public readonly Carbon $preparedAt,
    ) {}
}
