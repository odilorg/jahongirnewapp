<?php

namespace App\Services;

use App\Models\AuthorizedStaff;
use Illuminate\Support\Facades\Log;

class StaffAuthorizationService
{
    /**
     * Verify if Telegram user is authorized
     */
    public function verifyTelegramUser(array $update): ?AuthorizedStaff
    {
        $from = $update['message']['from'] ?? null;
        
        if (!$from) {
            return null;
        }

        $telegramUserId = $from['id'] ?? null;
        $telegramUsername = $from['username'] ?? null;

        if (!$telegramUserId) {
            return null;
        }

        // Find by Telegram ID
        $staff = AuthorizedStaff::findByTelegramId($telegramUserId);

        if ($staff) {
            $staff->touchLastActive();
            return $staff;
        }

        return null;
    }

    /**
     * Link phone number to Telegram account
     */
    public function linkPhoneNumber(string $phoneNumber, int $telegramUserId, string $telegramUsername): ?AuthorizedStaff
    {
        // Check if phone is authorized
        $staff = AuthorizedStaff::where('phone_number', $phoneNumber)
            ->where('is_active', true)
            ->first();

        if (!$staff) {
            Log::warning('Unauthorized phone number attempted to link', [
                'phone' => $phoneNumber,
                'telegram_id' => $telegramUserId
            ]);
            return null;
        }

        // Link Telegram account
        $staff->update([
            'telegram_user_id' => $telegramUserId,
            'telegram_username' => $telegramUsername,
            'last_active_at' => now(),
        ]);

        Log::info('Staff account linked', [
            'staff_id' => $staff->id,
            'telegram_id' => $telegramUserId
        ]);

        return $staff;
    }

    /**
     * Check if phone number is authorized
     */
    public function isPhoneAuthorized(string $phoneNumber): bool
    {
        return AuthorizedStaff::isAuthorized($phoneNumber);
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
    public function getAccessGrantedMessage(AuthorizedStaff $staff): string
    {
        return "✅ *Access Granted*\n\n" .
               "Welcome, *{$staff->full_name}*!\n\n" .
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
