<?php

namespace App\Observers;

use App\Models\Booking;
use App\Models\Driver;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingObserver
{
    const OWNER_CHAT_ID      = '38738713';
    const DRIVER_GUIDE_TOKEN = null; // resolved in constructor

    private ?string $botToken;
    private Client  $http;

    public function __construct()
    {
        $this->botToken = config('services.driver_guide_bot.token', env('TELEGRAM_BOT_TOKEN_DRIVER_GUIDE'));
        $this->http     = new Client(['base_uri' => 'https://api.telegram.org']);
    }

    /**
     * Fires when a booking is updated.
     * Checks if driver_id changed → validates availability → alerts owner if conflict.
     */
    public function updated(Booking $booking): void
    {
        // Only care if driver_id changed
        if (!$booking->wasChanged('driver_id')) {
            return;
        }

        $newDriverId = $booking->driver_id;
        if (!$newDriverId) {
            return; // driver was unassigned — no check needed
        }

        $driver  = Driver::find($newDriverId);
        if (!$driver) return;

        $tourDate = Carbon::parse($booking->booking_start_date_time)->toDateString();

        // Check availability
        $availability = DB::table('driver_availability')
            ->where('driver_id', $newDriverId)
            ->where('available_date', $tourDate)
            ->value('is_available');

        // Available = explicitly marked true
        if ($availability === 1 || $availability === true) {
            Log::info('BookingObserver: driver available', [
                'driver_id'  => $newDriverId,
                'booking_id' => $booking->id,
                'date'       => $tourDate,
            ]);
            return; // all good
        }

        // Not available (either marked ❌ or not set = unavailable by default)
        $status  = ($availability === 0 || $availability === false)
            ? "❌ Band deb belgilagan"
            : "⚪ Jadvalda ko'rsatmagan (default: band)";

        $driverName   = trim("{$driver->first_name} {$driver->last_name}");
        $dateLabel    = Carbon::parse($tourDate)->format('D, d M Y');
        $bookingRef   = $booking->booking_number ?? "ID #{$booking->id}";

        $message = implode("\n", [
            "⚠️ <b>Haydovchi konflikt!</b>",
            "",
            "📋 Buyurtma: <b>{$bookingRef}</b>",
            "📅 Sana: <b>{$dateLabel}</b>",
            "🚗 Haydovchi: <b>{$driverName}</b>",
            "📌 Holat: {$status}",
            "",
            "Haydovchini almashtirish yoki u bilan tekshirish kerak!",
        ]);

        $this->sendTelegramAlert($message);

        Log::warning('BookingObserver: driver conflict on assignment', [
            'driver_id'    => $newDriverId,
            'driver_name'  => $driverName,
            'booking_id'   => $booking->id,
            'booking_ref'  => $bookingRef,
            'date'         => $tourDate,
            'availability' => $availability,
        ]);
    }

    private function sendTelegramAlert(string $text): void
    {
        if (empty($this->botToken)) return;
        try {
            $this->http->post("/bot{$this->botToken}/sendMessage", [
                'json' => [
                    'chat_id'    => self::OWNER_CHAT_ID,
                    'text'       => $text,
                    'parse_mode' => 'HTML',
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('BookingObserver: Telegram alert failed', ['error' => $e->getMessage()]);
        }
    }
}
