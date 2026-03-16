<?php

namespace App\Console\Commands;

use App\Http\Controllers\TelegramDriverGuideSignUpController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                'bookings.booking_start_date_time',
                'guests.first_name',
                'guests.last_name',
                'guests.phone',
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

            // Ask guest dietary requirements via WhatsApp (Yurt Camp needs to prepare lunch)
            $this->sendDietaryQuestion($booking);
        } else {
            $this->info("ℹ No partner registered for this tour — skipped.");
        }

        return self::SUCCESS;
    }

    private function sendDietaryQuestion(object $booking): void
    {
        $phone = trim($booking->phone ?? '');
        if (empty($phone)) {
            $this->warn("⚠ No guest phone — dietary question skipped.");
            return;
        }

        // Normalize phone to E.164
        $phone = preg_replace('/[\s\-().]+/', '', $phone);
        if (str_starts_with($phone, '00')) $phone = '+' . substr($phone, 2);
        if (!str_starts_with($phone, '+')) $phone = '+' . $phone;

        $jid     = ltrim($phone, '+') . '@s.whatsapp.net';
        $name    = $booking->first_name;
        $message = implode("\n", [
            "Hi {$name}! 👋",
            "",
            "We're preparing your Yurt Camp experience and want to make sure everything is perfect for you.",
            "",
            "🍽 Do you have any dietary requirements?",
            "(vegetarian, vegan, allergies, halal, etc.)",
            "",
            "Please let us know so we can inform the camp in advance.",
            "",
            "— Jahongir Travel",
        ]);

        $this->info("  🍽 Sending dietary question → {$phone} ({$name})");

        exec('pm2 stop wacli-sync 2>&1');

        $output     = [];
        $returnCode = 0;
        exec('wacli send text --to ' . escapeshellarg($jid) . ' --message ' . escapeshellarg($message) . ' 2>&1',
            $output, $returnCode);

        exec('pm2 start wacli-sync 2>&1');

        if ($returnCode === 0) {
            $this->info("  ✅ Dietary question sent to {$name}");
            Log::info('SendBookingNotification: dietary question sent', [
                'booking_id' => $booking->id,
                'phone'      => $phone,
            ]);
        } else {
            $this->warn("  ⚠ Failed to send dietary question: " . implode(' ', $output));
        }
    }
}
