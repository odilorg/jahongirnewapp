<?php

namespace App\Observers;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\Models\Booking;
use App\Models\Driver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingObserver
{
    const OWNER_CHAT_ID = '38738713';

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
            return;
        }

        $driver = Driver::find($newDriverId);
        if (!$driver) {
            return;
        }

        $tourDate = $booking->tour_date ?? ($booking->tour?->date ?? null);
        if (!$tourDate) {
            return;
        }

        $dateStr = $tourDate instanceof Carbon ? $tourDate->toDateString() : (string) $tourDate;

        // Check availability from driver_availability_dates
        $availability = DB::table('driver_availability_dates')
            ->where('driver_id', $newDriverId)
            ->where('date', $dateStr)
            ->value('status');

        if ($availability === 'unavailable') {
            $driverName = $driver->name ?? "Driver #{$newDriverId}";
            $text = "⚠️ <b>Conflict Alert</b>\n\n"
                . "Driver <b>{$driverName}</b> was assigned to booking #{$booking->id}\n"
                . "Date: {$dateStr}\n"
                . "But driver is marked as <b>UNAVAILABLE</b> on this date.\n\n"
                . "Please verify and reassign if needed.";

            $this->sendTelegramAlert($text);
        }

        Log::info('BookingObserver: driver assigned', [
            'booking_id'   => $booking->id,
            'driver_id'    => $newDriverId,
            'date'         => $tourDate,
            'availability' => $availability,
        ]);
    }

    private function sendTelegramAlert(string $text): void
    {
        try {
            $resolver = app(BotResolverInterface::class);
            $transport = app(TelegramTransportInterface::class);
            $bot = $resolver->resolve('driver-guide');
            $transport->sendMessage($bot, self::OWNER_CHAT_ID, $text, ['parse_mode' => 'HTML']);
        } catch (\Throwable $e) {
            Log::error('BookingObserver: Telegram alert failed', ['error' => $e->getMessage()]);
        }
    }
}
