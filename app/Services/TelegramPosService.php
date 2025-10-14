<?php

namespace App\Services;

use App\Models\User;
use App\Models\TelegramPosSession;
use App\Models\TelegramPosActivity;
use Illuminate\Support\Facades\Log;

class TelegramPosService
{
    /**
     * Authenticate a user via phone number (using existing User model methods)
     */
    public function authenticate(int $telegramUserId, int $chatId, string $phoneNumber): array
    {
        // Normalize phone number (remove + and spaces)
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Check if phone is authorized using existing User model method
        if (!User::isPhoneAuthorized($phoneNumber)) {
            // Log failed attempt
            TelegramPosActivity::log(null, 'auth_failed', 'Phone: ' . $phoneNumber, $telegramUserId);
            
            return [
                'success' => false,
                'message' => 'Phone number not authorized',
            ];
        }
        
        // Find user by phone number
        $user = User::where('phone_number', $phoneNumber)->first();
        
        // Check if user has cashier or manager role
        if (!$user->hasAnyRole(['cashier', 'manager', 'super_admin'])) {
            TelegramPosActivity::log($user->id, 'auth_failed', 'Insufficient permissions', $telegramUserId);
            
            return [
                'success' => false,
                'message' => 'User does not have required permissions',
            ];
        }
        
        // Update or create session
        $session = TelegramPosSession::updateOrCreate(
            ['telegram_user_id' => $telegramUserId],
            [
                'chat_id' => $chatId,
                'user_id' => $user->id,
                'state' => 'authenticated',
                'language' => $this->detectLanguage($user),
                'last_activity_at' => now(),
                'expires_at' => now()->addMinutes(config('services.telegram_pos_bot.session_timeout', 15)),
            ]
        );
        
        // Update user's telegram info and last active timestamp
        $user->update([
            'telegram_user_id' => $telegramUserId,
            'last_active_at' => now(),
        ]);
        
        // Log successful authentication
        TelegramPosActivity::log($user->id, 'auth_success', 'Phone: ' . $phoneNumber, $telegramUserId);
        
        return [
            'success' => true,
            'user' => $user,
            'session' => $session,
        ];
    }
    
    /**
     * Get or create session for a chat
     */
    public function getSession(int $chatId): ?TelegramPosSession
    {
        $session = TelegramPosSession::where('chat_id', $chatId)->first();
        
        if (!$session) {
            return null;
        }
        
        // Check if expired
        if ($session->isExpired()) {
            $session->delete();
            return null;
        }
        
        // Update activity
        $session->updateActivity();
        
        return $session;
    }
    
    /**
     * Get session by Telegram user ID
     */
    public function getSessionByTelegramId(int $telegramUserId): ?TelegramPosSession
    {
        $session = TelegramPosSession::where('telegram_user_id', $telegramUserId)->first();
        
        if (!$session) {
            return null;
        }
        
        // Check if expired
        if ($session->isExpired()) {
            $session->delete();
            return null;
        }
        
        return $session;
    }
    
    /**
     * Check session expiry
     */
    public function checkSessionExpiry(TelegramPosSession $session): bool
    {
        return $session->isExpired();
    }
    
    /**
     * Set user language preference
     */
    public function setUserLanguage(int $chatId, string $language): bool
    {
        $session = TelegramPosSession::where('chat_id', $chatId)->first();
        
        if (!$session) {
            return false;
        }
        
        $session->update(['language' => $language]);
        
        return true;
    }
    
    /**
     * Get user's preferred language
     */
    public function getUserLanguage(int $chatId): string
    {
        $session = TelegramPosSession::where('chat_id', $chatId)->first();
        
        if ($session) {
            return $session->language;
        }
        
        return 'en'; // Default
    }
    
    /**
     * Detect user's language from user model or default
     */
    protected function detectLanguage(?User $user): string
    {
        if (!$user) {
            return 'en';
        }
        
        // Try to detect from user preferences or locale
        // For now, default to English
        return 'en';
    }
    
    /**
     * Log user activity
     */
    public function logActivity($userId, string $action, $details = null, $telegramUserId = null): TelegramPosActivity
    {
        return TelegramPosActivity::log($userId, $action, $details, $telegramUserId);
    }
    
    /**
     * Clear expired sessions
     */
    public function clearExpiredSessions(): int
    {
        return TelegramPosSession::expired()->delete();
    }
    
    /**
     * End session
     */
    public function endSession(int $chatId): bool
    {
        return TelegramPosSession::where('chat_id', $chatId)->delete() > 0;
    }
    
    /**
     * Create initial session for unauthenticated user
     */
    public function createGuestSession(int $telegramUserId, int $chatId, string $languageCode = 'en'): TelegramPosSession
    {
        // Map Telegram language codes to our supported languages
        $language = match($languageCode) {
            'ru' => 'ru',
            'uz' => 'uz',
            default => 'en',
        };
        
        return TelegramPosSession::updateOrCreate(
            ['telegram_user_id' => $telegramUserId],
            [
                'chat_id' => $chatId,
                'state' => 'awaiting_phone',
                'language' => $language,
                'last_activity_at' => now(),
                'expires_at' => now()->addMinutes(config('services.telegram_pos_bot.session_timeout', 15)),
            ]
        );
    }
}

