<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\TelegramBotConversation;
use App\Models\BotAnalytics;
use App\Services\OpenAIDateExtractorService;
use App\Services\TelegramBotService;
use App\Services\ResponseFormatterService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessTelegramMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;
    public $tries = 3;

    protected array $update;

    public function __construct(array $update)
    {
        $this->update = $update;
    }

    public function handle(
        OpenAIDateExtractorService $dateExtractor,
        TelegramBotService $telegram,
        ResponseFormatterService $formatter
    ): void
    {
        $startTime = microtime(true);

        try {
            // Extract message data
            $message = $this->update['message'] ?? null;
            
            if (!$message) {
                Log::info('No message in update', ['update' => $this->update]);
                return;
            }

            $chatId = $message['chat']['id'];
            $messageId = $message['message_id'];
            $messageText = $message['text'] ?? '';
            $userId = $message['from']['id'] ?? null;
            $username = $message['from']['username'] ?? null;

            // Handle /start command
            if ($messageText === '/start') {
                $telegram->sendMessage($chatId, $formatter->formatWelcomeMessage());
                return;
            }

            // Create conversation record
            $conversation = TelegramBotConversation::create([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'user_id' => $userId,
                'username' => $username,
                'message_text' => $messageText,
                'status' => 'pending',
            ]);

            // Step 1: Extract dates using OpenAI
            try {
                $dates = $dateExtractor->extractDates($messageText);
                
                $conversation->update([
                    'check_in_date' => $dates['check_in_date'],
                    'check_out_date' => $dates['check_out_date'],
                    'ai_response' => $dates,
                ]);
                
            } catch (\Exception $e) {
                throw new \Exception('Date extraction failed: ' . $e->getMessage());
            }

            // Step 2: Check availability
            try {
                $availabilityData = $this->checkAvailability($dates['check_in_date'], $dates['check_out_date']);
                
                $conversation->update([
                    'availability_data' => $availabilityData,
                ]);
                
            } catch (\Exception $e) {
                throw new \Exception('Availability check failed: ' . $e->getMessage());
            }

            // Step 3: Format and send response
            $response = $formatter->formatAvailabilityResponse(
                $availabilityData,
                $dates['check_in_date'],
                $dates['check_out_date']
            );

            $telegram->sendMessage($chatId, $response);

            // Mark as processed
            $responseTime = round(microtime(true) - $startTime, 2);
            $conversation->update([
                'status' => 'processed',
                'response_time' => $responseTime,
            ]);

            // Record analytics
            BotAnalytics::recordMessage(true, $responseTime, $chatId);

        } catch (\Exception $e) {
            Log::error('Process Telegram Message Error', [
                'error' => $e->getMessage(),
                'update' => $this->update,
                'trace' => $e->getTraceAsString()
            ]);

            // Update conversation with error
            if (isset($conversation)) {
                $responseTime = round(microtime(true) - $startTime, 2);
                $conversation->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'response_time' => $responseTime,
                ]);

                // Record analytics
                BotAnalytics::recordMessage(false, $responseTime, $chatId);
            }

            // Send error message to user
            try {
                if (isset($chatId) && isset($telegram) && isset($formatter)) {
                    $telegram->sendMessage($chatId, $formatter->formatErrorMessage($e->getMessage()));
                }
            } catch (\Exception $sendError) {
                Log::error('Failed to send error message', ['error' => $sendError->getMessage()]);
            }
        }
    }

    protected function checkAvailability(string $checkInDate, string $checkOutDate): array
    {
        $apiUrl = config('app.url') . '/api/availability';
        $bearerToken = config('services.beds24.api_token', '1|B4lwPffjpKRUg1B0A7OC9eC7YhMMZOOTTkZ1ZrYle1ac1f29');

        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $bearerToken,
            ])
            ->post($apiUrl, [
                'arrival_date' => $checkInDate,
                'departure_date' => $checkOutDate,
            ]);

        if (!$response->successful()) {
            throw new \Exception('Availability API returned error: ' . $response->body());
        }

        return $response->json();
    }
}
