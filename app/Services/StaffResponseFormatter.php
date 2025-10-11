<?php

namespace App\Services;

use Carbon\Carbon;

class StaffResponseFormatter
{
    /**
     * Format booking confirmation
     */
    public function formatBookingConfirmation(array $booking): string
    {
        $checkIn = Carbon::parse($booking['check_in'])->format('M j, Y');
        $checkOut = Carbon::parse($booking['check_out'])->format('M j, Y');
        
        return "✅ *Booking Created*\n" .
               "━━━━━━━━━━━━━━━━\n" .
               "*ID:* `#{$booking['id']}`\n" .
               "*Guest:* {$booking['guest_name']}\n" .
               "*Room:* {$booking['room_name']}" . 
               (isset($booking['unit_name']) ? " (Unit {$booking['unit_name']})" : "") . "\n" .
               "*Property:* {$booking['property_name']}\n" .
               "*Check-in:* {$checkIn}\n" .
               "*Check-out:* {$checkOut}\n" .
               "*Phone:* {$booking['guest_phone']}\n" .
               "*Email:* {$booking['guest_email']}\n" .
               (isset($booking['price']) ? "*Price:* \${$booking['price']}\n" : "") .
               "*Status:* Confirmed ✓";
    }

    /**
     * Format availability results
     */
    public function formatAvailability(array $data, string $checkIn, string $checkOut): string
    {
        $checkInFormatted = Carbon::parse($checkIn)->format('M j');
        $checkOutFormatted = Carbon::parse($checkOut)->format('M j');
        $nights = Carbon::parse($checkIn)->diffInDays(Carbon::parse($checkOut));
        
        $message = "✅ *Available {$checkInFormatted}-{$checkOutFormatted}* ({$nights} night" . ($nights > 1 ? 's' : '') . ")\n\n";

        foreach ($data['data'] ?? [] as $propertyId => $property) {
            $message .= "*{$property['hotel_name']}*\n";
            
            foreach ($property['available_rooms'] ?? [] as $room) {
                if ($room['available_qty'] > 0) {
                    $message .= "• {$room['name']} - \${$room['price']}/night";
                    
                    if ($room['available_qty'] > 1) {
                        $message .= " (x{$room['available_qty']})";
                    }
                    
                    $message .= "\n";
                }
            }
            
            $message .= "\n";
        }

        return rtrim($message);
    }

    /**
     * Format error message
     */
    public function formatError(string $message): string
    {
        return "❌ *Error*\n\n{$message}";
    }

    /**
     * Format cancellation confirmation
     */
    public function formatCancellation(string $bookingId): string
    {
        return "✅ *Booking Cancelled*\n\n" .
               "Booking ID: `#{$bookingId}` has been cancelled successfully.";
    }

    /**
     * Format today's bookings
     */
    public function formatTodaysBookings(array $bookings): string
    {
        $today = Carbon::today()->format('M j');
        
        if (empty($bookings)) {
            return "📅 *Today's Bookings ({$today})*\n\nNo bookings for today.";
        }

        $message = "📅 *Today's Bookings ({$today})*\n\n";
        
        foreach ($bookings as $index => $booking) {
            $num = $index + 1;
            $status = $booking['booking_status'] === 'checked_in' ? '✓ Checked-in' : 
                     ($booking['check_in_date'] == Carbon::today() ? 'Check-in' : 'Check-out');
            
            $message .= "{$num}. `#{$booking['beds24_booking_id']}` - {$booking['guest_name']} - " .
                       "{$booking['room_name']}" .
                       (isset($booking['unit_name']) ? " (Unit {$booking['unit_name']})" : "") .
                       " - {$status}\n";
        }

        $message .= "\nTotal: " . count($bookings) . " booking" . (count($bookings) > 1 ? 's' : '');

        return $message;
    }

    /**
     * Format help message
     */
    public function formatHelp(): string
    {
        return "*📖 Booking Bot Commands*\n\n" .
               "*Check Availability:*\n" .
               "• `check avail jan 2-3`\n" .
               "• `availability for weekend`\n\n" .
               "*Create Booking:*\n" .
               "• `book room 12 under John Walker jan 2-3 tel +1234567890 email ok@ok.com`\n" .
               "• `create booking room 22 guest Jane Doe jan 5-7 +998901234567`\n\n" .
               "*View Bookings:*\n" .
               "• `today's bookings`\n" .
               "• `bookings for tomorrow`\n\n" .
               "*Modify Booking:*\n" .
               "• `modify booking 12345 checkout jan 5`\n\n" .
               "*Cancel Booking:*\n" .
               "• `cancel booking 12345`";
    }
}
