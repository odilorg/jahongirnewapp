<?php

namespace App\Services;

use OpenAI;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class GetYourGuideDataExtractorService
{
    protected $client;

    public function __construct()
    {
        $this->client = OpenAI::factory()
            ->withApiKey(config('openai.api_key'))
            ->withBaseUri(config('openai.base_url'))
            ->withHttpClient(new \GuzzleHttp\Client(['timeout' => 30]))
            ->make();
    }

    /**
     * Extract booking data from email using AI
     */
    public function extractBookingData(string $emailBody, string $emailSubject): array
    {
        $startTime = microtime(true);

        try {
            // Build prompts
            $systemPrompt = $this->buildSystemPrompt();
            $userPrompt = $this->buildUserPrompt($emailBody, $emailSubject);

            // Call DeepSeek API
            $aiResponse = $this->callDeepSeek($systemPrompt, $userPrompt);

            // Parse and validate response
            $extractedData = $this->parseAIResponse($aiResponse);

            if (!$this->validateExtractedData($extractedData)) {
                throw new \Exception('Extracted data failed validation');
            }

            // Normalize data
            $normalizedData = $this->normalizeData($extractedData);

            $processingTime = round((microtime(true) - $startTime) * 1000);

            Log::info('GetYourGuide Data Extractor: Successfully extracted booking data', [
                'processing_time_ms' => $processingTime,
                'booking_reference' => $normalizedData['booking_reference'] ?? 'unknown',
            ]);

            return [
                'success' => true,
                'data' => $normalizedData,
                'processing_time_ms' => $processingTime,
                'ai_raw_response' => $aiResponse,
            ];

        } catch (\Exception $e) {
            Log::error('GetYourGuide Data Extractor: Extraction failed', [
                'error' => $e->getMessage(),
                'subject' => $emailSubject,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'ai_raw_response' => $aiResponse ?? null,
            ];
        }
    }

    /**
     * Build comprehensive system prompt for AI
     */
    protected function buildSystemPrompt(): string
    {
        $currentDate = Carbon::now(config('getyourguide.timezone'))->toDateString();
        $currentTime = Carbon::now(config('getyourguide.timezone'))->format('H:i');

        return <<<PROMPT
# Role
You are a GetYourGuide booking data extraction specialist. Your goal is to extract structured booking information from GetYourGuide confirmation emails with 100% accuracy.

# Context
- Today's date: {$currentDate}
- Current time: {$currentTime}
- Timezone: Asia/Samarkand (UTC+5)

# Tasks
Extract the following 19 fields from the booking email:

## Required Fields
1. booking_reference - GetYourGuide reference starting with "GYGG" (e.g., GYGG455V4RN8)
2. tour_name - Full tour title (exact text, don't paraphrase)
3. tour_date - Tour date in YYYY-MM-DD format
4. guest_name - Primary customer name

## Optional Fields
5. booking_date - Date booking was made (YYYY-MM-DD)
6. tour_time - Tour start time (HH:MM 24-hour format)
7. duration - Tour duration (e.g., "4 hours", "Full day")
8. guest_email - Customer email (may be anonymized)
9. guest_phone - Phone with country code (e.g., +33783356396)
10. adults - Number of adults (integer)
11. children - Number of children (integer, default 0)
12. number_of_guests - Total guests (adults + children)
13. pickup_location - Full pickup address
14. pickup_time - Pickup time (HH:MM 24-hour)
15. special_requirements - Special requests or notes
16. total_price - Price as decimal number (e.g., 85.00)
17. currency - 3-letter currency code (USD, EUR, GBP)
18. payment_status - "paid", "pending", or "refunded"
19. language - Customer language preference

# Extraction Rules

## Dates
- Convert any format to YYYY-MM-DD
- "November 8, 2025" → "2025-11-08"
- "8 Nov 2025" → "2025-11-08"
- "08/11/2025" → "2025-11-08"

## Times
- Convert to HH:MM 24-hour format
- "8:00 AM" → "08:00"
- "2:30 PM" → "14:30"

## Phone Numbers
- Keep international format: +33783356396
- Preserve country code

## Prices
- Extract number only: "$ 85.00" → 85.00
- Use decimal format: 85.00

## Currency
- Convert symbols to codes: $ → USD, € → EUR, £ → GBP
- Always 3 uppercase letters

## Booking Reference
- Must start with "GYGG"
- Uppercase all letters
- Example: GYGG455V4RN8

## Missing Data
- Use null for fields not found
- Don't invent or guess data

## Multi-Language
- Handle English, French, Russian, Spanish, German
- Extract data regardless of language

# Output Format

Output ONLY valid JSON. No markdown, no code blocks, no explanations.

{
  "booking_reference": "string",
  "booking_date": "YYYY-MM-DD or null",
  "tour_name": "string",
  "tour_date": "YYYY-MM-DD",
  "tour_time": "HH:MM or null",
  "duration": "string or null",
  "guest_name": "string",
  "guest_email": "string or null",
  "guest_phone": "string or null",
  "number_of_guests": integer,
  "adults": integer,
  "children": integer,
  "pickup_location": "string or null",
  "pickup_time": "HH:MM or null",
  "special_requirements": "string or null",
  "total_price": decimal,
  "currency": "string",
  "payment_status": "string or null",
  "language": "string or null"
}

# Example

{
  "booking_reference": "GYGG455V4RN8",
  "booking_date": null,
  "tour_name": "From Samarkand: Shahrisabz Private Day Tour",
  "tour_date": "2025-11-08",
  "tour_time": "08:00",
  "duration": null,
  "guest_name": "Nicolas Coriggio",
  "guest_email": "customer-hf4wxd2mxh7gkxi4@reply.getyourguide.com",
  "guest_phone": "+33783356396",
  "number_of_guests": 1,
  "adults": 1,
  "children": 0,
  "pickup_location": "MX4H+G7Q, Ulitsa Tashkentskaya 43, 140100, Samarkand",
  "pickup_time": null,
  "special_requirements": null,
  "total_price": 85.00,
  "currency": "USD",
  "payment_status": "paid",
  "language": "French"
}

# Critical Rules
1. Output ONLY JSON - no other text
2. Dates: YYYY-MM-DD
3. Times: HH:MM (24-hour)
4. Numbers: numeric types
5. Currency: 3-letter uppercase
6. Use null for missing fields
7. Ensure valid JSON
8. Extract exact text
9. Handle multiple languages
10. Don't invent data
PROMPT;
    }

    /**
     * Build user prompt with email content
     */
    protected function buildUserPrompt(string $emailBody, string $subject): string
    {
        return <<<PROMPT
Extract booking data from this GetYourGuide confirmation email:

**Email Subject:**
{$subject}

**Email Body:**
{$emailBody}

Extract all booking information and return as valid JSON only.
PROMPT;
    }

    /**
     * Call DeepSeek API
     */
    protected function callDeepSeek(string $systemPrompt, string $userPrompt): string
    {
        try {
            $response = $this->client->chat()->create([
                'model' => 'deepseek-chat',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt]
                ],
                'temperature' => 0.2,
                'max_tokens' => 1000,
            ]);

            $content = $response['choices'][0]['message']['content'] ?? '';

            if (empty($content)) {
                throw new \Exception('Empty response from DeepSeek API');
            }

            return $content;

        } catch (\Exception $e) {
            Log::error('GetYourGuide Data Extractor: DeepSeek API call failed', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('DeepSeek API call failed: ' . $e->getMessage());
        }
    }

    /**
     * Parse JSON from AI response
     */
    protected function parseAIResponse(string $response): array
    {
        $json = $this->extractJsonFromResponse($response);

        if (!$json) {
            throw new \Exception('No valid JSON found in AI response');
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in AI response: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Extract JSON object from response
     */
    protected function extractJsonFromResponse(string $response): ?string
    {
        // Remove markdown code blocks
        $response = preg_replace('/```json\s*/', '', $response);
        $response = preg_replace('/```\s*/', '', $response);
        $response = trim($response);

        // Try to find JSON object
        if (preg_match('/\{[^}]+\}/s', $response, $matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Validate extracted data
     */
    protected function validateExtractedData(array $data): bool
    {
        // Required fields
        $requiredFields = [
            'booking_reference',
            'tour_name',
            'tour_date',
            'guest_name',
        ];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                Log::warning('GetYourGuide Data Extractor: Missing required field', [
                    'field' => $field,
                ]);
                return false;
            }
        }

        // Validate booking reference format
        if (!preg_match('/^GYGG[A-Z0-9]+$/i', $data['booking_reference'])) {
            Log::warning('GetYourGuide Data Extractor: Invalid booking reference format', [
                'booking_reference' => $data['booking_reference'],
            ]);
            return false;
        }

        // Validate date format
        try {
            Carbon::parse($data['tour_date']);
        } catch (\Exception $e) {
            Log::warning('GetYourGuide Data Extractor: Invalid tour date', [
                'tour_date' => $data['tour_date'],
            ]);
            return false;
        }

        return true;
    }

    /**
     * Normalize extracted data
     */
    protected function normalizeData(array $data): array
    {
        // Uppercase booking reference
        if (isset($data['booking_reference'])) {
            $data['booking_reference'] = strtoupper($data['booking_reference']);
        }

        // Normalize dates
        if (isset($data['tour_date'])) {
            $data['tour_date'] = Carbon::parse($data['tour_date'])->format('Y-m-d');
        }
        if (isset($data['booking_date']) && $data['booking_date']) {
            $data['booking_date'] = Carbon::parse($data['booking_date'])->format('Y-m-d');
        }

        // Normalize times
        if (isset($data['tour_time']) && $data['tour_time']) {
            $data['tour_time'] = Carbon::parse($data['tour_time'])->format('H:i');
        }
        if (isset($data['pickup_time']) && $data['pickup_time']) {
            $data['pickup_time'] = Carbon::parse($data['pickup_time'])->format('H:i');
        }

        // Normalize phone
        if (isset($data['guest_phone']) && $data['guest_phone']) {
            $data['guest_phone'] = $this->normalizePhoneNumber($data['guest_phone']);
        }

        // Normalize currency
        if (isset($data['currency'])) {
            $data['currency'] = $this->normalizeCurrency($data['currency']);
        }

        // Ensure price is decimal
        if (isset($data['total_price'])) {
            $data['total_price'] = (float) $data['total_price'];
        }

        // Calculate number_of_guests if not set
        if (!isset($data['number_of_guests'])) {
            $data['number_of_guests'] = ($data['adults'] ?? 0) + ($data['children'] ?? 0);
        }

        // Default children to 0
        if (!isset($data['children'])) {
            $data['children'] = 0;
        }

        return $data;
    }

    /**
     * Normalize phone number
     */
    protected function normalizePhoneNumber(string $phone): string
    {
        // Remove spaces, dashes, parentheses
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);

        // Ensure starts with +
        if (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }

        return $phone;
    }

    /**
     * Normalize currency code
     */
    protected function normalizeCurrency(string $currency): string
    {
        $currencyMap = [
            '$' => 'USD',
            '€' => 'EUR',
            '£' => 'GBP',
            '¥' => 'JPY',
            '₽' => 'RUB',
        ];

        $currency = $currencyMap[$currency] ?? $currency;

        return strtoupper(substr($currency, 0, 3));
    }
}
