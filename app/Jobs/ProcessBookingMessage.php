<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\BookingCommandService;
use App\Services\BookingIntentParser;
use App\Services\StaffAuthorizationService;
use App\Services\StaffResponseFormatter;
use App\Services\TelegramBotService;
use App\Services\TelegramKeyboardService;
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
        StaffResponseFormatter $formatter,
        BookingCommandService $commandService,
        TelegramKeyboardService $keyboard
    ): void {
        try {
            // Handle callback queries (button presses)
            if (isset($this->update['callback_query'])) {
                $commandService->handleCallbackQuery($this->update['callback_query'], $telegram);
                return;
            }

            $message = $this->update['message'] ?? null;

            if (!$message) {
                return;
            }

            $chatId    = $message['chat']['id'];
            $messageId = $message['message_id'];
            $text      = $message['text'] ?? '';

            // Check for phone contact shared
            if (isset($message['contact'])) {
                $this->handlePhoneContact($message, $authService, $telegram, $formatter);
                return;
            }

            // Check authorization
            $staff = $authService->verifyTelegramUser($this->update);

            if (!$staff) {
                $telegram->sendMessage($chatId, $authService->getAuthorizationRequestMessage(), [
                    'reply_markup' => [
                        'keyboard' => [[
                            ['text' => '📱 Share Phone Number', 'request_contact' => true]
                        ]],
                        'one_time_keyboard' => true,
                        'resize_keyboard'   => true,
                    ],
                ]);
                return;
            }

            // Handle greetings and help — show main menu without hitting the AI parser
            $greetings = ['hi', 'hello', 'hey', 'hola', 'help', '/help', '/start', 'menu', 'привет', 'салам'];
            if (in_array(strtolower(trim($text)), $greetings)) {
                $welcomeMessage = "🏨 *Booking Bot*\n\nChoose an option or type your command:";

                $telegram->sendMessage($chatId, $welcomeMessage, [
                    'parse_mode'   => 'Markdown',
                    'reply_markup' => $keyboard->formatForApi($keyboard->getMainMenu()),
                ]);
                return;
            }

            // Parse intent with OpenAI
            $parsed = $parser->parse($text);

            // Dispatch to command service
            $response = $commandService->handle($parsed, $staff);

            // Send response with back button for view and check commands
            $intent         = $parsed['intent'] ?? 'unknown';
            $needsBackButton = in_array($intent, ['view_bookings', 'check_availability']);

            if ($needsBackButton) {
                $telegram->sendMessage($chatId, $response, [
                    'reply_markup' => $keyboard->formatForApi($keyboard->getBackButton()),
                ]);
            } else {
                $telegram->sendMessage($chatId, $response);
            }

        } catch (\Exception $e) {
            Log::error('Process Booking Message Error', [
                'error'  => $e->getMessage(),
                'trace'  => $e->getTraceAsString(),
                'update' => $this->update,
            ]);

            if (isset($chatId) && isset($telegram)) {
                $telegram->sendMessage($chatId, 'Error: ' . $e->getMessage());
            }
        }
    }

    protected function handlePhoneContact($message, $authService, $telegram, $formatter): void
    {
        $contact     = $message['contact'];
        $phoneNumber = $contact['phone_number'];
        $chatId      = $message['chat']['id'];
        $userId      = $message['from']['id'];
        $username    = $message['from']['username'] ?? '';

        // Normalize phone number: remove + sign to match database format
        $phoneNumber = ltrim($phoneNumber, '+');

        $staff = $authService->linkPhoneNumber($phoneNumber, $userId, $username);

        if ($staff) {
            $telegram->sendMessage($chatId, $authService->getAccessGrantedMessage($staff));
        } else {
            $telegram->sendMessage($chatId, $authService->getAccessDeniedMessage($phoneNumber));
        }
    }
}
