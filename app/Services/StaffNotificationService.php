<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\Models\Booking;
use App\Models\Driver;
use App\Models\Guide;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Sends Telegram notifications to drivers and guides when they are assigned to a booking.
 *
 * Uses the 'driver-guide' bot (same as GygNotifier).
 * Failures are logged and NEVER propagate to the caller — assignment must always succeed.
 */
class StaffNotificationService
{
    public function __construct(
        private readonly BotResolverInterface      $resolver,
        private readonly TelegramTransportInterface $transport,
    ) {}

    /**
     * Notify a driver they have been assigned to a booking.
     * Silently no-ops if the driver has no telegram_chat_id.
     */
    public function notifyDriverAssigned(Driver $driver, Booking $booking): void
    {
        if (empty($driver->telegram_chat_id)) {
            return;
        }

        $message = $this->buildAssignmentMessage('🚗 Driver Assignment', $driver->phone01, $booking);

        $this->send((int) $driver->telegram_chat_id, $message, "driver #{$driver->id}");
    }

    /**
     * Notify a guide they have been assigned to a booking.
     * Silently no-ops if the guide has no telegram_chat_id.
     */
    public function notifyGuideAssigned(Guide $guide, Booking $booking): void
    {
        if (empty($guide->telegram_chat_id)) {
            return;
        }

        $message = $this->buildAssignmentMessage('🧭 Guide Assignment', $guide->phone01, $booking);

        $this->send((int) $guide->telegram_chat_id, $message, "guide #{$guide->id}");
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildAssignmentMessage(string $heading, string $contactPhone, Booking $booking): string
    {
        $date    = Carbon::parse($booking->booking_start_date_time)->format('d M Y');
        $time    = Carbon::parse($booking->booking_start_date_time)->format('H:i');
        $pax     = $booking->guest?->number_of_people ?? '?';
        $pickup  = $booking->pickup_location ?? 'TBD';
        $ref     = $booking->booking_number ?? "#{$booking->id}";
        $guest   = $booking->guest?->full_name ?? 'Guest';
        $phone   = $booking->guest?->phone ?? '';

        $lines = [
            $heading,
            '',
            "Booking: {$ref}",
            "Date: {$date} at {$time}",
            "Guests: {$pax} pax",
            '',
            "Pickup: {$pickup}",
            "Guest: {$guest}",
        ];

        if ($phone !== '') {
            $lines[] = "Contact: {$phone}";
        }

        return implode("\n", $lines);
    }

    private function send(int $chatId, string $text, string $context): void
    {
        try {
            $bot    = $this->resolver->resolve('driver-guide');
            $result = $this->transport->sendMessage($bot, $chatId, $text);

            if (! $result->succeeded()) {
                Log::warning("StaffNotificationService: send to {$context} returned non-ok", [
                    'chat_id' => $chatId,
                    'status'  => $result->httpStatus,
                ]);
            }
        } catch (\Throwable $e) {
            // Never let notification failure break the assignment.
            Log::error("StaffNotificationService: failed to notify {$context}", [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
