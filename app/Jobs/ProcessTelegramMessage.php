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
use App\Services\TelegramBookingService;
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
        TelegramBookingService $bookingService,
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
            $languageCode = $message['from']['language_code'] ?? 'en';

            // Handle contact (phone number) sharing
            if (isset($message['contact'])) {
                $this->handlePhoneAuth($message['contact'], $userId, $chatId, $telegram, $bookingService);
                return;
            }

            // Handle /start command
            if ($messageText === '/start') {
                $this->handleStartCommand($userId, $chatId, $languageCode, $telegram, $bookingService);
                return;
            }

            // Check authentication for all other commands
            if (!$bookingService->isAuthenticated($userId)) {
                $telegram->sendMessage($chatId, "🔒 Please authenticate first using the /start command.");
                return;
            }

            // Get or update session activity
            $session = $bookingService->getSessionByTelegramId($userId);
            if ($session) {
                $session->updateActivity();
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

    protected function handleStartCommand(
        int $userId,
        int $chatId,
        string $languageCode,
        TelegramBotService $telegram,
        TelegramBookingService $bookingService
    ): void
    {
        // Check if already authenticated
        if ($bookingService->isAuthenticated($userId)) {
            $user = $bookingService->getAuthenticatedUser($userId);
            $telegram->sendMessage($chatId, "✅ You are already authenticated as {$user->name}.\n\nYou can now check hotel availability by sending me dates!");
            return;
        }

        // Create guest session
        $bookingService->createGuestSession($userId, $chatId, $languageCode);

        // Send welcome message with phone request button
        $keyboard = [
            'keyboard' => [
                [
                    [
                        'text' => '📱 Share Phone Number',
                        'request_contact' => true,
                    ]
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];

        $welcomeMessage = "👋 Welcome to Hotel Booking Bot!\n\n"
            . "To use this bot, please authenticate by sharing your phone number.\n\n"
            . "Click the button below to share your phone number:";

        $telegram->sendMessage($chatId, $welcomeMessage, $keyboard);
    }

    protected function handlePhoneAuth(
        array $contact,
        int $userId,
        int $chatId,
        TelegramBotService $telegram,
        TelegramBookingService $bookingService
    ): void
    {
        $phoneNumber = $contact['phone_number'] ?? null;

        if (!$phoneNumber) {
            $telegram->sendMessage($chatId, "❌ No phone number received. Please try again.");
            return;
        }

        Log::info('Phone authentication attempt', [
            'telegram_user_id' => $userId,
            'phone' => $phoneNumber,
        ]);

        // Authenticate user
        $result = $bookingService->authenticate($userId, $chatId, $phoneNumber);

        if ($result['success']) {
            $user = $result['user'];

            // Remove keyboard
            $keyboard = ['remove_keyboard' => true];

            $successMessage = "✅ Authentication successful!\n\n"
                . "Welcome, {$user->name}!\n\n"
                . "You can now check hotel availability by sending me dates.\n\n"
                . "Example: \"Check availability from tomorrow to next week\"";

            $telegram->sendMessage($chatId, $successMessage, $keyboard);

            Log::info('User authenticated successfully', [
                'user_id' => $user->id,
                'telegram_user_id' => $userId,
            ]);
        } else {
            $errorMessage = "❌ Authentication failed: {$result['message']}\n\n"
                . "Please make sure your phone number is registered in our system.";

            $telegram->sendMessage($chatId, $errorMessage);

            Log::warning('User authentication failed', [
                'telegram_user_id' => $userId,
                'phone' => $phoneNumber,
                'reason' => $result['message'],
            ]);
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
