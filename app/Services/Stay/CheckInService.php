<?php

namespace App\Services\Stay;

use App\Models\Beds24Booking;
use App\Models\StayTransitionLog;
use Illuminate\Support\Facades\DB;

/**
 * Performs a check-in transition on a Beds24Booking.
 *
 * Responsibility: validate eligibility, update status, write audit log.
 * Does NOT call Beds24 API — sync is a separate concern (Phase 7).
 * Does NOT know about Telegram — the bot controller calls this service.
 */
class CheckInService
{
    /** Statuses that may be promoted to checked_in */
    private const ELIGIBLE_STATUSES = ['confirmed', 'new'];

    /** Statuses that permanently block check-in */
    private const BLOCKED_STATUSES = ['cancelled', 'no_show', 'checked_in', 'checked_out'];

    /**
     * Check in a booking.
     *
     * @param  string $beds24BookingId  The Beds24 booking ID (string PK)
     * @param  int    $actorUserId      The staff member performing the action
     * @param  string $source           Caller identifier, e.g. 'telegram_cashier_bot'
     * @return Beds24Booking            The updated booking (fresh from DB)
     *
     * @throws \DomainException  If the transition is not allowed
     */
    public function checkIn(string $beds24BookingId, int $actorUserId, string $source = 'telegram_cashier_bot'): Beds24Booking
    {
        return DB::transaction(function () use ($beds24BookingId, $actorUserId, $source) {
            $booking = Beds24Booking::where('beds24_booking_id', $beds24BookingId)
                ->lockForUpdate()
                ->firstOrFail();

            $oldStatus = $booking->booking_status;

            $this->assertEligible($booking, $oldStatus);

            $booking->update(['booking_status' => 'checked_in']);

            StayTransitionLog::record(
                bookingId:  $beds24BookingId,
                actorId:    $actorUserId,
                action:     'check_in',
                oldStatus:  $oldStatus,
                newStatus:  'checked_in',
                source:     $source,
            );

            return $booking->fresh();
        });
    }

    private function assertEligible(Beds24Booking $booking, string $status): void
    {
        if (in_array($status, self::BLOCKED_STATUSES, true)) {
            throw new \DomainException(
                "Cannot check in booking {$booking->beds24_booking_id}: status is '{$status}'."
            );
        }

        if (!in_array($status, self::ELIGIBLE_STATUSES, true)) {
            throw new \DomainException(
                "Cannot check in booking {$booking->beds24_booking_id}: unexpected status '{$status}'."
            );
        }
    }
}
