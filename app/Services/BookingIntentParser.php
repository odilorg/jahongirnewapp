<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;
use Carbon\Carbon;

class BookingIntentParser
{
    /**
     * Parse natural language booking request into structured data
     */
    public function parse(string $message): array
    {
        $currentDate = Carbon::now()->toDateString();
        
        $systemPrompt = <<<PROMPT
You are a booking intent parser for a hotel staff member. Extract structured data from natural language booking commands.

Today's date is: {$currentDate}

Your task:
1. Identify the intent (check_availability, create_booking, modify_booking, cancel_booking, view_bookings)
2. Extract dates in YYYY-MM-DD format
3. Extract room identifiers (unit names like "12", "22" or room types like "double")
4. Extract guest information (name, phone, email)
5. Extract property name if mentioned

Output ONLY valid JSON, no additional text:
{
  "intent": "check_availability|create_booking|modify_booking|cancel_booking|view_bookings",
  "confidence": 0.0-1.0,
  "dates": {
    "check_in": "YYYY-MM-DD",
    "check_out": "YYYY-MM-DD"
  },
  "room": {
    "unit_name": "12",
    "room_type": "double"
  },
  "rooms": [
    {"unit_name": "12", "property": "jahongir_hotel"},
    {"unit_name": "14", "property": "jahongir_premium"}
  ],
  "guest": {
    "name": "Full Name",
    "phone": "+1234567890",
    "email": "email@example.com"
  },
  "property": "jahongir",
  "booking_id": "12345",
  "notes": "special requests"
}

Property names:
- "Jahongir Hotel" or "Hotel" or "jahongir hotel" → property: "jahongir_hotel"
- "Jahongir Premium" or "Premium" or "jahongir premium" → property: "jahongir_premium"

Examples:
- "book room 12 under John Walker jan 2-3 tel +1234567890 email ok@ok.com"
  → intent: create_booking, room.unit_name: "12", guest.name: "John Walker", dates: jan 2-3
  
- "book room 12 at Premium under John Walker jan 2-3 tel +123"
  → intent: create_booking, room.unit_name: "12", property: "jahongir_premium", guest.name: "John Walker"
  
- "book room 14 at Hotel under Jane Doe jan 5-6 tel +456"
  → intent: create_booking, room.unit_name: "14", property: "jahongir_hotel", guest.name: "Jane Doe"
  
- "check avail jan 5-7"
  → intent: check_availability, dates: jan 5-7
  
- "book rooms 12 and 14 under John Walker jan 5-7 tel +123"
  → intent: create_booking, rooms: [{unit_name: "12"}, {unit_name: "14"}]
  
- "cancel booking 12345"
  → intent: cancel_booking, booking_id: "12345"
PROMPT;

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $message]
                ],
                'temperature' => 0.1,
                'max_tokens' => 500,
            ]);

            $content = $response->choices[0]->message->content ?? '';
            
            Log::info('OpenAI Booking Intent Response', ['content' => $content]);

            // Parse JSON response
            $parsed = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to parse OpenAI response: ' . json_last_error_msg());
            }

            // Validate required fields
            if (!isset($parsed['intent'])) {
                throw new \Exception('Intent not found in parsed response');
            }

            return $parsed;

        } catch (\Exception $e) {
            Log::error('Booking Intent Parse Error', [
                'message' => $message,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('Failed to parse booking intent: ' . $e->getMessage());
        }
    }

    /**
     * Validate parsed intent
     */
    public function validate(array $parsed): bool
    {
        $validIntents = [
            'check_availability',
            'create_booking',
            'modify_booking',
            'cancel_booking',
            'view_bookings'
        ];

        return isset($parsed['intent']) && in_array($parsed['intent'], $validIntents);
    }
}
