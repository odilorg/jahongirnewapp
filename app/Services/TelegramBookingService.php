<?php

namespace App\Services;

use App\Models\User;
use App\Models\TelegramBookingSession;
use Illuminate\Support\Facades\Log;

class TelegramBookingService
{
    /**
     * Authenticate a user via phone number
     */
    public function authenticate(int $telegramUserId, int $chatId, string $phoneNumber): array
    {
        // Normalize phone number (remove + and spaces)
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        Log::info('Booking bot authentication attempt', [
            'telegram_user_id' => $telegramUserId,
            'phone' => $phoneNumber,
        ]);
        
        // Find user by phone number
        $user = User::where('phone_number', $phoneNumber)->first();
        
        if (!$user) {
            Log::warning('Booking bot auth failed - phone not found', [
                'phone' => $phoneNumber,
                'telegram_user_id' => $telegramUserId,
            ]);
            
            return [
                'success' => false,
                'message' => 'Phone number not registered in our system',
            ];
        }
        
        // Update or create session
        $session = TelegramBookingSession::updateOrCreate(
            ['telegram_user_id' => $telegramUserId],
            [
                'chat_id' => $chatId,
                'user_id' => $user->id,
                'state' => 'authenticated',
                'language' => $this->detectLanguage($user),
                'last_activity_at' => now(),
                'expires_at' => now()->addMinutes(config('services.telegram_booking_bot.session_timeout', 15)),
            ]
        );
        
        // Update user's booking bot telegram info
        $user->update([
            'telegram_booking_user_id' => $telegramUserId,
            'telegram_booking_username' => null, // Can be added if needed from Telegram update
        ]);
        
        Log::info('Booking bot authentication successful', [
            'user_id' => $user->id,
            'telegram_user_id' => $telegramUserId,
        ]);
        
        return [
            'success' => true,
            'user' => $user,
            'session' => $session,
        ];
    }
    
    /**
     * Get or create session for a chat
     */
    public function getSession(int $chatId): ?TelegramBookingSession
    {
        $session = TelegramBookingSession::where('chat_id', $chatId)->first();
        
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
    public function getSessionByTelegramId(int $telegramUserId): ?TelegramBookingSession
    {
        $session = TelegramBookingSession::where('telegram_user_id', $telegramUserId)->first();
        
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
     * Check if user is authenticated
     */
    public function isAuthenticated(int $telegramUserId): bool
    {
        $session = $this->getSessionByTelegramId($telegramUserId);
        
        return $session && $session->isAuthenticated();
    }
    
    /**
     * Set user language preference
     */
    public function setUserLanguage(int $chatId, string $language): bool
    {
        $session = TelegramBookingSession::where('chat_id', $chatId)->first();
        
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
        $session = TelegramBookingSession::where('chat_id', $chatId)->first();
        
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
     * Clear expired sessions
     */
    public function clearExpiredSessions(): int
    {
        return TelegramBookingSession::expired()->delete();
    }
    
    /**
     * End session
     */
    public function endSession(int $chatId): bool
    {
        return TelegramBookingSession::where('chat_id', $chatId)->delete() > 0;
    }
    
    /**
     * Create initial session for unauthenticated user
     */
    public function createGuestSession(int $telegramUserId, int $chatId, string $languageCode = 'en'): TelegramBookingSession
    {
        // Map Telegram language codes to our supported languages
        $language = match($languageCode) {
            'ru' => 'ru',
            'uz' => 'uz',
            default => 'en',
        };
        
        Log::info('Creating guest session for booking bot', [
            'telegram_user_id' => $telegramUserId,
            'chat_id' => $chatId,
            'language' => $language,
        ]);
        
        return TelegramBookingSession::updateOrCreate(
            ['telegram_user_id' => $telegramUserId],
            [
                'chat_id' => $chatId,
                'state' => 'awaiting_phone',
                'language' => $language,
                'last_activity_at' => now(),
                'expires_at' => now()->addMinutes(config('services.telegram_booking_bot.session_timeout', 15)),
            ]
        );
    }
    
    /**
     * Get authenticated user from session
     */
    public function getAuthenticatedUser(int $telegramUserId): ?User
    {
        $session = $this->getSessionByTelegramId($telegramUserId);
        
        if (!$session || !$session->isAuthenticated()) {
            return null;
        }
        
        return $session->user;
    }
}
