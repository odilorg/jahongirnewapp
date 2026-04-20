<?php

namespace App\Actions\BookingBot\Handlers;

use App\Services\StaffAuthorizationService;
use App\Services\TelegramBotService;

/**
 * Handles the "share phone number" contact payload from @j_booking_hotel_bot.
 *
 * Links the Telegram user to a Staff record (via normalised phone number) and
 * replies with either the access-granted or access-denied message.
 *
 * Pure extraction from ProcessBookingMessage::handlePhoneContact (pre-extraction
 * lines 118-136 of that Job). Behaviour must be byte-identical — the golden master
 * asserts this.
 */
final class HandlePhoneContactAction
{
    public function __construct(
        private readonly StaffAuthorizationService $authService,
        private readonly TelegramBotService $telegram,
    ) {}

    public function execute(array $message): void
    {
        $contact = $message['contact'];
        $chatId = $message['chat']['id'];
        $userId = $message['from']['id'];
        $username = $message['from']['username'] ?? '';

        // Telegram returns phone numbers prefixed with '+'; DB stores without.
        $phoneNumber = ltrim($contact['phone_number'], '+');

        $staff = $this->authService->linkPhoneNumber($phoneNumber, $userId, $username);

        if ($staff) {
            $this->telegram->sendMessage($chatId, $this->authService->getAccessGrantedMessage($staff));
        } else {
            $this->telegram->sendMessage($chatId, $this->authService->getAccessDeniedMessage($phoneNumber));
        }
    }
}
