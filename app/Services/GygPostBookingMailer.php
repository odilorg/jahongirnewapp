<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GygPostBookingMailer
{
    private const PLACEHOLDER_EMAIL = 'not-provided@gyg-import.local';
    private const FROM_EMAIL = 'odilorg@gmail.com';

    /**
     * Send post-booking emails for a GYG booking.
     * Called after booking is fully saved. Never throws.
     */
    public function handleAppliedBooking(int $bookingId, int $gygEmailId): void
    {
        try {
            $this->process($bookingId, $gygEmailId);
        } catch (\Throwable $e) {
            Log::error('GygPostBookingMailer: unhandled exception', [
                'booking_id' => $bookingId,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    private function process(int $bookingId, int $gygEmailId): void
    {
        $booking = DB::table('bookings')
            ->join('guests', 'bookings.guest_id', '=', 'guests.id')
            ->where('bookings.id', $bookingId)
            ->select([
                'bookings.id',
                'bookings.booking_number',
                'bookings.booking_start_date_time',
                'bookings.pickup_location',
                'bookings.confirmation_sent_at',
                'bookings.route_request_sent_at',
                'bookings.hotel_request_sent_at',
                'guests.first_name',
                'guests.last_name',
                'guests.email',
            ])
            ->first();

        if (! $booking) {
            Log::warning('GygPostBookingMailer: booking not found', ['booking_id' => $bookingId]);
            return;
        }

        $email = trim($booking->email ?? '');
        if (empty($email) || $email === self::PLACEHOLDER_EMAIL) {
            Log::info('GygPostBookingMailer: skipping — no real guest email', [
                'booking_id' => $bookingId,
                'email'      => $email,
            ]);
            return;
        }

        $gygEmail = DB::table('gyg_inbound_emails')
            ->where('id', $gygEmailId)
            ->select(['tour_name', 'option_title', 'pax', 'travel_date'])
            ->first();

        if (! $gygEmail) {
            return;
        }

        $tourTitle = $gygEmail->option_title ?: $gygEmail->tour_name ?: 'your tour';
        $dateLabel = $booking->booking_start_date_time
            ? date('F d, Y', strtotime($booking->booking_start_date_time))
            : 'your scheduled date';
        $firstName = $booking->first_name ?: 'Guest';
        $pax       = $gygEmail->pax ?? 1;

        // 1. Confirmation email (always, once)
        if (! $booking->confirmation_sent_at) {
            $this->sendConfirmation($booking->id, $email, $firstName, $tourTitle, $dateLabel, $booking->booking_number, $pax);
            usleep(1_500_000); // 1.5s gap between emails
        }

        // 2. Detect tour type
        $isPrivateYurt = $this->isPrivateYurtCampBooking($gygEmail->option_title, $gygEmail->tour_name);

        Log::info('GygPostBookingMailer: tour classified', [
            'booking_id'     => $bookingId,
            'booking_number' => $booking->booking_number,
            'private_yurt'   => $isPrivateYurt,
            'option_title'   => $gygEmail->option_title,
        ]);

        if ($isPrivateYurt) {
            // Private yurt: route choice + pickup + dropoff
            if (! $booking->route_request_sent_at) {
                $this->sendPrivateYurtRouteRequest($booking->id, $email, $firstName, $tourTitle, $dateLabel);
            }
            return; // do NOT fall into pickup logic
        }

        // 3. Standard: hotel pickup only (if needed)
        if ($this->needsPickupRequest($booking->pickup_location) && ! $booking->hotel_request_sent_at) {
            $this->sendHotelPickupRequest($booking->id, $email, $firstName, $tourTitle, $dateLabel);
        }
    }

    // ── Detection ──────────────────────────────────────────

    private function isPrivateYurtCampBooking(?string $optionTitle, ?string $tourName): bool
    {
        $text = strtolower(($optionTitle ?? '') . ' ' . ($tourName ?? ''));

        return str_contains($text, 'yurt') && str_contains($text, 'private');
    }

    private function needsPickupRequest(?string $pickupLocation): bool
    {
        return empty(trim($pickupLocation ?? ''));
    }

    // ── Email senders ──────────────────────────────────────

    private function sendConfirmation(int $bookingId, string $to, string $firstName, string $tourTitle, string $dateLabel, ?string $ref, int $pax): void
    {
        $subject = "Your booking is confirmed — {$tourTitle}";

        $body = implode("\n", [
            "Dear {$firstName},",
            "",
            "Thank you for booking \"{$tourTitle}\" on {$dateLabel} through GetYourGuide!",
            "",
            "Booking reference: {$ref}",
            "Guests: {$pax}",
            "",
            "We have received your booking and everything is confirmed.",
            "We look forward to welcoming you!",
            "",
            "Best regards,",
            "Odiljon",
            "Jahongir Travel",
        ]);

        if ($this->sendViaHimalaya($to, $subject, $body)) {
            DB::table('bookings')->where('id', $bookingId)->update(['confirmation_sent_at' => now()]);
            Log::info('GygPostBookingMailer: confirmation sent', ['booking_id' => $bookingId, 'ref' => $ref, 'email' => $to]);
        } else {
            Log::error('GygPostBookingMailer: confirmation send failed', ['booking_id' => $bookingId, 'ref' => $ref, 'email' => $to]);
        }
    }

    private function sendPrivateYurtRouteRequest(int $bookingId, string $to, string $firstName, string $tourTitle, string $dateLabel): void
    {
        $subject = "{$tourTitle} — {$dateLabel} | Route & Pickup Details Needed";

        $body = implode("\n", [
            "Dear {$firstName},",
            "",
            "A few details for your private yurt camp tour on {$dateLabel}:",
            "",
            "1. ROUTE OPTION",
            "   Your booking is for Samarkand to Bukhara (you finish in Bukhara).",
            "   If you prefer, we can change it to Samarkand to Samarkand",
            "   (return to Samarkand). Just reply and let us know!",
            "",
            "2. PICKUP HOTEL",
            "   Please reply with the name of your hotel in Samarkand",
            "   so we can pick you up on the morning of the tour.",
            "",
            "3. DROP-OFF HOTEL",
            "   If you are going to Bukhara, please also share your",
            "   hotel name in Bukhara for drop-off.",
            "",
            "Just reply to this email with your answers.",
            "",
            "Thank you!",
            "Odiljon",
            "Jahongir Travel",
        ]);

        if ($this->sendViaHimalaya($to, $subject, $body)) {
            // Set both: route_request_sent_at for our tracking, hotel_request_sent_at
            // to prevent the tour:send-hotel-requests cron from sending a duplicate
            // generic pickup email to this guest.
            DB::table('bookings')->where('id', $bookingId)->update([
                'route_request_sent_at'  => now(),
                'hotel_request_sent_at'  => now(),
            ]);
            Log::info('GygPostBookingMailer: route request sent', ['booking_id' => $bookingId, 'email' => $to]);
        } else {
            Log::error('GygPostBookingMailer: route request send failed', ['booking_id' => $bookingId, 'email' => $to]);
        }
    }

    private function sendHotelPickupRequest(int $bookingId, string $to, string $firstName, string $tourTitle, string $dateLabel): void
    {
        $subject = "{$tourTitle} — {$dateLabel} | Pickup Location Needed";

        $body = implode("\n", [
            "Dear {$firstName},",
            "",
            "To arrange your pickup for \"{$tourTitle}\" on {$dateLabel},",
            "could you please reply with the name of your hotel in Samarkand?",
            "",
            "We will pick you up directly from the hotel lobby.",
            "",
            "Thank you!",
            "Odiljon",
            "Jahongir Travel",
        ]);

        if ($this->sendViaHimalaya($to, $subject, $body)) {
            DB::table('bookings')->where('id', $bookingId)->update(['hotel_request_sent_at' => now()]);
            Log::info('GygPostBookingMailer: hotel pickup request sent', ['booking_id' => $bookingId, 'email' => $to]);
        } else {
            Log::error('GygPostBookingMailer: hotel pickup request send failed', ['booking_id' => $bookingId, 'email' => $to]);
        }
    }

    // ── Himalaya transport (reuses existing pattern) ───────

    private function sendViaHimalaya(string $to, string $subject, string $body): bool
    {
        $mml = "From: " . self::FROM_EMAIL . "\nTo: {$to}\nSubject: {$subject}\n\n{$body}";

        $tmpFile = tempnam(sys_get_temp_dir(), 'gyg_mail_') . '.eml';
        file_put_contents($tmpFile, $mml);

        $output = [];
        $code   = 1;
        exec("himalaya send < " . escapeshellarg($tmpFile) . " 2>&1", $output, $code);
        unlink($tmpFile);

        if ($code !== 0) {
            Log::error('GygPostBookingMailer: himalaya send failed', [
                'to'     => $to,
                'code'   => $code,
                'output' => implode(' ', $output),
            ]);
        }

        return $code === 0;
    }
}
