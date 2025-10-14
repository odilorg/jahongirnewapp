<?php

namespace App\Http\Controllers;

use App\Services\TelegramPosService;
use App\Services\TelegramMessageFormatter;
use App\Services\TelegramKeyboardBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramPosController extends Controller
{
    protected $botToken;
    
    public function __construct(
        protected TelegramPosService $posService,
        protected TelegramMessageFormatter $formatter,
        protected TelegramKeyboardBuilder $keyboard
    ) {
        $this->botToken = config('services.telegram_pos_bot.token');
    }
    
    /**
     * Handle incoming webhook from Telegram
     */
    public function handleWebhook(Request $request)
    {
        Log::info('Telegram POS Webhook received', $request->all());
        
        // Handle callback queries (inline button presses)
        if ($callback = $request->input('callback_query')) {
            return $this->handleCallbackQuery($callback);
        }
        
        // Handle contact sharing
        if ($contact = $request->input('message.contact')) {
            return $this->handleContactShared($contact, $request);
        }
        
        // Handle text messages
        return $this->processMessage($request);
    }
    
    /**
     * Process text messages
     */
    protected function processMessage(Request $request)
    {
        $message = $request->input('message');
        
        if (!$message) {
            Log::error('No message in webhook payload');
            return response('OK');
        }
        
        $chatId = $message['chat']['id'] ?? null;
        $telegramUserId = $message['from']['id'] ?? null;
        $text = trim($message['text'] ?? '');
        $languageCode = $message['from']['language_code'] ?? 'en';
        
        if (!$chatId || !$telegramUserId) {
            return response('OK');
        }
        
        // Get or create session
        $session = $this->posService->getSession($chatId);
        
        // If no session, check for /start command
        if (!$session && strtolower($text) !== '/start') {
            $this->sendMessage(
                $chatId,
                $this->formatter->formatError(__('telegram_pos.session_expired', [], 'en'), 'en')
            );
            return response('OK');
        }
        
        // Route commands
        switch (strtolower($text)) {
            case '/start':
                return $this->handleStart($chatId, $telegramUserId, $languageCode);
                
            case '/language':
            case '⚙️ settings':
            case '⚙️ sozlamalar':
            case '⚙️ настройки':
                return $this->showLanguageSelection($chatId, $session);
                
            case '/help':
            case 'ℹ️ help':
            case 'ℹ️ yordam':
            case 'ℹ️ помощь':
                return $this->showHelp($chatId, $session);
                
            case '🟢 start shift':
            case '🟢 smenani boshlash':
            case '🟢 начать смену':
                return $this->handleStartShift($chatId, $session);
                
            case '📊 my shift':
            case '📊 mening smenaim':
            case '📊 моя смена':
                return $this->handleMyShift($chatId, $session);
                
            case '💰 record transaction':
            case '💰 tranzaksiyani yozish':
            case '💰 записать транзакцию':
                return $this->handleRecordTransaction($chatId, $session);
                
            case '🔴 close shift':
            case '🔴 smenani yopish':
            case '🔴 закрыть смену':
                return $this->handleCloseShift($chatId, $session);
                
            default:
                // Handle conversation states
                if ($session) {
                    return $this->handleConversationState($session, $text);
                }
                
                $lang = $session ? $session->language : 'en';
                $this->sendMessage(
                    $chatId,
                    $this->formatter->formatMainMenu($lang),
                    $this->keyboard->mainMenuKeyboard($lang)
                );
        }
        
        return response('OK');
    }
    
    /**
     * Handle /start command
     */
    protected function handleStart(int $chatId, int $telegramUserId, string $languageCode = 'en')
    {
        // Check if already authenticated
        $session = $this->posService->getSessionByTelegramId($telegramUserId);
        
        if ($session && $session->user_id) {
            // Already authenticated
            $lang = $session->language;
            $this->sendMessage(
                $chatId,
                $this->formatter->formatWelcome($session->user->name, $lang),
                $this->keyboard->mainMenuKeyboard($lang)
            );
        } else {
            // Create guest session and request phone
            $session = $this->posService->createGuestSession($telegramUserId, $chatId, $languageCode);
            $lang = $session->language;
            
            $this->sendMessage(
                $chatId,
                $this->formatter->formatAuthRequest($lang),
                $this->keyboard->phoneRequestKeyboard($lang)
            );
        }
        
        return response('OK');
    }
    
    /**
     * Handle contact sharing (authentication)
     */
    protected function handleContactShared(array $contact, Request $request)
    {
        $chatId = $request->input('message.chat.id');
        $telegramUserId = $request->input('message.from.id');
        $phoneNumber = $contact['phone_number'];
        
        // Authenticate
        $result = $this->posService->authenticate($telegramUserId, $chatId, $phoneNumber);
        
        if ($result['success']) {
            $user = $result['user'];
            $lang = $result['session']->language;
            
            $this->sendMessage(
                $chatId,
                $this->formatter->formatAuthSuccess($user, $lang),
                $this->keyboard->mainMenuKeyboard($lang)
            );
        } else {
            $this->sendMessage(
                $chatId,
                $this->formatter->formatError(__('telegram_pos.auth_failed', [], 'en'), 'en')
            );
        }
        
        return response('OK');
    }
    
    /**
     * Handle callback queries (inline button presses)
     */
    protected function handleCallbackQuery(array $callback)
    {
        $chatId = $callback['message']['chat']['id'] ?? null;
        $callbackData = $callback['data'] ?? '';
        $callbackId = $callback['id'];
        
        // Answer callback to remove loading state
        $this->answerCallbackQuery($callbackId);
        
        if (!$chatId) {
            return response('OK');
        }
        
        $session = $this->posService->getSession($chatId);
        
        if (!$session) {
            return response('OK');
        }
        
        $lang = $session->language;
        
        // Handle language selection
        if (str_starts_with($callbackData, 'lang:')) {
            $language = substr($callbackData, 5);
            $this->posService->setUserLanguage($chatId, $language);
            
            $this->sendMessage(
                $chatId,
                __('telegram_pos.language_changed', [], $language),
                $this->keyboard->mainMenuKeyboard($language)
            );
        }
        
        // More callback handlers will be added in Phase 2 & 3
        
        return response('OK');
    }
    
    /**
     * Show language selection
     */
    protected function showLanguageSelection(int $chatId, $session)
    {
        $lang = $session ? $session->language : 'en';
        
        $this->sendMessage(
            $chatId,
            __('telegram_pos.select_language', [], $lang),
            $this->keyboard->languageSelectionKeyboard(),
            'inline'
        );
        
        return response('OK');
    }
    
    /**
     * Show help
     */
    protected function showHelp(int $chatId, $session)
    {
        $lang = $session ? $session->language : 'en';
        
        $this->sendMessage(
            $chatId,
            $this->formatter->formatHelp($lang)
        );
        
        return response('OK');
    }
    
    /**
     * Handle start shift (placeholder for Phase 2)
     */
    protected function handleStartShift(int $chatId, $session)
    {
        $lang = $session ? $session->language : 'en';
        
        $this->sendMessage(
            $chatId,
            "🔜 Start shift feature coming in Phase 2!"
        );
        
        return response('OK');
    }
    
    /**
     * Handle my shift (placeholder for Phase 2)
     */
    protected function handleMyShift(int $chatId, $session)
    {
        $lang = $session ? $session->language : 'en';
        
        $this->sendMessage(
            $chatId,
            "🔜 My shift feature coming in Phase 2!"
        );
        
        return response('OK');
    }
    
    /**
     * Handle record transaction (placeholder for Phase 3)
     */
    protected function handleRecordTransaction(int $chatId, $session)
    {
        $this->sendMessage(
            $chatId,
            "🔜 Record transaction feature coming in Phase 3!"
        );
        
        return response('OK');
    }
    
    /**
     * Handle close shift (placeholder for Phase 2)
     */
    protected function handleCloseShift(int $chatId, $session)
    {
        $this->sendMessage(
            $chatId,
            "🔜 Close shift feature coming in Phase 2!"
        );
        
        return response('OK');
    }
    
    /**
     * Handle conversation states (placeholder for Phase 2 & 3)
     */
    protected function handleConversationState($session, string $text)
    {
        // Will be implemented in Phase 2 & 3
        return response('OK');
    }
    
    /**
     * Send a message to Telegram
     */
    protected function sendMessage(int $chatId, string $text, ?array $keyboard = null, string $keyboardType = 'reply')
    {
        if (!$this->botToken) {
            Log::error('TELEGRAM_POS_BOT_TOKEN not set');
            return false;
        }
        
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";
        
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        
        if ($keyboard) {
            if ($keyboardType === 'inline') {
                $payload['reply_markup'] = json_encode($keyboard);
            } else {
                $payload['reply_markup'] = json_encode($keyboard);
            }
        }
        
        try {
            $response = Http::post($url, $payload);
            
            if ($response->failed()) {
                Log::error('sendMessage failed', [
                    'response' => $response->body(),
                    'payload' => $payload
                ]);
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            Log::error('sendMessage exception: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Answer callback query
     */
    protected function answerCallbackQuery(string $callbackId, ?string $text = null)
    {
        if (!$this->botToken) {
            return false;
        }
        
        $url = "https://api.telegram.org/bot{$this->botToken}/answerCallbackQuery";
        
        $payload = ['callback_query_id' => $callbackId];
        
        if ($text) {
            $payload['text'] = $text;
        }
        
        try {
            Http::post($url, $payload);
            return true;
        } catch (\Exception $e) {
            Log::error('answerCallbackQuery exception: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Set webhook URL
     */
    public function setWebhook(Request $request)
    {
        $webhookUrl = config('services.telegram_pos_bot.webhook_url');
        
        if (!$webhookUrl) {
            return response()->json(['error' => 'Webhook URL not configured'], 400);
        }
        
        $url = "https://api.telegram.org/bot{$this->botToken}/setWebhook";
        
        try {
            $response = Http::post($url, [
                'url' => $webhookUrl,
            ]);
            
            return response()->json($response->json());
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Get webhook info
     */
    public function getWebhookInfo()
    {
        $url = "https://api.telegram.org/bot{$this->botToken}/getWebhookInfo";
        
        try {
            $response = Http::get($url);
            return response()->json($response->json());
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}

