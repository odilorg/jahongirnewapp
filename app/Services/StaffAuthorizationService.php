<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;

class StaffAuthorizationService
{
    /**
     * Verify if Telegram user is authorized
     */
    public function verifyTelegramUser(array $update): ?User
    {
        // Handle both regular messages and callback queries (button clicks)
        $from = $update['message']['from'] ?? $update['callback_query']['from'] ?? null;

        if (!$from) {
            return null;
        }

        $telegramUserId = $from['id'] ?? null;
        $telegramUsername = $from['username'] ?? null;

        if (!$telegramUserId) {
            return null;
        }

        // Find by Booking Bot Telegram ID
        $user = User::findByBookingBotTelegramId($telegramUserId);

        if ($user) {
            $user->touchLastActive();
            return $user;
        }

        return null;
    }

    /**
     * Link phone number to Telegram account
     */
    public function linkPhoneNumber(string $phoneNumber, int $telegramUserId, string $telegramUsername): ?User
    {
        // Check if phone is authorized (user exists with this phone)
        $user = User::where('phone_number', $phoneNumber)->first();

        if (!$user) {
            Log::warning('Unauthorized phone number attempted to link', [
                'phone' => $phoneNumber,
                'telegram_id' => $telegramUserId
            ]);
            return null;
        }

        // Link Telegram account to Booking Bot
        $user->update([
            'telegram_booking_user_id' => $telegramUserId,
            'telegram_booking_username' => $telegramUsername,
            'last_active_at' => now(),
        ]);

        Log::info('User account linked to Telegram Booking Bot', [
            'user_id' => $user->id,
            'telegram_id' => $telegramUserId
        ]);

        return $user;
    }

    /**
     * Check if phone number is authorized
     */
    public function isPhoneAuthorized(string $phoneNumber): bool
    {
        return User::isPhoneAuthorized($phoneNumber);
    }

    /**
     * Format authorization request message
     */
    public function getAuthorizationRequestMessage(): string
    {
        return "🔐 *Authorization Required*\n\n" .
               "Please share your phone number to verify access.\n\n" .
               "Tap the button below to share your contact.";
    }

    /**
     * Format access granted message
     */
    public function getAccessGrantedMessage(User $user): string
    {
        return "✅ *Access Granted*\n\n" .
               "Welcome, *{$user->name}*!\n\n" .
               "You can now create bookings.\n\n" .
               "*Try these commands:*\n" .
               "• `check avail jan 2-3`\n" .
               "• `book room 12 under John Walker jan 2-3 tel +1234567890 email ok@ok.com`\n" .
               "• `today's bookings`";
    }

    /**
     * Format access denied message
     */
    public function getAccessDeniedMessage(string $phoneNumber): string
    {
        return "❌ *Access Denied*\n\n" .
               "Your phone number is not authorized.\n\n" .
               "Phone: `{$phoneNumber}`\n\n" .
               "Please contact your administrator to add your number.";
    }
}
