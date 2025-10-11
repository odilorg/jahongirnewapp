<?php

namespace App\Services;

use Carbon\Carbon;

class ResponseFormatterService
{
    public function formatAvailabilityResponse(array $availabilityData, string $checkInDate, string $checkOutDate): string
    {
        $checkIn = Carbon::parse($checkInDate)->format('M d Y');
        $checkOut = Carbon::parse($checkOutDate)->format('M d Y');
        
        $message = "*🏨 Room Availability Report*\n\n";
        $message .= "📅 *Check-in:* {$checkIn}\n";
        $message .= "📅 *Check-out:* {$checkOut}\n";
        $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";

        if (empty($availabilityData) || !isset($availabilityData['data'])) {
            return $message . "❌ No availability data found.";
        }

        $data = $availabilityData['data'];

        foreach ($data as $hotelId => $hotel) {
            $hotelName = $hotel['hotel_name'] ?? 'Unknown Hotel';
            $message .= "*{$hotelName}*\n";
            
            if (empty($hotel['available_rooms'])) {
                $message .= "   ❌ No rooms available\n\n";
                continue;
            }

            foreach ($hotel['available_rooms'] as $room) {
                $roomName = $room['name'] ?? 'Unknown Room';
                $available = $room['available_qty'] ?? 0;
                $total = $room['total_qty'] ?? 0;
                $switchingRequired = $room['switching_required'] ?? false;

                $message .= "   🛏️ *{$roomName}*\n";
                $message .= "      Available: {$available}/{$total}\n";
                
                if ($switchingRequired) {
                    $message .= "      ⚠️ Room switching required\n";
                }
                
                $message .= "\n";
            }

            $message .= "━━━━━━━━━━━━━━━━━━━━\n\n";
        }

        return $message;
    }

    public function formatErrorMessage(string $error): string
    {
        return "❌ *Error*\n\nSorry, I encountered an error:\n_{$error}_\n\nPlease try again or contact support.";
    }

    public function formatWelcomeMessage(): string
    {
        return "👋 *Welcome to Hotel Availability Bot!*\n\n"
            . "I can help you check room availability for your dates.\n\n"
            . "Just send me a message like:\n"
            . "• \"Check availability for December 25-27\"\n"
            . "• \"Rooms for next week, 3 nights\"\n"
            . "• \"Is there availability from 10th to 15th Jan?\"\n\n"
            . "I'll find the best available rooms for you! 🏨";
    }
}
