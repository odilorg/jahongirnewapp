<?php

namespace App\Services;

use OpenAI\Laravel\Facades\OpenAI;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class OpenAIDateExtractorService
{
    public function extractDates(string $messageText): array
    {
        $currentDate = Carbon::now()->toDateString();
        
        $systemPrompt = <<<PROMPT
# Role 
You are a hotel date extraction specialist. Your primary goal is to read text from the user and extract all check-in and check-out dates.

# Tasks
- Dates Extraction
- Detect check-in and check-out dates from the provided text.
- Accept dates in any language/format.
- Convert dates to YYYY-MM-DD format using the reference date {$currentDate} for relative terms (e.g., "tomorrow," "next week").
- For implied durations (e.g., "3 nights"), calculate the check-out date by adding days to the check-in date.
- If there is no context or reference to year use today's date {$currentDate} extract the year and proceed.

Today is {$currentDate}. Use this to interpret relative date expressions in the user's text.

# Output Requirements
You must produce valid JSON (no extra text, code blocks, or markdown).

# Few-Shot Example
Use the following as a sample only (do not output literal placeholders in your final answer):

{
    "check_in_date": "2025-06-10",
    "check_out_date": "2025-06-15"
}
PROMPT;

        try {
            $response = OpenAI::chat()->create([
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => "Extract check in and check out dates from: {$messageText}"]
                ],
                'temperature' => 0.3,
                'max_tokens' => 200,
            ]);

            $content = $response['choices'][0]['message']['content'] ?? '';
            
            return $this->parseAIResponse($content);
            
        } catch (\Exception $e) {
            Log::error('OpenAI Date Extraction Error', [
                'message' => $e->getMessage(),
                'text' => $messageText
            ]);
            
            throw new \Exception('Failed to extract dates from AI: ' . $e->getMessage());
        }
    }

    protected function parseAIResponse(string $content): array
    {
        // Extract JSON from response (in case AI adds extra text)
        preg_match('/\{[^}]+\}/', $content, $matches);
        
        if (empty($matches)) {
            throw new \Exception('No valid JSON found in AI response');
        }

        $data = json_decode($matches[0], true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in AI response: ' . json_last_error_msg());
        }

        if (!isset($data['check_in_date']) || !isset($data['check_out_date'])) {
            throw new \Exception('Missing required date fields in AI response');
        }

        // Validate dates
        try {
            $checkIn = Carbon::parse($data['check_in_date']);
            $checkOut = Carbon::parse($data['check_out_date']);
            
            if ($checkOut->lte($checkIn)) {
                throw new \Exception('Check-out date must be after check-in date');
            }
            
            return [
                'check_in_date' => $checkIn->toDateString(),
                'check_out_date' => $checkOut->toDateString(),
            ];
            
        } catch (\Exception $e) {
            throw new \Exception('Invalid dates in response: ' . $e->getMessage());
        }
    }
}
