<?php

namespace App\Jobs;

use App\Actions\BookingBot\Handlers\CancelBookingFromMessageAction;
use App\Actions\BookingBot\Handlers\CheckAvailabilityAction;
use App\Actions\BookingBot\Handlers\CreateBookingFromMessageAction;
use App\Actions\BookingBot\Handlers\HandleCallbackQueryAction;
use App\Actions\BookingBot\Handlers\HandlePhoneContactAction;
use App\Actions\BookingBot\Handlers\ModifyBookingFromMessageAction;
use App\Actions\BookingBot\Handlers\ViewBookingsFromMessageAction;
use App\Services\BookingIntentParser;
use App\Services\StaffAuthorizationService;
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
        TelegramKeyboardService $keyboard
    ): void {
        try {
            // Handle callback queries (button presses)
            if (isset($this->update['callback_query'])) {
                app(HandleCallbackQueryAction::class)->execute($this->update['callback_query']);
                return;
            }

            $message = $this->update['message'] ?? null;

            if (!$message) {
                return;
            }

            $chatId = $message['chat']['id'];
            $messageId = $message['message_id'];
            $text = $message['text'] ?? '';

            // Check for phone contact shared
            if (isset($message['contact'])) {
                app(HandlePhoneContactAction::class)->execute($message);
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
                        'resize_keyboard' => true
                    ]
                ]);
                return;
            }

            // Handle greetings and help — show main menu without hitting the AI parser
            $greetings = ['hi', 'hello', 'hey', 'hola', 'help', '/help', '/start', 'menu', 'привет', 'салам'];
            if (in_array(strtolower(trim($text)), $greetings)) {
                $welcomeMessage = "🏨 *Booking Bot*\n\nChoose an option or type your command:";

                $telegram->sendMessage($chatId, $welcomeMessage, [
                    'parse_mode' => 'Markdown',
                    'reply_markup' => $keyboard->formatForApi($keyboard->getMainMenu())
                ]);
                return;
            }

            // Parse intent with OpenAI
            $parsed = $parser->parse($text);

            // Handle command
            $response = $this->handleCommand($parsed, $staff);

            // Send response with back button for view and check commands
            $intent = $parsed['intent'] ?? 'unknown';
            $needsBackButton = in_array($intent, ['view_bookings', 'check_availability']);

            if ($needsBackButton) {
                $telegram->sendMessage($chatId, $response, [
                    'reply_markup' => $keyboard->formatForApi($keyboard->getBackButton())
                ]);
            } else {
                $telegram->sendMessage($chatId, $response);
            }

        } catch (\Exception $e) {
            Log::error('Process Booking Message Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'update' => $this->update
            ]);

            if (isset($chatId) && isset($telegram)) {
                $telegram->sendMessage($chatId, 'Error: ' . $e->getMessage());
            }
        }
    }

    protected function handleCommand($parsed, $staff): string
    {
        $intent = $parsed['intent'] ?? 'unknown';

        return match ($intent) {
            'check_availability' => app(CheckAvailabilityAction::class)->execute($parsed),
            'create_booking'     => app(CreateBookingFromMessageAction::class)->execute($parsed, $staff),
            'view_bookings'      => app(ViewBookingsFromMessageAction::class)->execute($parsed),
            'modify_booking'     => app(ModifyBookingFromMessageAction::class)->execute($parsed, $staff),
            'cancel_booking'     => app(CancelBookingFromMessageAction::class)->execute($parsed, $staff),
            default              => "I did not quite understand that. Try:\n\n" .
                                    "- check avail jan 2-3\n" .
                                    "- book room 12 under John Walker jan 2-3 tel +1234567890 email ok@ok.com\n" .
                                    "- cancel booking #123456\n" .
                                    "- help",
        };
    }
}
