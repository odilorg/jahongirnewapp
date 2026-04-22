<?php

namespace App\Jobs;

use App\Actions\BookingBot\Handlers\CancelBookingFromMessageAction;
use App\Actions\BookingBot\Handlers\CheckAvailabilityAction;
use App\Actions\BookingBot\Handlers\CreateBookingFromMessageAction;
use App\Actions\BookingBot\Handlers\HandleCallbackQueryAction;
use App\Actions\BookingBot\Handlers\HandlePhoneContactAction;
use App\Actions\BookingBot\Handlers\ModifyBookingFromMessageAction;
use App\Actions\BookingBot\Handlers\ViewBookingsFromMessageAction;
use App\Services\BookingBot\IntentParseException;
use App\Services\BookingIntentParser;
use App\Support\BookingBot\HelpContent;
use App\Support\BookingBot\LogSanitizer;
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

            // Handle /help explicitly — show examples-per-intent guide
            // with a back-to-menu button. This is a behavior-training
            // surface: only examples that we know the parser accepts.
            $normalized = strtolower(trim($text));
            $helpTokens = ['help', '/help'];
            if (in_array($normalized, $helpTokens, true)) {
                $telegram->sendMessage($chatId, HelpContent::render(), [
                    'reply_markup' => $keyboard->formatForApi($keyboard->getBackButton()),
                ]);
                return;
            }

            // Handle greetings — show main menu without hitting the AI parser
            $greetings = ['hi', 'hello', 'hey', 'hola', '/start', 'menu', '/menu', 'привет', 'салам'];
            if (in_array($normalized, $greetings, true)) {
                $welcomeMessage = "🏨 *Booking Bot*\n\nChoose an option or type your command.\nType /help for examples.";

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

            // Handlers may append a guest-forward message, delimited by
            // a fixed marker, for the operator to copy-paste to the guest.
            // We send it as a SECOND Telegram message so Telegram mobile's
            // long-press-copy grabs the guest text cleanly.
            [$primary, $guestForward] = $this->splitGuestForward($response);

            if ($needsBackButton) {
                $telegram->sendMessage($chatId, $primary, [
                    'reply_markup' => $keyboard->formatForApi($keyboard->getBackButton())
                ]);
            } else {
                $telegram->sendMessage($chatId, $primary);
            }

            if ($guestForward !== null && $guestForward !== '') {
                $telegram->sendMessage($chatId, $guestForward);
            }

        } catch (IntentParseException $e) {
            // Intent parser (local or LLM) couldn't parse the message.
            // Do NOT leak raw cURL / JSON errors to the operator — ship
            // a menu-hint reply instead. The sanitized exception message
            // is logged for debugging only.
            //
            // Operator inputs carry PII (guest names, phone numbers,
            // emails). Log length + prefix by default; opt in to full
            // payload via LOG_BOOKING_BOT_DEBUG_PAYLOADS=true.
            $text = $text ?? '';
            $logPayload = [
                'error'          => $e->getMessage(),
                'message_len'    => mb_strlen($text),
                'message_prefix' => LogSanitizer::commandPreview($text),
            ];
            if ((bool) config('logging.booking_bot.debug_payloads', false)) {
                $logPayload['message'] = $text;
                $logPayload['update']  = $this->update;
            }
            Log::warning('Booking Bot Intent Parse Failed', $logPayload);

            if (isset($chatId) && isset($telegram)) {
                $telegram->sendMessage(
                    $chatId,
                    "I couldn't parse that. Try one of:\n" .
                    "• bookings today\n" .
                    "• arrivals today\n" .
                    "• cancel 12345\n" .
                    "Type /help for full examples, or /menu to return."
                );
            }
        } catch (\Exception $e) {
            // Operator messages often embed PII (phone, email) inside
            // the Telegram `message.text` free string. LogSanitizer's
            // `text` rule truncates at 60 chars, but short PII sits in
            // that head. Replace the update payload with a compact
            // summary: IDs + text length + 40-char prefix. Full payload
            // only when LOG_BOOKING_BOT_DEBUG_PAYLOADS=true.
            $text = $this->update['message']['text'] ?? '';
            $errorPayload = [
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
                'update_id'      => $this->update['update_id'] ?? null,
                'message_len'    => mb_strlen((string) $text),
                'message_prefix' => LogSanitizer::commandPreview((string) $text),
            ];
            if ((bool) config('logging.booking_bot.debug_payloads', false)) {
                $errorPayload['update'] = $this->update;
            }
            Log::error('Process Booking Message Error', $errorPayload);

            if (isset($chatId) && isset($telegram)) {
                $telegram->sendMessage($chatId, 'Error: ' . $e->getMessage());
            }
        }
    }

    /**
     * Marker that Handlers can append to split their reply into
     * (1) operator receipt and (2) guest-forward copy-paste message.
     * Kept in sync with the same constant in the booking-bot Handlers.
     */
    public const GUEST_FORWARD_MARKER = "\n---GUEST-FORWARD---\n";

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitGuestForward(string $response): array
    {
        $pos = strpos($response, self::GUEST_FORWARD_MARKER);
        if ($pos === false) {
            return [$response, null];
        }

        $primary = substr($response, 0, $pos);
        $guest   = substr($response, $pos + strlen(self::GUEST_FORWARD_MARKER));

        return [rtrim($primary), $guest === false ? null : trim($guest)];
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
