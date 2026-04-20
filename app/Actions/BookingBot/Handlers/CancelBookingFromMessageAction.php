<?php

namespace App\Actions\BookingBot\Handlers;

use App\Models\User;
use App\Services\Beds24BookingService;
use Illuminate\Support\Facades\Log;

/**
 * Handles "cancel booking #id" intent from @j_booking_hotel_bot.
 *
 * Pure extraction from ProcessBookingMessage::handleCancelBooking. Behaviour
 * must be byte-identical — the golden master asserts this.
 *
 * $staff is the acting User (called "staff" in the original for Telegram-bot
 * semantics). It's used only to stamp the cancellation reason passed to Beds24.
 */
final class CancelBookingFromMessageAction
{
    public function __construct(
        private readonly Beds24BookingService $beds24,
    ) {}

    public function execute(array $parsed, User $staff): string
    {
        $bookingId = $parsed['booking_id'] ?? null;

        // Fallback: some intent parses lose the ID; re-scan the raw message.
        if (!$bookingId && isset($parsed['_raw_message'])) {
            if (preg_match('/#?(\d+)/', $parsed['_raw_message'], $matches)) {
                $bookingId = $matches[1];
            }
        }

        if (!$bookingId) {
            return "Please provide a booking ID to cancel.\n\n" .
                   "Example: cancel booking #123456\n" .
                   "Or: cancel booking 123456";
        }

        try {
            Log::info('Fetching booking details before cancellation', ['booking_id' => $bookingId]);

            // Best-effort details fetch so the confirmation can echo room/guest/dates.
            // Not fatal if it fails — cancellation still proceeds.
            $bookingDetails = null;
            try {
                $getResult = $this->beds24->getBooking($bookingId);
                if (isset($getResult['data']) && !empty($getResult['data'])) {
                    $bookingDetails = $getResult['data'][0] ?? $getResult['data'];
                }
            } catch (\Exception $e) {
                Log::warning('Could not fetch booking details', ['error' => $e->getMessage()]);
            }

            Log::info('Cancelling booking', [
                'booking_id' => $bookingId,
                'staff' => $staff->name,
            ]);

            $reason = 'Cancelled by ' . $staff->name . ' via Telegram Bot';
            $result = $this->beds24->cancelBooking($bookingId, $reason);

            Log::info('Cancel booking API response', ['result' => $result]);

            // Beds24 API sometimes returns array [{"success": true, ...}] and
            // sometimes implies success by absence of error — handle both.
            $success = false;
            if (is_array($result)) {
                if (isset($result['success']) && $result['success']) {
                    $success = true;
                } elseif (isset($result[0]['success']) && $result[0]['success']) {
                    $success = true;
                } elseif (isset($result[0]) && !isset($result[0]['error'])) {
                    $success = true;
                }
            }

            if ($success) {
                $response = "✅ Booking Cancelled Successfully\n\n";
                $response .= "Booking ID: #{$bookingId}\n";

                if ($bookingDetails) {
                    if (isset($bookingDetails['roomName'])) {
                        $response .= "Room: {$bookingDetails['roomName']}\n";
                    }
                    if (isset($bookingDetails['guestName'])) {
                        $response .= "Guest: {$bookingDetails['guestName']}\n";
                    }
                    if (isset($bookingDetails['arrival']) && isset($bookingDetails['departure'])) {
                        $response .= "Dates: {$bookingDetails['arrival']} to {$bookingDetails['departure']}\n";
                    }
                }

                $response .= "\nThe booking has been cancelled in Beds24.";

                return $response;
            }

            $errorMsg = 'Unknown error';
            if (isset($result['error'])) {
                $errorMsg = $result['error'];
            } elseif (isset($result[0]['error'])) {
                $errorMsg = $result[0]['error'];
            }

            throw new \Exception($errorMsg);

        } catch (\Exception $e) {
            Log::error('Booking cancellation failed', [
                'booking_id' => $bookingId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return "❌ Booking Cancellation Failed\n\n" .
                   "Booking ID: #{$bookingId}\n" .
                   "Error: {$e->getMessage()}\n\n" .
                   "Please check the booking ID and try again, or cancel manually in Beds24.";
        }
    }
}
