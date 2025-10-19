<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\CashierShift;
use App\Services\TelegramKeyboardBuilder;
use App\Services\TelegramReportService;
use App\Services\TelegramReportFormatter;
use App\Actions\StartShiftAction;
use App\Actions\CloseShiftAction;
use App\Actions\RecordTransactionAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TelegramPosController extends Controller
{
    protected string $botToken;
    protected int $sessionTimeout = 15; // minutes

    public function __construct(
        protected TelegramKeyboardBuilder $keyboard,
        protected StartShiftAction $startShiftAction,
        protected CloseShiftAction $closeShiftAction,
        protected RecordTransactionAction $recordTransactionAction,
        protected TelegramReportService $reportService,
        protected TelegramReportFormatter $reportFormatter
    ) {
        $this->botToken = config('services.telegram_pos_bot.token');
        $this->sessionTimeout = config('services.telegram_pos_bot.session_timeout', 15);
    }

    /**
     * Handle incoming webhook from Telegram
     */
    public function handleWebhook(Request $request)
    {
        Log::info('POS Bot Webhook', ['data' => $request->all()]);

        // Handle callback query (inline keyboard buttons)
        if ($callback = $request->input('callback_query')) {
            return $this->handleCallbackQuery($callback);
        }

        // Handle regular message
        if ($message = $request->input('message')) {
            return $this->processMessage($message);
        }

        return response('OK');
    }

    /**
     * Process incoming messages
     */
    protected function processMessage(array $message)
    {
        $chatId = $message['chat']['id'] ?? null;
        $text = trim($message['text'] ?? '');
        $contact = $message['contact'] ?? null;

        if (!$chatId) {
            return response('OK');
        }

        // Handle contact sharing FIRST (before checking session)
        if ($contact) {
            return $this->handleContactShared($chatId, $contact);
        }

        // Get or create session
        $session = $this->getOrCreateSession($chatId);

        if (!$session) {
            $this->sendMessage($chatId, "Please share your contact to start.", $this->keyboard->phoneRequestKeyboard());
            return response('OK');
        }

        $lang = $session->language ?? 'en';
        $user = $session->user;

        // Route commands
        $textLower = mb_strtolower($text);

        switch ($textLower) {
            case '/start':
                return $this->handleStart($chatId, $session);

            case '📊 reports':
            case '📊 hisobotlar':
            case '📊 отчеты':
                return $this->showReportsMenu($chatId, $session);

            default:
                // Show main menu
                $this->sendMessage(
                    $chatId,
                    __('telegram_pos.main_menu', [], $lang),
                    $this->keyboard->mainMenuKeyboard($lang, $user)
                );
                break;
        }

        return response('OK');
    }

    /**
     * Handle callback query from inline keyboards
     */
    protected function handleCallbackQuery(array $callback)
    {
        $chatId = $callback['message']['chat']['id'] ?? null;
        $callbackData = $callback['data'] ?? '';
        $callbackId = $callback['id'] ?? '';

        if (!$chatId) {
            return response('OK');
        }

        // Answer callback to remove loading state
        $this->answerCallbackQuery($callbackId);

        $session = $this->getOrCreateSession($chatId);
        if (!$session) {
            $this->sendMessage($chatId, "Session not found. Please /start again.");
            return response('OK');
        }

        // Handle report callbacks
        if (str_starts_with($callbackData, 'report:')) {
            return $this->handleReportCallback($session, $callbackData, $chatId);
        }

        // Handle language selection
        if (str_starts_with($callbackData, 'lang:')) {
            $lang = substr($callbackData, 5);
            $this->updateSessionLanguage($session, $lang);
            $this->sendMessage($chatId, __('telegram_pos.language_set', [], $lang));
            return response('OK');
        }

        return response('OK');
    }

    /**
     * Handle start command
     */
    protected function handleStart(int $chatId, $session)
    {
        $lang = $session->language ?? 'en';
        $user = $session->user;

        $this->sendMessage(
            $chatId,
            __('telegram_pos.welcome', [], $lang),
            $this->keyboard->mainMenuKeyboard($lang, $user)
        );

        return response('OK');
    }

    /**
     * Show reports menu (manager only)
     */
    protected function showReportsMenu(int $chatId, $session)
    {
        if (!$session || !$session->user_id) {
            $this->sendMessage($chatId, __('telegram_pos.unauthorized', [], 'en'));
            return response('OK');
        }

        $lang = $session->language ?? 'en';
        $user = $session->user;

        // Check if user is manager
        if (!$user->hasAnyRole(['manager', 'super_admin'])) {
            $this->sendMessage($chatId, __('telegram_pos.manager_only', [], $lang));
            return response('OK');
        }

        $this->sendMessage(
            $chatId,
            __('telegram_pos.select_report_type', [], $lang),
            $this->keyboard->managerReportsKeyboard($lang),
            'inline'
        );

        return response('OK');
    }

    /**
     * Handle report callback
     */
    protected function handleReportCallback($session, string $callbackData, int $chatId)
    {
        $lang = $session->language ?? 'en';
        $user = $session->user;

        if (!$user->hasAnyRole(['manager', 'super_admin'])) {
            $this->sendMessage($chatId, __('telegram_pos.manager_only', [], $lang));
            return response('OK');
        }

        $reportType = substr($callbackData, 7); // Remove 'report:'

        switch ($reportType) {
            case 'today':
                return $this->handleTodayReport($chatId, $user, $lang);
            case 'shifts':
                return $this->handleShiftsReport($chatId, $user, $lang);
            case 'transactions':
                return $this->handleTransactionsReport($chatId, $user, $lang);
            case 'locations':
                return $this->handleLocationsReport($chatId, $user, $lang);
            case 'back':
                $this->sendMessage($chatId, __('telegram_pos.main_menu', [], $lang), $this->keyboard->mainMenuKeyboard($lang, $user));
                break;
        }

        return response('OK');
    }

    /**
     * Handle today's summary report
     */
    protected function handleTodayReport(int $chatId, User $user, string $lang)
    {
        $data = $this->reportService->getTodaySummary($user);

        if (isset($data['error'])) {
            $this->sendMessage($chatId, "❌ " . $data['error']);
            return response('OK');
        }

        $message = $this->reportFormatter->formatTodaySummary($data, $lang);
        $this->sendMessage($chatId, $message);

        return response('OK');
    }

    /**
     * Handle shift performance report
     */
    protected function handleShiftsReport(int $chatId, User $user, string $lang)
    {
        $data = $this->reportService->getShiftPerformance($user, Carbon::today());

        if (isset($data['error'])) {
            $this->sendMessage($chatId, "❌ " . $data['error']);
            return response('OK');
        }

        $message = $this->reportFormatter->formatShiftPerformance($data, $lang);
        $this->sendMessage($chatId, $message);

        return response('OK');
    }

    /**
     * Handle transaction activity report
     */
    protected function handleTransactionsReport(int $chatId, User $user, string $lang)
    {
        $data = $this->reportService->getTransactionActivity(
            $user,
            Carbon::today(),
            Carbon::today()
        );

        if (isset($data['error'])) {
            $this->sendMessage($chatId, "❌ " . $data['error']);
            return response('OK');
        }

        $message = $this->reportFormatter->formatTransactionActivity($data, $lang);
        $this->sendMessage($chatId, $message);

        return response('OK');
    }

    /**
     * Handle multi-location summary report
     */
    protected function handleLocationsReport(int $chatId, User $user, string $lang)
    {
        $data = $this->reportService->getMultiLocationSummary($user);

        if (isset($data['error'])) {
            $this->sendMessage($chatId, "❌ " . $data['error']);
            return response('OK');
        }

        $message = $this->reportFormatter->formatMultiLocationSummary($data, $lang);
        $this->sendMessage($chatId, $message);

        return response('OK');
    }

    /**
     * Handle contact shared
     */
    protected function handleContactShared(int $chatId, array $contact)
    {
        $phoneNumber = $contact['phone_number'] ?? null;
        $telegramUserId = $contact['user_id'] ?? null;

        if (!$phoneNumber && !$telegramUserId) {
            $this->sendMessage($chatId, "Invalid contact information.");
            return response('OK');
        }

        // Try to find user by Telegram ID first (most reliable)
        $user = null;
        if ($telegramUserId) {
            $user = User::where('telegram_pos_user_id', $telegramUserId)->first();
        }

        // If not found, try by phone number (with variations)
        if (!$user && $phoneNumber) {
            // Try exact match first
            $user = User::where('phone_number', $phoneNumber)->first();

            // Try without + prefix
            if (!$user) {
                $phoneWithoutPlus = ltrim($phoneNumber, '+');
                $user = User::where('phone_number', $phoneWithoutPlus)->first();
            }

            // Try with + prefix
            if (!$user && !str_starts_with($phoneNumber, '+')) {
                $user = User::where('phone_number', '+' . $phoneNumber)->first();
            }
        }

        if (!$user) {
            $this->sendMessage($chatId, "User not found. Please contact administrator.");
            return response('OK');
        }

        // Update Telegram user ID if not set
        if ($telegramUserId && !$user->telegram_pos_user_id) {
            $user->telegram_pos_user_id = $telegramUserId;
            $user->save();
        }

        // Create or update session
        $this->createSession($chatId, $user->id);

        $this->sendMessage(
            $chatId,
            __('telegram_pos.welcome', [], 'en'),
            $this->keyboard->mainMenuKeyboard('en', $user)
        );

        return response('OK');
    }

    /**
     * Get or create session for chat
     */
    protected function getOrCreateSession(int $chatId)
    {
        $session = DB::table('telegram_pos_sessions')
            ->where('chat_id', $chatId)
            ->where('updated_at', '>=', now()->subMinutes($this->sessionTimeout))
            ->first();

        if ($session && $session->user_id) {
            // Refresh session
            DB::table('telegram_pos_sessions')
                ->where('id', $session->id)
                ->update(['updated_at' => now()]);

            // Load user relationship
            $session->user = User::find($session->user_id);
            return $session;
        }

        return null;
    }

    /**
     * Create new session
     */
    protected function createSession(int $chatId, int $userId, string $language = 'en')
    {
        DB::table('telegram_pos_sessions')->updateOrInsert(
            ['chat_id' => $chatId],
            [
                'user_id' => $userId,
                'language' => $language,
                'state' => 'main_menu',
                'data' => json_encode([]),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * Update session language
     */
    protected function updateSessionLanguage($session, string $language)
    {
        DB::table('telegram_pos_sessions')
            ->where('id', $session->id)
            ->update([
                'language' => $language,
                'updated_at' => now()
            ]);
    }

    /**
     * Send message via Telegram API
     */
    protected function sendMessage(int $chatId, string $text, ?array $keyboard = null, string $keyboardType = 'reply')
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($keyboard) {
            if ($keyboardType === 'inline') {
                $params['reply_markup'] = json_encode($keyboard);
            } else {
                $params['reply_markup'] = json_encode($keyboard);
            }
        }

        $response = Http::post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $params);

        if (!$response->successful()) {
            Log::error('Telegram API Error', [
                'response' => $response->json(),
                'params' => $params
            ]);
        }

        return $response->json();
    }

    /**
     * Answer callback query
     */
    protected function answerCallbackQuery(string $callbackId, ?string $text = null)
    {
        $params = ['callback_query_id' => $callbackId];
        
        if ($text) {
            $params['text'] = $text;
        }

        Http::post("https://api.telegram.org/bot{$this->botToken}/answerCallbackQuery", $params);
    }
}
