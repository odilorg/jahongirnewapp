<?php

namespace App\Actions\BookingBot\Handlers;

use App\Services\StaffAuthorizationService;
use App\Services\TelegramBotService;
use App\Services\TelegramKeyboardService;

/**
 * Handles inline-button callback queries from @j_booking_hotel_bot.
 *
 * Pure extraction from ProcessBookingMessage::handleCallbackQuery. Behaviour
 * must be byte-identical — the golden master asserts this.
 *
 * The four "view_*" callback cases delegate to ViewBookingsFromMessageAction
 * via the container. Earlier iterations passed a Closure delegate as a
 * transitional seam; that scaffold was removed when ViewBookings shipped.
 */
final class HandleCallbackQueryAction
{
    public function __construct(
        private readonly StaffAuthorizationService $authService,
        private readonly TelegramBotService $telegram,
        private readonly TelegramKeyboardService $keyboard,
    ) {}

    public function execute(array $callbackQuery): void
    {
        $chatId = $callbackQuery['message']['chat']['id'];
        $messageId = $callbackQuery['message']['message_id'];
        $callbackData = $callbackQuery['data'];
        $callbackQueryId = $callbackQuery['id'];

        $staff = $this->authService->verifyTelegramUser(['callback_query' => $callbackQuery]);

        if (!$staff) {
            $this->telegram->answerCallbackQuery($callbackQueryId);

            $this->telegram->sendMessage($chatId, $this->authService->getAuthorizationRequestMessage(), [
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

        // Answer the callback query immediately to remove loading state
        $this->telegram->answerCallbackQuery($callbackQueryId);

        switch ($callbackData) {
            case 'main_menu':
                $this->telegram->editMessageText($chatId, $messageId, "🏨 Booking Bot Menu\n\nChoose an option below:", [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getMainMenu())
                ]);
                break;

            case 'view_arrivals_today':
                $response = app(ViewBookingsFromMessageAction::class)->execute(['filter_type' => 'arrivals_today']);
                $this->telegram->editMessageText($chatId, $messageId, $response, [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getBackButton())
                ]);
                break;

            case 'view_departures_today':
                $response = app(ViewBookingsFromMessageAction::class)->execute(['filter_type' => 'departures_today']);
                $this->telegram->editMessageText($chatId, $messageId, $response, [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getBackButton())
                ]);
                break;

            case 'view_current':
                $response = app(ViewBookingsFromMessageAction::class)->execute(['filter_type' => 'current']);
                $this->telegram->editMessageText($chatId, $messageId, $response, [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getBackButton())
                ]);
                break;

            case 'view_new':
                $response = app(ViewBookingsFromMessageAction::class)->execute(['filter_type' => 'new']);
                $this->telegram->editMessageText($chatId, $messageId, $response, [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getBackButton())
                ]);
                break;

            case 'search_guest':
            case 'check_availability':
            case 'create_booking':
            case 'modify_booking':
            case 'cancel_booking':
                $instructions = match($callbackData) {
                    'search_guest' => "Please type the guest name to search.\n\nExample: search for John Smith",
                    'check_availability' => "Please type dates to check availability.\n\nExample: check avail jan 15-17",
                    'create_booking' => "Please type booking details.\n\nExample: book room 12 under John Smith jan 15-17 tel +1234567890 email john@email.com",
                    'modify_booking' => "Please type booking ID and changes.\n\nExample: modify booking #123456 to jan 15-17",
                    'cancel_booking' => "Please type booking ID to cancel.\n\nExample: cancel booking #123456",
                };

                $this->telegram->editMessageText($chatId, $messageId, $instructions, [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getBackButton())
                ]);
                break;

            default:
                $this->telegram->editMessageText($chatId, $messageId, "Unknown action. Please try again.", [
                    'reply_markup' => $this->keyboard->formatForApi($this->keyboard->getMainMenu())
                ]);
                break;
        }
    }
}
