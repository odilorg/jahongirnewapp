<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use OpenAI;
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
1. Identify the intent (check_availability, create_booking, modify_booking, cancel_booking, view_bookings, record_payment)
2. Extract dates in YYYY-MM-DD format
3. Extract room identifiers (unit names like "12", "22" or room types like "double")
4. Extract guest information (name, phone, email)
5. Extract property name if mentioned
6. Extract optional quoted price for create_booking
7. Extract payment details for record_payment

Output ONLY valid JSON, no additional text:
{
  "intent": "check_availability|create_booking|modify_booking|cancel_booking|view_bookings|record_payment",
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
  "price": null,
  "payment": {
    "amount": null,
    "currency": "USD",
    "method": null
  },
  "filter_type": "arrivals_today|departures_today|current|new",
  "search_string": "guest name to search",
  "notes": "special requests"
}

IMPORTANT — intent rules:
- "price" on create_booking = the quoted total the guest will be charged (not yet paid). Extract ONLY if explicitly stated. Set null if not mentioned. Do NOT invent a price.
- "record_payment" is a SEPARATE intent, ONLY when the operator states that money was already received against an existing booking. It ALWAYS requires a booking_id.
- These two intents are mutually exclusive. Never combine them.

Property names:
- "Jahongir Hotel" or "Hotel" or "jahongir hotel" → property: "jahongir_hotel"
- "Jahongir Premium" or "Premium" or "jahongir premium" → property: "jahongir_premium"

Payment method values: "cash", "card", "transfer", or null if not specified.

Examples:
- "book room 12 under John Walker jan 2-3 tel +1234567890 email ok@ok.com"
  → intent: create_booking, room.unit_name: "12", guest.name: "John Walker", dates: jan 2-3, price: null

- "book room 12 for $100 under John Walker jan 2-3 tel +123"
  → intent: create_booking, room.unit_name: "12", price: 100, guest.name: "John Walker"

- "book rooms 12 and 14 total 220 USD under John jan 5-7 tel +123"
  → intent: create_booking, rooms: [{unit_name: "12"}, {unit_name: "14"}], price: 220

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

- "record payment 100 for booking 123456"
  → intent: record_payment, booking_id: "123456", payment: {amount: 100, currency: "USD", method: null}

- "mark booking #123456 paid 150 cash"
  → intent: record_payment, booking_id: "123456", payment: {amount: 150, currency: "USD", method: "cash"}

- "received 200 USD card for booking 123456"
  → intent: record_payment, booking_id: "123456", payment: {amount: 200, currency: "USD", method: "card"}

- "view today's arrivals" or "show arrivals today" or "today arrivals"
  → intent: view_bookings, filter_type: "arrivals_today"

- "view today's departures" or "show departures today" or "today departures"
  → intent: view_bookings, filter_type: "departures_today"

- "view current bookings" or "show current" or "in-house guests"
  → intent: view_bookings, filter_type: "current"

- "view new bookings" or "show new" or "unconfirmed bookings"
  → intent: view_bookings, filter_type: "new"

- "search for John Smith" or "find booking John Smith"
  → intent: view_bookings, search_string: "John Smith"

- "modify booking 12345 to jan 5-7"
  → intent: modify_booking, booking_id: "12345", dates: jan 5-7
PROMPT;

        try {
            // Create OpenAI client with custom base URL for DeepSeek
            $client = OpenAI::factory()
                ->withApiKey(config('openai.api_key'))
                ->withBaseUri(config('openai.base_url'))
                ->withHttpClient(new \GuzzleHttp\Client(['timeout' => config('openai.request_timeout')]))
                ->make();

            $response = $client->chat()->create([
                'model' => 'deepseek-chat',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $message]
                ],
                'temperature' => 0.1,
                'max_tokens' => 500,
            ]);

            $content = $response->choices[0]->message->content ?? '';
            
            Log::info('DeepSeek Booking Intent Response', ['content' => $content]);

            // Parse JSON response
            $parsed = json_decode($content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to parse DeepSeek response: ' . json_last_error_msg());
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
            'view_bookings',
            'record_payment',
        ];

        return isset($parsed['intent']) && in_array($parsed['intent'], $validIntents);
    }
}
