<?php

namespace App\Services\Stay;

use App\Models\Beds24Booking;

/**
 * Compact read-model for a single booking, shaped for Telegram list rendering.
 *
 * Intentionally immutable and presentation-free — no formatting here.
 * The bot controller decides how to render each field.
 *
 * Balance note: invoice_balance is included as-is from the DB column.
 * It reflects the outstanding amount at last Beds24 sync, not real-time.
 * Use it for informational display only, not for payment enforcement.
 */
readonly class BookingSummary
{
    public function __construct(
        public string  $beds24BookingId,
        public string  $guestName,
        public string  $roomName,
        public string  $propertyName,
        public string  $arrivalDate,     // Y-m-d
        public string  $departureDate,   // Y-m-d
        public string  $bookingStatus,
        public float   $invoiceBalance,
        public string  $currency,
        public int     $numAdults,
        public int     $numChildren,
    ) {}

    public static function fromModel(Beds24Booking $b): self
    {
        return new self(
            beds24BookingId: $b->beds24_booking_id,
            guestName:       $b->guest_name ?: 'Unknown Guest',
            roomName:        $b->room_name  ?: 'Room ' . ($b->room_id ?? '?'),
            propertyName:    $b->getPropertyName(),
            arrivalDate:     $b->arrival_date->toDateString(),
            departureDate:   $b->departure_date->toDateString(),
            bookingStatus:   $b->booking_status,
            invoiceBalance:  (float) $b->invoice_balance,
            currency:        $b->currency ?? 'USD',
            numAdults:       (int) $b->num_adults,
            numChildren:     (int) $b->num_children,
        );
    }
}
