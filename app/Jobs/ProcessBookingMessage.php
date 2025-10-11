<?php

namespace App\Jobs;

use App\Models\AuthorizedStaff;
use App\Services\BookingIntentParser;
use App\Services\StaffAuthorizationService;
use App\Services\TelegramBotService;
use App\Services\StaffResponseFormatter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBookingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public array $update
    ) {}

    public function handle(
        StaffAuthorizationService $authService,
        BookingIntentParser $parser,
        TelegramBotService $telegram,
        StaffResponseFormatter $formatter
    ): void {
        try {
            $message = $this->update['message'] ?? null;
            
            if (!$message) {
                return;
            }

            $chatId = $message['chat']['id'];
            $messageId = $message['message_id'];
            $text = $message['text'] ?? '';

            // Check for phone contact shared
            if (isset($message['contact'])) {
                $this->handlePhoneContact($message, $authService, $telegram, $formatter);
                return;
            }

            // Check authorization
            $staff = $authService->verifyTelegramUser($this->update);

            if (!$staff) {
                // Request phone number
                $telegram->sendMessage($chatId, $authService->getAuthorizationRequestMessage(), [
                    'reply_markup' => json_encode([
                        'keyboard' => [[
                            ['text' => 'Share Phone Number', 'request_contact' => true]
                        ]],
                        'one_time_keyboard' => true,
                        'resize_keyboard' => true
                    ])
                ]);
                return;
            }

            // Handle help command
            if (in_array(strtolower($text), ['help', '/help', '/start'])) {
                $telegram->sendMessage($chatId, $formatter->formatHelp());
                return;
            }

            // Parse intent with OpenAI
            $parsed = $parser->parse($text);

            // Handle command (simplified - would use BookingCommandHandler)
            $response = $this->handleCommand($parsed, $staff, $chatId, $messageId, $text);

            $telegram->sendMessage($chatId, $response);

        } catch (\Exception $e) {
            Log::error('Process Booking Message Error', [
                'error' => $e->getMessage(),
                'update' => $this->update
            ]);

            if (isset($chatId) && isset($telegram)) {
                $telegram->sendMessage($chatId, "❌ Error: " . $e->getMessage());
            }
        }
    }

    protected function handlePhoneContact($message, $authService, $telegram, $formatter): void
    {
        $contact = $message['contact'];
        $phoneNumber = $contact['phone_number'];
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $username = $message['from']['username'] ?? '';

        $staff = $authService->linkPhoneNumber($phoneNumber, $userId, $username);

        if ($staff) {
            $telegram->sendMessage($chatId, $authService->getAccessGrantedMessage($staff));
        } else {
            $telegram->sendMessage($chatId, $authService->getAccessDeniedMessage($phoneNumber));
        }
    }

    protected function handleCommand($parsed, $staff, $chatId, $messageId, $text): string
    {
        // Simplified handler - full implementation would use BookingCommandHandler
        return "Command received: " . ($parsed['intent'] ?? 'unknown') . "

Full integration coming soon!";
    }
}
