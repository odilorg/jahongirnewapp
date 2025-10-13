<?php

namespace App\Services;

class TelegramKeyboardService
{
    /**
     * Get main menu keyboard with common operations
     */
    public function getMainMenu(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '📅 Today\'s Arrivals', 'callback_data' => 'view_arrivals_today'],
                    ['text' => '📤 Today\'s Departures', 'callback_data' => 'view_departures_today'],
                ],
                [
                    ['text' => '🏠 Current Guests', 'callback_data' => 'view_current'],
                    ['text' => '🆕 New Bookings', 'callback_data' => 'view_new'],
                ],
                [
                    ['text' => '🔍 Search Guest', 'callback_data' => 'search_guest'],
                ],
                [
                    ['text' => '✅ Check Availability', 'callback_data' => 'check_availability'],
                ],
                [
                    ['text' => '➕ Create Booking', 'callback_data' => 'create_booking'],
                ],
                [
                    ['text' => '✏️ Modify Booking', 'callback_data' => 'modify_booking'],
                    ['text' => '❌ Cancel Booking', 'callback_data' => 'cancel_booking'],
                ],
            ]
        ];
    }

    /**
     * Get back to main menu button
     */
    public function getBackButton(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '« Back to Menu', 'callback_data' => 'main_menu'],
                ]
            ]
        ];
    }

    /**
     * Format keyboard as JSON string for Telegram API
     */
    public function formatForApi(array $keyboard): string
    {
        return json_encode($keyboard);
    }
}
