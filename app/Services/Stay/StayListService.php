<?php

namespace App\Services\Stay;

use App\Models\Beds24Booking;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-model queries for daily hotel operations.
 *
 * Returns compact BookingSummary collections for Telegram list rendering.
 * These are intentionally read-only — no state changes here.
 *
 * Property filtering:
 *   Pass a $propertyId string to scope to one property, or null for all.
 *   The cashier bot currently scopes to property 41097 (Jahongir Hotel).
 *
 * Ordering:
 *   Both lists order by room_name ASC, then beds24_booking_id ASC.
 *   Room name is the most operationally useful sort for front-desk use.
 *   When room_name is absent the secondary sort keeps results stable.
 *
 * Balance:
 *   invoice_balance is included from the DB column as-is (last Beds24 sync).
 *   It is informational only — not authoritative for payment enforcement.
 */
class StayListService
{
    /**
     * Bookings arriving today that are candidates for check-in.
     *
     * Eligible: confirmed, new
     * Excluded: cancelled, no_show, checked_in (already done), checked_out (impossible without check-in)
     *
     * @param  string|null  $propertyId  Beds24 property ID to filter, or null for all
     * @param  string|null  $date        Y-m-d override for testing; defaults to today
     * @return Collection<int, BookingSummary>
     */
    public function getArrivalsToday(?string $propertyId = null, ?string $date = null): Collection
    {
        $today = $date ?? Carbon::today()->toDateString();

        return Beds24Booking::query()
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->where('arrival_date', $today)
            ->whereIn('booking_status', ['confirmed', 'new'])
            ->orderBy('room_name')
            ->orderBy('beds24_booking_id')
            ->get()
            ->map(fn (Beds24Booking $b) => BookingSummary::fromModel($b));
    }

    /**
     * Bookings departing today that are candidates for check-out.
     *
     * Eligible: checked_in (primary operational case), confirmed/new (arrived but not yet
     * formally checked in via bot — still relevant for front desk awareness)
     * Excluded: cancelled, no_show, checked_out (already done)
     *
     * Rationale for including confirmed/new on departure day:
     *   Walk-in or same-day arrivals may not have been checked in via bot yet.
     *   Front desk needs to see them so they can process check-out manually or
     *   do a same-day check-in + check-out. Phase 8 will add a warning for these.
     *
     * @param  string|null  $propertyId  Beds24 property ID to filter, or null for all
     * @param  string|null  $date        Y-m-d override for testing; defaults to today
     * @return Collection<int, BookingSummary>
     */
    public function getDeparturesToday(?string $propertyId = null, ?string $date = null): Collection
    {
        $today = $date ?? Carbon::today()->toDateString();

        return Beds24Booking::query()
            ->when($propertyId, fn ($q) => $q->where('property_id', $propertyId))
            ->where('departure_date', $today)
            ->whereIn('booking_status', ['checked_in', 'confirmed', 'new'])
            ->orderBy('room_name')
            ->orderBy('beds24_booking_id')
            ->get()
            ->map(fn (Beds24Booking $b) => BookingSummary::fromModel($b));
    }
}
