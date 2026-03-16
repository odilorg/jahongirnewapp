<?php

namespace App\Console\Commands;

use App\Http\Controllers\TelegramDriverGuideSignUpController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendBookingNotification extends Command
{
    protected $signature   = 'booking:notify {booking_id : The booking ID to notify driver and partner about}';
    protected $description = 'Send booking confirmation request to assigned driver and partner (Yurt Camp etc)';

    public function handle(): int
    {
        $bookingId = (int) $this->argument('booking_id');

        $booking = DB::table('bookings')
            ->join('guests', 'bookings.guest_id', '=', 'guests.id')
            ->join('tours',  'bookings.tour_id',  '=', 'tours.id')
            ->where('bookings.id', $bookingId)
            ->select([
                'bookings.id',
                'bookings.booking_number',
                'bookings.driver_id',
                'bookings.tour_id',
                'bookings.booking_status',
                'guests.first_name',
                'guests.last_name',
                'tours.title as tour_title',
            ])
            ->first();

        if (!$booking) {
            $this->error("Booking #{$bookingId} not found.");
            return self::FAILURE;
        }

        $this->info("Booking: {$booking->booking_number} — {$booking->first_name} {$booking->last_name} — {$booking->tour_title}");

        $controller = app(TelegramDriverGuideSignUpController::class);

        // ── Notify driver ──────────────────────────────────────────────────
        if ($booking->driver_id) {
            $driver = DB::table('drivers')->where('id', $booking->driver_id)->first();
            if ($driver?->telegram_chat_id) {
                $controller->sendDriverBookingRequest($bookingId);
                $this->info("✅ Driver request sent → {$driver->first_name} {$driver->last_name}");
            } else {
                $this->warn("⚠ Driver has no Telegram — skipped.");
            }
        } else {
            $this->warn("⚠ No driver assigned — skipped.");
        }

        // ── Notify partner ─────────────────────────────────────────────────
        $partner = DB::table('partners')
            ->whereJsonContains('tour_ids', (int) $booking->tour_id)
            ->whereNotNull('telegram_chat_id')
            ->first();

        if ($partner) {
            $controller->sendPartnerBookingRequest($bookingId);
            $this->info("✅ Partner request sent → {$partner->name}");
        } else {
            $this->info("ℹ No partner registered for this tour — skipped.");
        }

        return self::SUCCESS;
    }
}
