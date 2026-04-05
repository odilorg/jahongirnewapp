<?php

namespace App\Services\Stay;

use App\Models\Beds24Booking;
use App\Models\StayTransitionLog;
use Illuminate\Support\Facades\DB;

/**
 * Performs a check-out transition on a Beds24Booking.
 *
 * Responsibility: validate eligibility, update status, write audit log.
 * Does NOT call Beds24 API — sync is a separate concern (Phase 7).
 * Does NOT know about Telegram — the bot controller calls this service.
 */
class CheckOutService
{
    /**
     * Check out a booking.
     *
     * @param  string $beds24BookingId  The Beds24 booking ID (string PK)
     * @param  int    $actorUserId      The staff member performing the action
     * @param  string $source           Caller identifier, e.g. 'telegram_cashier_bot'
     * @return Beds24Booking            The updated booking (fresh from DB)
     *
     * @throws \DomainException  If the transition is not allowed
     */
    public function checkOut(string $beds24BookingId, int $actorUserId, string $source = 'telegram_cashier_bot'): Beds24Booking
    {
        return DB::transaction(function () use ($beds24BookingId, $actorUserId, $source) {
            $booking = Beds24Booking::where('beds24_booking_id', $beds24BookingId)
                ->lockForUpdate()
                ->firstOrFail();

            $oldStatus = $booking->booking_status;

            $this->assertEligible($booking, $oldStatus);

            $booking->update(['booking_status' => 'checked_out']);

            StayTransitionLog::record(
                bookingId:  $beds24BookingId,
                actorId:    $actorUserId,
                action:     'check_out',
                oldStatus:  $oldStatus,
                newStatus:  'checked_out',
                source:     $source,
            );

            return $booking->fresh();
        });
    }

    private function assertEligible(Beds24Booking $booking, string $status): void
    {
        if ($status === 'checked_out') {
            throw new \DomainException(
                "Cannot check out booking {$booking->beds24_booking_id}: already checked out."
            );
        }

        if (in_array($status, ['cancelled', 'no_show'], true)) {
            throw new \DomainException(
                "Cannot check out booking {$booking->beds24_booking_id}: status is '{$status}'."
            );
        }

        if ($status !== 'checked_in') {
            throw new \DomainException(
                "Cannot check out booking {$booking->beds24_booking_id}: must be checked_in first (current: '{$status}')."
            );
        }
    }
}
