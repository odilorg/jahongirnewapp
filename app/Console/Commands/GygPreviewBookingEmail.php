<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GygPreviewBookingEmail extends Command
{
    protected $signature = 'gyg:preview-booking-email
        {bookingId : Booking ID to preview}
        {--send : Actually send to override address instead of just previewing}';

    protected $description = 'Preview what emails would be sent for a GYG booking (dry-run by default)';

    public function handle(): int
    {
        $bookingId = (int) $this->argument('bookingId');

        $booking = DB::table('bookings')
            ->join('guests', 'bookings.guest_id', '=', 'guests.id')
            ->where('bookings.id', $bookingId)
            ->select([
                'bookings.id',
                'bookings.booking_number',
                'bookings.booking_start_date_time',
                'bookings.pickup_location',
                'bookings.booking_source',
                'bookings.confirmation_sent_at',
                'bookings.route_request_sent_at',
                'bookings.hotel_request_sent_at',
                'guests.first_name',
                'guests.last_name',
                'guests.email',
            ])
            ->first();

        if (! $booking) {
            $this->error("Booking #{$bookingId} not found.");
            return self::FAILURE;
        }

        // Find linked GYG email
        $gygEmail = DB::table('gyg_inbound_emails')
            ->where('booking_id', $bookingId)
            ->select(['id', 'tour_name', 'option_title', 'pax', 'travel_date', 'gyg_booking_reference', 'guest_name'])
            ->first();

        // ── Booking summary ───────────────────────────────────
        $this->info('');
        $this->info('═══ BOOKING SUMMARY ═══');
        $this->table([], [
            ['Booking ID', $booking->id],
            ['Booking #', $booking->booking_number],
            ['Source', $booking->booking_source],
            ['Guest', "{$booking->first_name} {$booking->last_name}"],
            ['Email', $booking->email ?? '(none)'],
            ['Date', $booking->booking_start_date_time],
            ['Pickup', $booking->pickup_location ?: '(empty)'],
            ['GYG Ref', $gygEmail->gyg_booking_reference ?? '(no GYG email linked)'],
            ['Tour', $gygEmail->tour_name ?? '(unknown)'],
            ['Option', $gygEmail->option_title ?? '(unknown)'],
            ['Pax', $gygEmail->pax ?? '?'],
        ]);

        // ── Classification ────────────────────────────────────
        $email = trim($booking->email ?? '');
        $isPlaceholder = empty($email) || $email === 'not-provided@gyg-import.local';
        $isPrivateYurt = false;
        $pickupMissing = empty(trim($booking->pickup_location ?? ''));

        if ($gygEmail) {
            $text = strtolower(($gygEmail->option_title ?? '') . ' ' . ($gygEmail->tour_name ?? ''));
            $isPrivateYurt = str_contains($text, 'yurt') && str_contains($text, 'private');
        }

        $this->info('');
        $this->info('═══ CLASSIFICATION ═══');
        $this->table([], [
            ['Placeholder email?', $isPlaceholder ? 'YES → SKIP ALL' : 'no'],
            ['Private yurt camp?', $isPrivateYurt ? 'YES' : 'no'],
            ['Pickup missing?', $pickupMissing ? 'YES' : 'no'],
        ]);

        if ($isPlaceholder) {
            $this->warn('→ Result: ALL EMAILS SKIPPED (placeholder/missing email)');
            return self::SUCCESS;
        }

        // ── Determine what would send ─────────────────────────
        $tourTitle = $gygEmail->option_title ?: $gygEmail->tour_name ?: 'your tour';
        $dateLabel = $booking->booking_start_date_time
            ? date('F d, Y', strtotime($booking->booking_start_date_time))
            : 'your scheduled date';
        $firstName = $booking->first_name ?: 'Guest';
        $pax = $gygEmail->pax ?? 1;
        $ref = $booking->booking_number;

        $emails = [];

        // Email 1: Confirmation
        $alreadySent = (bool) $booking->confirmation_sent_at;
        $emails[] = [
            'type'    => 'confirmation',
            'would'   => $alreadySent ? 'SKIP (already sent)' : 'SEND',
            'subject' => "Your booking is confirmed — {$tourTitle}",
            'body'    => implode("\n", [
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
            ]),
        ];

        // Email 2: depends on type
        if ($isPrivateYurt) {
            $alreadySent2 = (bool) $booking->route_request_sent_at;
            $emails[] = [
                'type'    => 'private_yurt_route_request',
                'would'   => $alreadySent2 ? 'SKIP (already sent)' : 'SEND',
                'subject' => "{$tourTitle} — {$dateLabel} | Route & Pickup Details Needed",
                'body'    => implode("\n", [
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
                ]),
            ];
        } elseif ($pickupMissing) {
            $alreadySent2 = (bool) $booking->hotel_request_sent_at;
            $emails[] = [
                'type'    => 'hotel_pickup_request',
                'would'   => $alreadySent2 ? 'SKIP (already sent)' : 'SEND',
                'subject' => "{$tourTitle} — {$dateLabel} | Pickup Location Needed",
                'body'    => implode("\n", [
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
                ]),
            ];
        } else {
            $this->info('→ No follow-up email needed (pickup already set)');
        }

        // ── Display emails ────────────────────────────────────
        $override = config('services.gyg.email_override_to');

        foreach ($emails as $e) {
            $this->info('');
            $this->info("═══ EMAIL: {$e['type']} ═══");
            $this->info("Action:  {$e['would']}");
            $this->info("To:      " . ($override ?: $booking->email) . ($override ? " (OVERRIDE — real: {$booking->email})" : ''));
            $this->info("Subject: {$e['subject']}");
            $this->info("─── Body ───");
            $this->line($e['body']);
            $this->info("─── End ────");
        }

        // ── Optional: send to override ────────────────────────
        if ($this->option('send')) {
            if (! $override) {
                $this->error('Cannot --send without GYG_EMAIL_OVERRIDE_TO set in config/services.php');
                return self::FAILURE;
            }
            $this->info('');
            $this->warn("Sending to override: {$override}");

            $mailer = app(\App\Services\GygPostBookingMailer::class);
            $mailer->handleAppliedBooking($bookingId, $gygEmail->id ?? 0);
            $this->info('Done. Check your inbox.');
        }

        return self::SUCCESS;
    }
}
