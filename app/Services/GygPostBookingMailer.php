<?php

namespace App\Services;

use App\Services\Messaging\GuestContactSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates post-booking guest communication for GYG bookings.
 *
 * Decides WHAT to send and to WHOM. Does not handle transport.
 * Transport is delegated to GuestContactSender (WA-first, email fallback).
 */
class GygPostBookingMailer
{
    private const PLACEHOLDER_EMAIL = 'not-provided@gyg-import.local';

    public function __construct(
        private GuestContactSender $sender,
    ) {}

    /**
     * Send post-booking emails/messages for a GYG booking.
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
                'guests.phone',
            ])
            ->first();

        if (! $booking) {
            Log::warning('GygPostBookingMailer: booking not found', ['booking_id' => $bookingId]);
            return;
        }

        $guestEmail = trim($booking->email ?? '');
        if (empty($guestEmail) || $guestEmail === self::PLACEHOLDER_EMAIL) {
            Log::info('GygPostBookingMailer: skipping — no real guest email', [
                'booking_id' => $bookingId,
                'email'      => $guestEmail,
            ]);
            return;
        }

        $gygEmail = DB::table('gyg_inbound_emails')
            ->where('id', $gygEmailId)
            ->select(['tour_name', 'option_title', 'pax', 'travel_date', 'tour_type'])
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
        $ref       = $booking->booking_number;
        $phone     = $booking->phone;

        // Classify using tour_type from DB (set by GygEmailParser: 'group' or 'private').
        // Group tours have a fixed meeting point (Gur Emir Mausoleum) — never ask for hotel.
        // Only private tours need a hotel pickup request.
        $isPrivate     = ($gygEmail->tour_type === 'private');
        $isPrivateYurt = $isPrivate && $this->isYurtCampBooking($gygEmail->option_title, $gygEmail->tour_name);
        $selectedVariant = $isPrivateYurt ? 'route_request' : ($isPrivate ? 'pickup_request' : 'confirmation_only');

        Log::info('GygPostBookingMailer: classified', [
            'booking_id'       => $bookingId,
            'booking_number'   => $ref,
            'email'            => $guestEmail,
            'phone'            => $phone,
            'tour_type'        => $gygEmail->tour_type,
            'is_private'       => $isPrivate,
            'is_private_yurt'  => $isPrivateYurt,
            'selected_variant' => $selectedVariant,
            'option_title'     => $gygEmail->option_title,
        ]);

        // 1. Confirmation (always, once)
        if (! $booking->confirmation_sent_at) {
            $result = $this->sender->send([
                'phone'             => $phone,
                'email'             => $guestEmail,
                'wa_message'        => $this->waConfirmation($firstName, $tourTitle, $dateLabel, $ref, $pax),
                'email_subject'     => "Your booking is confirmed — {$tourTitle}",
                'email_body'        => $this->emailConfirmation($firstName, $tourTitle, $dateLabel, $ref, $pax),
                'booking_id'        => $bookingId,
                'booking_ref'       => $ref,
                'notification_type' => 'confirmation',
            ]);

            if ($result->success) {
                DB::table('bookings')->where('id', $bookingId)->update(['confirmation_sent_at' => now()]);
            }

            usleep(1_500_000);
        }

        // 2. Private yurt: route + pickup + dropoff
        if ($isPrivateYurt) {
            if (! $booking->route_request_sent_at) {
                $result = $this->sender->send([
                    'phone'             => $phone,
                    'email'             => $guestEmail,
                    'wa_message'        => $this->waPrivateYurtRoute($firstName, $tourTitle, $dateLabel),
                    'email_subject'     => "{$tourTitle} — {$dateLabel} | Route & Pickup Details Needed",
                    'email_body'        => $this->emailPrivateYurtRoute($firstName, $tourTitle, $dateLabel),
                    'booking_id'        => $bookingId,
                    'booking_ref'       => $ref,
                    'notification_type' => 'private_yurt_route_request',
                ]);

                if ($result->success) {
                    DB::table('bookings')->where('id', $bookingId)->update([
                        'route_request_sent_at' => now(),
                        'hotel_request_sent_at' => now(),
                    ]);
                }
            }
            return;
        }

        // 3. Private non-yurt: hotel pickup only (group tours never reach here)
        if ($isPrivate && ! $booking->hotel_request_sent_at) {
            $result = $this->sender->send([
                'phone'             => $phone,
                'email'             => $guestEmail,
                'wa_message'        => $this->waPickupRequest($firstName, $tourTitle, $dateLabel),
                'email_subject'     => "{$tourTitle} — {$dateLabel} | Pickup Location Needed",
                'email_body'        => $this->emailPickupRequest($firstName, $tourTitle, $dateLabel),
                'booking_id'        => $bookingId,
                'booking_ref'       => $ref,
                'notification_type' => 'hotel_pickup_request',
            ]);

            if ($result->success) {
                DB::table('bookings')->where('id', $bookingId)->update(['hotel_request_sent_at' => now()]);
            }
        }
    }

    // ── Detection ──────────────────────────────────────────

    // True if this is a yurt camp tour (private yurt needs route+pickup+dropoff questions).
    // tour_type='private' is checked separately before calling this.
    private function isYurtCampBooking(?string $optionTitle, ?string $tourName): bool
    {
        $text = strtolower(($optionTitle ?? '') . ' ' . ($tourName ?? ''));
        return str_contains($text, 'yurt');
    }

    // ── WhatsApp message builders ──────────────────────────

    private function waConfirmation(string $firstName, string $tourTitle, string $dateLabel, ?string $ref, int $pax): string
    {
        return "Hi {$firstName}! 👋\n\nYour booking is confirmed:\n📍 {$tourTitle}\n📅 {$dateLabel}\n👥 {$pax} guest(s)\n🔖 Ref: {$ref}\n\nWe look forward to welcoming you!\n— Jahongir Travel";
    }

    private function waPickupRequest(string $firstName, string $tourTitle, string $dateLabel): string
    {
        return "Hi {$firstName},\n\nCould you please share the name of your hotel in Samarkand?\nWe'll pick you up from the lobby on the morning of the tour ({$dateLabel}).\n\nThanks! 🙏\n— Jahongir Travel";
    }

    private function waPrivateYurtRoute(string $firstName, string $tourTitle, string $dateLabel): string
    {
        return "Hi {$firstName},\n\nA few details for your private yurt camp tour on {$dateLabel}:\n\n1️⃣ ROUTE: Your booking is Samarkand → Bukhara. Want to change to Samarkand → Samarkand (return)? Just let us know!\n\n2️⃣ PICKUP: What's your hotel in Samarkand?\n\n3️⃣ DROP-OFF: If going to Bukhara, what's your hotel there?\n\nJust reply here! 🙏\n— Jahongir Travel";
    }

    // ── Email message builders ─────────────────────────────

    private function emailConfirmation(string $firstName, string $tourTitle, string $dateLabel, ?string $ref, int $pax): string
    {
        return implode("\n", [
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
    }

    private function emailPickupRequest(string $firstName, string $tourTitle, string $dateLabel): string
    {
        return implode("\n", [
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
    }

    private function emailPrivateYurtRoute(string $firstName, string $tourTitle, string $dateLabel): string
    {
        return implode("\n", [
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
    }
}
