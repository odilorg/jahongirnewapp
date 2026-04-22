<?php

declare(strict_types=1);

namespace App\Services\BookingBot;

use App\Support\BookingBot\LogSanitizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use OpenAI;

/**
 * Infrastructure adapter: sends the bot-intent prompt to DeepSeek via
 * the OpenAI-compatible SDK and normalizes the response into the
 * existing parsed-intent array shape.
 *
 * Phase 10.4 extraction — behavior is byte-identical to the previous
 * BookingIntentParser body before the coordinator/strategy split.
 * No retry, no timeout bump: those are deferred to Phase 10.6 per
 * scope lock.
 */
/**
 * Not `final` so the coordinator's unit test can Mockery-mock it
 * without an IntentParserInterface. Per Phase 10.4 architect plan,
 * YAGNI: two concrete strategies + coordinator composition beats an
 * interface that would exist only for tests. Revisit when a third
 * strategy (retry wrapper) appears.
 */
class DeepSeekIntentParser
{
    /**
     * @throws IntentParseException on network failure, JSON decode
     *         failure, or missing required fields.
     *
     * @return array<string, mixed>
     */
    public function parse(string $message): array
    {
        $systemPrompt = $this->buildPrompt();

        try {
            $client = OpenAI::factory()
                ->withApiKey(config('openai.api_key'))
                ->withBaseUri(config('openai.base_url'))
                ->withHttpClient(new \GuzzleHttp\Client(['timeout' => config('openai.request_timeout')]))
                ->make();

            $response = $client->chat()->create([
                'model' => 'deepseek-chat',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $message],
                ],
                'temperature' => 0.1,
                'max_tokens'  => 500,
            ]);

            $content = $response->choices[0]->message->content ?? '';

            Log::info('DeepSeek Booking Intent Response', LogSanitizer::context(['content' => $content]));

            // DeepSeek occasionally wraps JSON in a markdown code fence.
            $content = trim($content);
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
            $content = preg_replace('/\s*```\s*$/', '', $content) ?? $content;

            $parsed = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new IntentParseException('Failed to parse DeepSeek response: ' . json_last_error_msg());
            }

            if (!isset($parsed['intent'])) {
                throw new IntentParseException('Intent not found in parsed response');
            }

            return $parsed;
        } catch (IntentParseException $e) {
            Log::error('Booking Intent Parse Error', LogSanitizer::context([
                'message' => $message,
                'error'   => $e->getMessage(),
            ]));
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Booking Intent Parse Error', LogSanitizer::context([
                'message' => $message,
                'error'   => $e->getMessage(),
            ]));
            // Sanitize transport-layer errors (cURL messages, URLs) so
            // upstream never surfaces raw network noise to operators.
            throw new IntentParseException('Intent parser unavailable: ' . $e->getMessage(), 0, $e);
        }
    }

    private function buildPrompt(): string
    {
        $currentDate = Carbon::now()->toDateString();

        return <<<PROMPT
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
  "filter_type": "arrivals_today|departures_today|current|new|arrivals|departures|today",
  "search_string": "guest name to search",
  "notes": "special requests",
  "charge": {
    "price_per_night": 80,
    "currency": "USD"
  }
}

Charge rules:
- Only emit "charge" for create_booking intents.
- "price_per_night" is a number (no currency symbol in the value).
- "currency" is one of USD, UZS, EUR. Omit the "charge" key entirely if
  the operator did not state a price.
- If the operator says "total 200" without per-night, still omit
  "charge" (v1 does not support total-only input).
- For multi-room bookings (rooms[]), a single "charge" applies to EVERY
  room (per-room per-night). Do not split or divide. Emit one "charge"
  block, not one per room.

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

- "bookings today"
  → intent: view_bookings, filter_type: "today"

- "bookings on may 5" or "who is staying on may 5"
  → intent: view_bookings, dates: {check_in: "<current or next year>-05-05", check_out: "<same>-05-05"}

- "bookings may 5-10"
  → intent: view_bookings, dates: {check_in: "<current or next year>-05-05", check_out: "<same>-05-10"}

- "arrivals may 5-10"
  → intent: view_bookings, filter_type: "arrivals", dates: {check_in: "<current or next year>-05-05", check_out: "<same>-05-10"}

- "departures next week"
  → intent: view_bookings, filter_type: "departures", dates: {check_in: "<next monday YYYY-MM-DD>", check_out: "<next sunday YYYY-MM-DD>"}

IMPORTANT for view_bookings date parsing:
- If the user mentions a month without a year, pick the NEXT occurrence
  of that month (today or later). Never pick a past year.
- If only one date is given ("may 5"), set BOTH check_in and check_out
  to that date — a single-day range.

- "modify booking 12345 to jan 5-7"
  → intent: modify_booking, booking_id: "12345", dates: jan 5-7

- "book room 12 under John Walker jan 2-3 tel +123 at 80 usd/night"
  → intent: create_booking, room.unit_name: "12", guest.name: "John Walker",
    dates: jan 2-3, charge: {price_per_night: 80, currency: "USD"}
PROMPT;
    }
}
