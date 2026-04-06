<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single mutation layer for operational booking actions performed via Telegram.
 *
 * All state guards live here. Telegram handlers must NEVER apply status
 * transitions or direct model updates directly — call this service instead.
 *
 * Allowed transitions per status:
 *   pending   → confirm, cancel, assign_driver, assign_guide, set_price, set_pickup
 *   confirmed → cancel, assign_driver (reassign), assign_guide (reassign), set_price, set_pickup
 *   cancelled → read-only (all actions throw BookingOpsException)
 *   any other → read-only
 *
 * Every mutation is appended to booking_ops_logs for auditing.
 */
class BookingOpsService
{
    // ── Public actions ───────────────────────────────────────────────────────

    /**
     * Move a pending booking to confirmed.
     * Triggers notification scheduling via Booking::updated boot hook.
     */
    public function confirm(Booking $booking, string $actor): void
    {
        $this->assertAllowed($booking, 'confirm');

        $old = $booking->booking_status;
        $booking->update(['booking_status' => 'confirmed']);

        $this->log($booking, $actor, 'confirm', ['booking_status' => ['old' => $old, 'new' => 'confirmed']]);
    }

    /**
     * Cancel a booking (pending or confirmed).
     * Deletes scheduled notifications via Booking::updated boot hook.
     */
    public function cancel(Booking $booking, string $actor): void
    {
        $this->assertAllowed($booking, 'cancel');

        $old = $booking->booking_status;
        $booking->update(['booking_status' => 'cancelled']);

        $this->log($booking, $actor, 'cancel', ['booking_status' => ['old' => $old, 'new' => 'cancelled']]);
    }

    /**
     * Assign or reassign a driver.
     * Resets driver_status to pending so the driver notification flow can fire.
     */
    public function assignDriver(Booking $booking, int $driverId, string $actor): void
    {
        $this->assertAllowed($booking, 'assign_driver');

        $old = $booking->driver_id;
        $booking->update([
            'driver_id'     => $driverId,
            'driver_status' => 'pending',
        ]);

        $this->log($booking, $actor, 'assign_driver', ['driver_id' => ['old' => $old, 'new' => $driverId]]);
    }

    /** Assign or reassign a guide. */
    public function assignGuide(Booking $booking, int $guideId, string $actor): void
    {
        $this->assertAllowed($booking, 'assign_guide');

        $old = $booking->guide_id;
        $booking->update(['guide_id' => $guideId]);

        $this->log($booking, $actor, 'assign_guide', ['guide_id' => ['old' => $old, 'new' => $guideId]]);
    }

    /** Set (or update) the booking price. */
    public function setPrice(Booking $booking, float $amount, string $actor): void
    {
        $this->assertAllowed($booking, 'set_price');

        $old = $booking->amount;
        $booking->update(['amount' => $amount]);

        $this->log($booking, $actor, 'set_price', ['amount' => ['old' => $old, 'new' => $amount]]);
    }

    /** Set (or update) the pickup location. */
    public function setPickupLocation(Booking $booking, string $location, string $actor): void
    {
        $this->assertAllowed($booking, 'set_pickup');

        $old = $booking->pickup_location;
        $booking->update(['pickup_location' => $location]);

        $this->log($booking, $actor, 'set_pickup', ['pickup_location' => ['old' => $old, 'new' => $location]]);
    }

    // ── Edit actions (guest details, date, pax, notes) ───────────────────────

    /**
     * Edit the guest's full name.
     * Updates first_name + last_name on the linked Guest record.
     *
     * @throws \RuntimeException if the booking has no linked guest.
     */
    public function editGuestName(Booking $booking, string $firstName, string $lastName, string $actor): void
    {
        $this->assertAllowed($booking, 'edit_guest_name');

        $guest = $booking->guest;
        if (! $guest) {
            throw new \RuntimeException("Cannot edit guest name: booking has no linked guest.");
        }

        $old = trim($guest->first_name . ' ' . $guest->last_name);
        $guest->update(['first_name' => $firstName, 'last_name' => $lastName]);
        $new = trim($firstName . ' ' . $lastName);

        $this->log($booking, $actor, 'edit_guest_name', ['guest_name' => ['old' => $old, 'new' => $new]]);
    }

    /** Edit the guest's phone number. */
    public function editGuestPhone(Booking $booking, string $phone, string $actor): void
    {
        $this->assertAllowed($booking, 'edit_guest_phone');

        $guest = $booking->guest;
        if (! $guest) {
            throw new \RuntimeException("Cannot edit guest phone: booking has no linked guest.");
        }

        $old = $guest->phone;
        $guest->update(['phone' => $phone]);

        $this->log($booking, $actor, 'edit_guest_phone', ['phone' => ['old' => $old, 'new' => $phone]]);
    }

    /** Edit the guest's email address. */
    public function editGuestEmail(Booking $booking, string $email, string $actor): void
    {
        $this->assertAllowed($booking, 'edit_guest_email');

        $guest = $booking->guest;
        if (! $guest) {
            throw new \RuntimeException("Cannot edit guest email: booking has no linked guest.");
        }

        $old = $guest->email;
        $guest->update(['email' => $email]);

        $this->log($booking, $actor, 'edit_guest_email', ['email' => ['old' => $old, 'new' => $email]]);
    }

    /**
     * Edit the booking start date/time.
     * Triggers notification reschedule automatically via Booking::updated boot hook.
     *
     * @param  string $dateTime  Format: 'Y-m-d H:i:s'
     */
    public function editDate(Booking $booking, string $dateTime, string $actor): void
    {
        $this->assertAllowed($booking, 'edit_date');

        $old = $booking->booking_start_date_time;
        $booking->update(['booking_start_date_time' => $dateTime]);

        $this->log($booking, $actor, 'edit_date', [
            'booking_start_date_time' => ['old' => $old, 'new' => $dateTime],
        ]);
    }

    /**
     * Edit the pax count.
     * Updates number_of_people on the Guest record (the authoritative source)
     * and on the Booking record for consistency.
     */
    public function editPax(Booking $booking, int $pax, string $actor): void
    {
        $this->assertAllowed($booking, 'edit_pax');

        $guest = $booking->guest;
        if (! $guest) {
            throw new \RuntimeException("Cannot edit pax: booking has no linked guest.");
        }

        $old = $guest->number_of_people;
        $guest->update(['number_of_people' => $pax]);
        $booking->update(['number_of_people' => $pax]);

        $this->log($booking, $actor, 'edit_pax', ['number_of_people' => ['old' => $old, 'new' => $pax]]);
    }

    /** Edit the special requests / notes field. */
    public function editNotes(Booking $booking, string $notes, string $actor): void
    {
        $this->assertAllowed($booking, 'edit_notes');

        $old = $booking->special_requests;
        $booking->update(['special_requests' => $notes]);

        $this->log($booking, $actor, 'edit_notes', ['special_requests' => ['old' => $old, 'new' => $notes]]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Validate that $action is legal for the booking's current status.
     *
     * @throws \RuntimeException  with a human-readable message suitable for display in Telegram.
     */
    private function assertAllowed(Booking $booking, string $action): void
    {
        $status = $booking->booking_status;

        $allowed = match ($status) {
            'pending'   => [
                'confirm', 'cancel', 'assign_driver', 'assign_guide', 'set_price', 'set_pickup',
                'edit_guest_name', 'edit_guest_phone', 'edit_guest_email', 'edit_date', 'edit_pax', 'edit_notes',
            ],
            'confirmed' => [
                'cancel', 'assign_driver', 'assign_guide', 'set_price', 'set_pickup',
                'edit_guest_name', 'edit_guest_phone', 'edit_guest_email', 'edit_date', 'edit_pax', 'edit_notes',
            ],
            default     => [],  // cancelled, finished, etc. → read-only
        };

        if (! in_array($action, $allowed, true)) {
            throw new \RuntimeException(
                "Cannot perform '{$action}' on a booking with status '{$status}'."
            );
        }
    }

    private function log(Booking $booking, string $actor, string $action, array $changes): void
    {
        try {
            DB::table('booking_ops_logs')->insert([
                'booking_id'     => $booking->id,
                'booking_number' => $booking->booking_number,
                'actor'          => $actor,
                'action'         => $action,
                'changes'        => json_encode($changes),
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            // Logging must never interrupt the mutation itself.
            Log::error("BookingOpsService: failed to write audit log for booking #{$booking->id}", [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
