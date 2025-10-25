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

            case '💵 record transaction':
            case '💵 tranzaksiyani yozish':
            case '💵 записать транзакцию':
                return $this->handleRecordTransaction($chatId, $session);

            case '🔴 close shift':
            case '🔴 smenani yopish':
            case '🔴 закрыть смену':
                return $this->handleCloseShift($chatId, $session);

            case '📊 reports':
            case '📊 hisobotlar':
            case '📊 отчеты':
                return $this->showReportsMenu($chatId, $session);

            default:
                // Handle conversation states
                if ($session) {
                    return $this->handleConversationState($session, $text);
                }

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

        // Handle transaction type selection
        if (str_starts_with($callbackData, 'txn_type:')) {
            return $this->handleTransactionTypeSelection($session, $callbackData, $chatId);
        }

        // Handle currency selection
        if (str_starts_with($callbackData, 'currency:')) {
            return $this->handleCurrencySelection($session, $callbackData, $chatId);
        }

        // Handle category selection
        if (str_starts_with($callbackData, 'category:')) {
            return $this->handleCategorySelection($session, $callbackData, $chatId);
        }

        // Handle notes skip
        if ($callbackData === 'notes:skip') {
            return $this->handleNotesSkip($session, $chatId);
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
            case 'drawer_balances':
                return $this->handleDrawerBalancesReport($chatId, $user, $lang);
            case 'today':
                return $this->handleTodayReport($chatId, $user, $lang);
            case 'shifts':
                return $this->handleShiftsReport($chatId, $user, $lang);
            case 'transactions':
                return $this->handleTransactionsReport($chatId, $user, $lang);
            case 'locations':
                return $this->handleLocationsReport($chatId, $user, $lang);
            case 'financial_range':
                return $this->handleFinancialRangeReport($chatId, $user, $lang);
            case 'discrepancies':
                return $this->handleDiscrepanciesReport($chatId, $user, $lang);
            case 'executive':
                return $this->handleExecutiveDashboard($chatId, $user, $lang);
            case 'currency_exchange':
                return $this->handleCurrencyExchangeReport($chatId, $user, $lang);
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
        $data = $this->reportService->getTransactionActivity($user, Carbon::today(), Carbon::today());
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

        $helpText = __('telegram_pos.help_text', [], $lang) ??
            "ℹ️ <b>POS Bot Help</b>\n\n" .
            "🟢 <b>Start Shift</b> - Begin your cashier shift\n" .
            "📊 <b>My Shift</b> - View current shift details\n" .
            "💵 <b>Record Transaction</b> - Record a cash transaction\n" .
            "🔴 <b>Close Shift</b> - End your shift and count cash\n" .
            "📊 <b>Reports</b> - View reports (managers only)\n";

        $this->sendMessage($chatId, $helpText);

        return response('OK');
    }

    /**
     * Handle start shift
     */
    protected function handleStartShift(int $chatId, $session)
    {
        if (!$session || !$session->user_id) {
            $this->sendMessage($chatId, __('telegram_pos.unauthorized', [], 'en'));
            return response('OK');
        }

        $lang = $session->language;
        $user = User::with("locations")->find($session->user_id);

        // Check if user already has an open shift
        $existingShift = \App\Models\CashierShift::getUserOpenShift($user->id);

        if ($existingShift) {
            // Ensure cashDrawer.location relationship is loaded (not the string column)
            $existingShift->load('cashDrawer.location');

            $this->sendMessage(
                $chatId,
                __('telegram_pos.shift_already_open', ['drawer' => $existingShift->cashDrawer->name], $lang)
            );
            return response('OK');
        }

        try {
            // Use the existing StartShiftAction to start shift
            $shift = app(\App\Actions\StartShiftAction::class)->quickStart($user);

            // Ensure relationships are loaded
            $shift->load('cashDrawer.location', 'beginningSaldos');

            // Send success message with shift details
            $message = "✅ <b>" . __('telegram_pos.shift_started', [], $lang) . "</b>\n\n";
            $message .= "🆔 Shift ID: {$shift->id}\n";

            if ($shift->cashDrawer && $shift->cashDrawer->location) {
                $message .= "📍 " . __('telegram_pos.location', [], $lang) . ": {$shift->cashDrawer->location->name}\n";
            }

            if ($shift->cashDrawer) {
                $message .= "🗄️ " . __('telegram_pos.drawer', [], $lang) . ": {$shift->cashDrawer->name}\n";
            }

            $message .= "🕐 " . __('telegram_pos.opened', [], $lang) . ": " . $shift->opened_at->format('H:i');

            $this->sendMessage($chatId, $message);

            // Log activity
            app(\App\Services\TelegramPosService::class)->logActivity(
                $user->id,
                'shift_started',
                "Shift #{$shift->id} started via Telegram",
                $session->telegram_user_id
            );

        } catch (\Exception $e) {
            $this->sendMessage(
                $chatId,
                "❌ " . __('telegram_pos.shift_start_failed', ['reason' => $e->getMessage()], $lang)
            );

            Log::error('Telegram POS: Start shift failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
        }

        return response('OK');
    }

    /**
     * Handle my shift - show current shift status
     */
    protected function handleMyShift(int $chatId, $session)
    {
        if (!$session || !$session->user_id) {
            $this->sendMessage($chatId, __('telegram_pos.unauthorized', [], 'en'));
            return response('OK');
        }

        $lang = $session->language;
        $user = $session->user;

        // Get user's open shift
        $shift = \App\Models\CashierShift::getUserOpenShift($user->id);

        if (!$shift) {
            $this->sendMessage(
                $chatId,
                __('telegram_pos.no_open_shift', [], $lang) ?? "You don't have an open shift."
            );
            return response('OK');
        }

        // Load relationships - avoid attribute conflict with location field
        $shift->loadMissing(['cashDrawer', 'beginningSaldos']);
        if ($shift->cashDrawer) {
            $shift->cashDrawer->loadMissing('location');
        }

        // Build shift details message
        $message = "📊 <b>" . __('telegram_pos.my_shift', [], $lang) . "</b>\n\n";
        $message .= "🆔 Shift ID: {$shift->id}\n";

        if ($shift->cashDrawer && $shift->cashDrawer->location_id) {
            $loc = $shift->cashDrawer->getRelation('location');
            $message .= "📍 " . __('telegram_pos.location', [], $lang) . ": {$loc->name}\n";
        }

        if ($shift->cashDrawer) {
            $message .= "🗄️ " . __('telegram_pos.drawer', [], $lang) . ": {$shift->cashDrawer->name}\n";
        }

        $message .= "🕐 " . __('telegram_pos.opened', [], $lang) . ": " . $shift->opened_at->format('H:i') . "\n";
        $message .= "⏱️ " . __('telegram_pos.duration', [], $lang) . ": " . $shift->opened_at->diffForHumans(null, true) . "\n\n";

        $message .= "💰 <b>" . __('telegram_pos.running_balance', [], $lang) . "</b>\n";
        $balances = $shift->getAllRunningBalances();
        foreach ($balances as $balance) {
            $message .= "  {$balance['formatted']}\n";
        }

        $this->sendMessage($chatId, $message);

        // Log activity
        app(\App\Services\TelegramPosService::class)->logActivity(
            $user->id,
            'shift_viewed',
            "Viewed shift #{$shift->id} via Telegram",
            $session->telegram_user_id
        );

        return response('OK');
    }

    /**
     * Handle record transaction - initiate transaction flow
     */
    protected function handleRecordTransaction(int $chatId, $session)
    {
        if (!$session || !$session->user_id) {
            $this->sendMessage($chatId, __('telegram_pos.unauthorized', [], 'en'));
            return response('OK');
        }

        $lang = $session->language;
        $user = $session->user;

        // Check if user has open shift
        $shift = \App\Models\CashierShift::getUserOpenShift($user->id);

        if (!$shift) {
            $this->sendMessage(
                $chatId,
                __('telegram_pos.shift_not_open', [], $lang) ?? "You don't have an open shift."
            );
            return response('OK');
        }

        // Start transaction recording flow
        DB::table('telegram_pos_sessions')
            ->where('id', $session->id)
            ->update([
                'state' => 'recording_transaction',
                'data' => json_encode([
                    'shift_id' => $shift->id,
                    'transaction_step' => 'type',
                    'transaction_data' => []
                ]),
                'updated_at' => now()
            ]);

        // Ask for transaction type
        $this->sendMessage(
            $chatId,
            __('telegram_pos.select_transaction_type', [], $lang) ?? "Select transaction type:",
            $this->keyboard->transactionTypeKeyboard($lang),
            'inline'
        );

        return response('OK');
    }

    /**
     * Handle close shift - initiate multi-step flow
     */
    protected function handleCloseShift(int $chatId, $session)
    {
        if (!$session || !$session->user_id) {
            $this->sendMessage($chatId, __('telegram_pos.unauthorized', [], 'en'));
            return response('OK');
        }

        $lang = $session->language;
        $user = $session->user;

        // Get user's open shift
        $shift = \App\Models\CashierShift::getUserOpenShift($user->id);

        if (!$shift) {
            $this->sendMessage(
                $chatId,
                __('telegram_pos.no_open_shift', [], $lang) ?? "You don't have an open shift."
            );
            return response('OK');
        }

        // Get all currencies used in this shift
        $usedCurrencies = $shift->getUsedCurrencies();
        $beginningSaldos = $shift->beginningSaldos->pluck('currency');
        $allCurrencies = $usedCurrencies->merge($beginningSaldos)->unique();

        if ($allCurrencies->isEmpty()) {
            // No currencies, just close the shift
            try {
                app(\App\Actions\CloseShiftAction::class)->execute($shift, $user, [
                    'counted_end_saldos' => [],
                    'notes' => 'Closed via Telegram - No transactions'
                ]);

                $this->sendMessage($chatId, __('telegram_pos.shift_closed', [], $lang) ?? "Shift closed successfully.");
                app(\App\Services\TelegramPosService::class)->logActivity(
                    $user->id,
                    'shift_closed',
                    "Shift #{$shift->id} closed via Telegram",
                    $session->telegram_user_id
                );

            } catch (\Exception $e) {
                $this->sendMessage($chatId, "❌ " . $e->getMessage());
            }

            return response('OK');
        }

        // Start close shift flow
        DB::table('telegram_pos_sessions')
            ->where('id', $session->id)
            ->update([
                'state' => 'closing_shift',
                'data' => json_encode([
                    'shift_id' => $shift->id,
                    'currencies' => $allCurrencies->values()->toArray(),
                    'current_currency_index' => 0,
                    'counted_amounts' => []
                ]),
                'updated_at' => now()
            ]);

        // Ask for first currency amount
        $firstCurrency = $allCurrencies->first();
        $expectedAmount = $shift->getNetBalanceForCurrency($firstCurrency);

        $message = __('telegram_pos.enter_counted_amount', ['currency' => $firstCurrency->value], $lang)
            ?? "Enter counted amount for {$firstCurrency->value}:";
        $message .= "\n\n💰 " . __('telegram_pos.running_balance', [], $lang) . ": " . $firstCurrency->formatAmount($expectedAmount);

        $this->sendMessage($chatId, $message, $this->keyboard->cancelKeyboard($lang));

        return response('OK');
    }

    /**
     * Handle conversation states for multi-step flows
     */
    protected function handleConversationState($session, string $text)
    {
        $chatId = $session->chat_id;
        $lang = $session->language;
        $state = $session->state;

        // Handle cancel
        if (in_array(strtolower($text), ['cancel', 'отмена', 'bekor qilish', '❌ cancel', '❌ отмена', '❌ bekor qilish'])) {
            DB::table('telegram_pos_sessions')
                ->where('id', $session->id)
                ->update([
                    'state' => 'authenticated',
                    'data' => null,
                    'updated_at' => now()
                ]);

            $this->sendMessage(
                $chatId,
                __('telegram_pos.cancelled', [], $lang) ?? 'Cancelled',
                $this->keyboard->mainMenuKeyboard($lang, $session->user)
            );

            return response('OK');
        }

        // Handle closing shift flow
        if ($state === 'closing_shift') {
            return $this->handleCloseShiftFlow($session, $text);
        }

        // Handle transaction recording flow
        if ($state === 'recording_transaction') {
            return $this->handleTransactionRecordingFlow($session, $text);
        }

        return response('OK');
    }

    /**
     * Handle close shift flow - collecting counted amounts
     */
    protected function handleCloseShiftFlow($session, string $text)
    {
        $chatId = $session->chat_id;
        $lang = $session->language;
        $user = $session->user;

        $data = json_decode($session->data, true);
        $shiftId = $data['shift_id'] ?? null;
        $currencies = collect($data['currencies'] ?? []);
        $currentIndex = $data['current_currency_index'] ?? 0;
        $countedAmounts = $data['counted_amounts'] ?? [];

        // Get shift
        $shift = \App\Models\CashierShift::find($shiftId);

        if (!$shift) {
            $this->sendMessage($chatId, __('telegram_pos.shift_not_found', [], $lang) ?? 'Shift not found');
            DB::table('telegram_pos_sessions')->where('id', $session->id)->update(['state' => 'authenticated', 'data' => null]);
            return response('OK');
        }

        // Validate amount input
        $amount = trim($text);
        if (!is_numeric($amount) || $amount < 0) {
            $this->sendMessage($chatId, __('telegram_pos.invalid_amount', [], $lang) ?? 'Invalid amount');
            return response('OK');
        }

        // Store counted amount for current currency
        $currentCurrency = $currencies[$currentIndex];
        $countedAmounts[] = [
            'currency' => is_string($currentCurrency) ? $currentCurrency : $currentCurrency->value,
            'counted_end_saldo' => (float) $amount,
            'denominations' => [],
        ];

        // Move to next currency
        $nextIndex = $currentIndex + 1;

        if ($nextIndex < $currencies->count()) {
            // Ask for next currency
            DB::table('telegram_pos_sessions')
                ->where('id', $session->id)
                ->update([
                    'data' => json_encode([
                        'shift_id' => $shiftId,
                        'currencies' => $currencies->toArray(),
                        'current_currency_index' => $nextIndex,
                        'counted_amounts' => $countedAmounts
                    ]),
                    'updated_at' => now()
                ]);

            $nextCurrency = $currencies[$nextIndex];
            $currencyEnum = is_string($nextCurrency) ? \App\Enums\Currency::from($nextCurrency) : $nextCurrency;
            $expectedAmount = $shift->getNetBalanceForCurrency($currencyEnum);

            $message = __('telegram_pos.enter_counted_amount', ['currency' => $currencyEnum->value], $lang)
                ?? "Enter counted amount for {$currencyEnum->value}:";
            $message .= "\n\n💰 " . __('telegram_pos.running_balance', [], $lang) . ": " . $currencyEnum->formatAmount($expectedAmount);

            $this->sendMessage($chatId, $message, $this->keyboard->cancelKeyboard($lang));
        } else {
            // All amounts collected, close the shift
            try {
                $closedShift = app(\App\Actions\CloseShiftAction::class)->execute($shift, $user, [
                    'counted_end_saldos' => $countedAmounts,
                    'notes' => 'Closed via Telegram Bot',
                ]);

                // Send success message
                $message = "✅ <b>" . __('telegram_pos.shift_closed', [], $lang) . "</b>\n\n";
                $message .= "🆔 Shift ID: {$shift->id}\n";
                $message .= "🕐 " . __('telegram_pos.closed', [], $lang) . ": " . now()->format('H:i');

                $this->sendMessage($chatId, $message, $this->keyboard->mainMenuKeyboard($lang, $user));

                // Log activity
                app(\App\Services\TelegramPosService::class)->logActivity(
                    $user->id,
                    'shift_closed',
                    "Shift #{$shift->id} closed via Telegram",
                    $session->telegram_user_id
                );

                // Reset session state
                DB::table('telegram_pos_sessions')
                    ->where('id', $session->id)
                    ->update(['state' => 'authenticated', 'data' => null, 'updated_at' => now()]);

            } catch (\Exception $e) {
                $this->sendMessage(
                    $chatId,
                    "❌ " . $e->getMessage(),
                    $this->keyboard->mainMenuKeyboard($lang, $user)
                );

                Log::error('Telegram POS: Close shift failed', [
                    'user_id' => $user->id,
                    'shift_id' => $shiftId,
                    'error' => $e->getMessage()
                ]);

                // Reset session state
                DB::table('telegram_pos_sessions')
                    ->where('id', $session->id)
                    ->update(['state' => 'authenticated', 'data' => null, 'updated_at' => now()]);
            }
        }

        return response('OK');
    }

    /**
     * Handle transaction recording flow - text input
     */
    protected function handleTransactionRecordingFlow($session, string $text)
    {
        $chatId = $session->chat_id;
        $lang = $session->language;
        $user = $session->user;

        $data = json_decode($session->data, true);
        $step = $data['transaction_step'] ?? null;
        $transactionData = $data['transaction_data'] ?? [];

        switch ($step) {
            case 'amount':
                // Validate amount
                if (!is_numeric($text) || $text <= 0) {
                    $this->sendMessage($chatId, __('telegram_pos.invalid_amount', [], $lang) ?? 'Invalid amount');
                    return response('OK');
                }

                $transactionData['amount'] = (float) $text;
                DB::table('telegram_pos_sessions')
                    ->where('id', $session->id)
                    ->update([
                        'data' => json_encode([
                            'shift_id' => $data['shift_id'],
                            'transaction_step' => 'currency',
                            'transaction_data' => $transactionData
                        ]),
                        'updated_at' => now()
                    ]);

                // Ask for currency
                $this->sendMessage(
                    $chatId,
                    __('telegram_pos.select_currency', [], $lang) ?? "Select currency:",
                    $this->keyboard->currencySelectionKeyboard($lang),
                    'inline'
                );
                break;

            case 'out_amount':
                // Validate out amount for complex transaction
                if (!is_numeric($text) || $text <= 0) {
                    $this->sendMessage($chatId, __('telegram_pos.invalid_amount', [], $lang) ?? 'Invalid amount');
                    return response('OK');
                }

                $transactionData['out_amount'] = (float) $text;
                DB::table('telegram_pos_sessions')
                    ->where('id', $session->id)
                    ->update([
                        'data' => json_encode([
                            'shift_id' => $data['shift_id'],
                            'transaction_step' => 'out_currency',
                            'transaction_data' => $transactionData
                        ]),
                        'updated_at' => now()
                    ]);

                // Ask for out currency
                $this->sendMessage(
                    $chatId,
                    __('telegram_pos.select_out_currency', [], $lang) ?? "Select outgoing currency:",
                    $this->keyboard->currencySelectionKeyboard($lang),
                    'inline'
                );
                break;

            case 'notes':
                // Record transaction with notes
                return $this->recordTransaction($session, $chatId, $text);

            default:
                $this->sendMessage($chatId, __('telegram_pos.error_occurred', [], $lang) ?? 'An error occurred');
                DB::table('telegram_pos_sessions')
                    ->where('id', $session->id)
                    ->update(['state' => 'authenticated', 'data' => null, 'updated_at' => now()]);
        }

        return response('OK');
    }

    /**
     * Handle transaction type selection
     */
    protected function handleTransactionTypeSelection($session, string $callbackData, int $chatId)
    {
        $lang = $session->language;
        $type = substr($callbackData, 9); // Remove 'txn_type:'

        if ($type === 'cancel') {
            $this->resetTransactionFlow($session);
            $this->sendMessage(
                $chatId,
                __('telegram_pos.cancelled', [], $lang) ?? 'Cancelled',
                $this->keyboard->mainMenuKeyboard($lang, $session->user)
            );
            return response('OK');
        }

        $data = json_decode($session->data, true);
        $transactionData = $data['transaction_data'] ?? [];
        $transactionData['type'] = $type;

        DB::table('telegram_pos_sessions')
            ->where('id', $session->id)
            ->update([
                'data' => json_encode([
                    'shift_id' => $data['shift_id'],
                    'transaction_step' => 'amount',
                    'transaction_data' => $transactionData
                ]),
                'updated_at' => now()
            ]);

        // Ask for amount
        $this->sendMessage(
            $chatId,
            __('telegram_pos.enter_amount', [], $lang) ?? "Enter amount:",
            $this->keyboard->cancelKeyboard($lang)
        );

        return response('OK');
    }

    /**
     * Handle currency selection
     */
    protected function handleCurrencySelection($session, string $callbackData, int $chatId)
    {
        $lang = $session->language;
        $currency = substr($callbackData, 9); // Remove 'currency:'

        if ($currency === 'cancel') {
            $this->resetTransactionFlow($session);
            $this->sendMessage(
                $chatId,
                __('telegram_pos.cancelled', [], $lang) ?? 'Cancelled',
                $this->keyboard->mainMenuKeyboard($lang, $session->user)
            );
            return response('OK');
        }

        $data = json_decode($session->data, true);
        $transactionData = $data['transaction_data'] ?? [];
        $step = $data['transaction_step'] ?? null;

        if ($step === 'currency') {
            $transactionData['currency'] = $currency;

            // Check if complex transaction
            if ($transactionData['type'] === 'in_out') {
                DB::table('telegram_pos_sessions')
                    ->where('id', $session->id)
                    ->update([
                        'data' => json_encode([
                            'shift_id' => $data['shift_id'],
                            'transaction_step' => 'out_amount',
                            'transaction_data' => $transactionData
                        ]),
                        'updated_at' => now()
                    ]);
                $this->sendMessage($chatId, __('telegram_pos.enter_out_amount', [], $lang) ?? "Enter outgoing amount:", $this->keyboard->cancelKeyboard($lang));
            } else {
                DB::table('telegram_pos_sessions')
                    ->where('id', $session->id)
                    ->update([
                        'data' => json_encode([
                            'shift_id' => $data['shift_id'],
                            'transaction_step' => 'category',
                            'transaction_data' => $transactionData
                        ]),
                        'updated_at' => now()
                    ]);
                $this->sendMessage($chatId, __('telegram_pos.select_category', [], $lang) ?? "Select category:", $this->keyboard->categorySelectionKeyboard($lang), 'inline');
            }
        } elseif ($step === 'out_currency') {
            $transactionData['out_currency'] = $currency;
            DB::table('telegram_pos_sessions')
                ->where('id', $session->id)
                ->update([
                    'data' => json_encode([
                        'shift_id' => $data['shift_id'],
                        'transaction_step' => 'category',
                        'transaction_data' => $transactionData
                    ]),
                    'updated_at' => now()
                ]);
            $this->sendMessage($chatId, __('telegram_pos.select_category', [], $lang) ?? "Select category:", $this->keyboard->categorySelectionKeyboard($lang), 'inline');
        }

        return response('OK');
    }

    /**
     * Handle category selection
     */
    protected function handleCategorySelection($session, string $callbackData, int $chatId)
    {
        $lang = $session->language;
        $category = substr($callbackData, 9); // Remove 'category:'

        if ($category === 'cancel') {
            $this->resetTransactionFlow($session);
            $this->sendMessage(
                $chatId,
                __('telegram_pos.cancelled', [], $lang) ?? 'Cancelled',
                $this->keyboard->mainMenuKeyboard($lang, $session->user)
            );
            return response('OK');
        }

        $data = json_decode($session->data, true);
        $transactionData = $data['transaction_data'] ?? [];
        $transactionData['category'] = $category;

        DB::table('telegram_pos_sessions')
            ->where('id', $session->id)
            ->update([
                'data' => json_encode([
                    'shift_id' => $data['shift_id'],
                    'transaction_step' => 'notes',
                    'transaction_data' => $transactionData
                ]),
                'updated_at' => now()
            ]);

        // Ask for notes
        $this->sendMessage(
            $chatId,
            __('telegram_pos.add_notes', [], $lang) ?? "Add notes (or skip):",
            $this->keyboard->skipNotesKeyboard($lang),
            'inline'
        );

        return response('OK');
    }

    /**
     * Handle notes skip
     */
    protected function handleNotesSkip($session, int $chatId)
    {
        $lang = $session->language;

        // Record transaction without notes
        return $this->recordTransaction($session, $chatId, null);
    }

    /**
     * Record the transaction using RecordTransactionAction
     */
    protected function recordTransaction($session, int $chatId, ?string $notes)
    {
        $lang = $session->language;
        $user = $session->user;

        $data = json_decode($session->data, true);
        $shiftId = $data['shift_id'] ?? null;
        $transactionData = $data['transaction_data'] ?? [];

        $shift = \App\Models\CashierShift::find($shiftId);

        if (!$shift || !$shift->isOpen()) {
            $this->sendMessage($chatId, __('telegram_pos.shift_not_open', [], $lang) ?? "Shift is not open");
            $this->resetTransactionFlow($session);
            return response('OK');
        }

        // Prepare transaction data for RecordTransactionAction
        $txnData = [
            'type' => $transactionData['type'],
            'amount' => $transactionData['amount'],
            'currency' => $transactionData['currency'],
            'category' => $transactionData['category'] ?? null,
            'notes' => $notes,
        ];

        // Add out currency and amount for complex transactions
        if ($transactionData['type'] === 'in_out') {
            $txnData['out_amount'] = $transactionData['out_amount'] ?? null;
            $txnData['out_currency'] = $transactionData['out_currency'] ?? null;
        }

        try {
            // Record the transaction using existing action
            app(\App\Services\TelegramPosService::class)->logActivity(
                $user->id,
                'transaction_started',
                'Recording transaction via Telegram',
                $session->telegram_user_id
            );

            $transaction = app(\App\Actions\RecordTransactionAction::class)->execute($shift, $user, $txnData);

            // Send success message
            $message = "✅ <b>" . __('telegram_pos.transaction_recorded', [], $lang) . "</b>\n\n";
            $message .= "🆔 ID: {$transaction->id}\n";

            if ($transactionData['type'] === 'in_out') {
                $message .= "💸 {$transactionData['amount']} {$transactionData['currency']} → {$transactionData['out_amount']} {$transactionData['out_currency']}\n";
            } else {
                $typeLabel = $transactionData['type'] === 'in' ? '💰 In' : '💸 Out';
                $message .= "{$typeLabel}: {$transactionData['amount']} {$transactionData['currency']}\n";
            }

            if (isset($transactionData['category'])) {
                $message .= "📂 " . ucfirst($transactionData['category']) . "\n";
            }

            if ($notes) {
                $message .= "📝 {$notes}\n";
            }

            // Show updated running balance
            $message .= "\n💵 " . __('telegram_pos.running_balance', [], $lang) . ":\n";
            $balances = $shift->fresh()->getAllRunningBalances();
            foreach ($balances as $balance) {
                $message .= "  {$balance['formatted']}\n";
            }

            $this->sendMessage($chatId, $message, $this->keyboard->mainMenuKeyboard($lang, $user));

            // Log success
            app(\App\Services\TelegramPosService::class)->logActivity(
                $user->id,
                'transaction_recorded',
                "Transaction #{$transaction->id} recorded via Telegram",
                $session->telegram_user_id
            );

            // Reset flow
            $this->resetTransactionFlow($session);

        } catch (\Exception $e) {
            $this->sendMessage(
                $chatId,
                "❌ " . __('telegram_pos.transaction_failed', ['reason' => $e->getMessage()], $lang),
                $this->keyboard->mainMenuKeyboard($lang, $user)
            );

            Log::error('Telegram POS: Record transaction failed', [
                'user_id' => $user->id,
                'shift_id' => $shiftId,
                'error' => $e->getMessage()
            ]);

            $this->resetTransactionFlow($session);
        }

        return response('OK');
    }

    /**
     * Reset transaction recording flow
     */
    protected function resetTransactionFlow($session)
    {
        DB::table('telegram_pos_sessions')
            ->where('id', $session->id)
            ->update([
                'state' => 'authenticated',
                'data' => null,
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

    /**
     * Handle Financial Range Report
     */
    protected function handleFinancialRangeReport(int $chatId, User $user, string $lang)
    {
        $service = app(\App\Services\AdvancedReportService::class);

        // Default to this month
        $data = $service->getDateRangeFinancialSummary(
            $user,
            \Carbon\Carbon::now()->startOfMonth(),
            \Carbon\Carbon::now()->endOfMonth()
        );

        if (isset($data['error'])) {
            $this->sendMessage($chatId, "❌ " . $data['error']);
            return response('OK');
        }

        $formatter = app(\App\Services\TelegramReportFormatter::class);
        $message = $formatter->formatFinancialRangeSummary($data, $lang);
        $this->sendMessage($chatId, $message);

        return response('OK');
    }

    /**
     * Handle Discrepancies Report
     */
    protected function handleDiscrepanciesReport(int $chatId, User $user, string $lang)
    {
        $service = app(\App\Services\AdvancedReportService::class);

        // Default to this month
        $data = $service->getDiscrepancyVarianceReport(
            $user,
            \Carbon\Carbon::now()->startOfMonth(),
            \Carbon\Carbon::now()->endOfMonth()
        );

        if (isset($data['error'])) {
            $this->sendMessage($chatId, "❌ " . $data['error']);
            return response('OK');
        }

        $formatter = app(\App\Services\TelegramReportFormatter::class);
        $message = $formatter->formatDiscrepancyReport($data, $lang);
        $this->sendMessage($chatId, $message);

        return response('OK');
    }

    /**
     * Handle Executive Dashboard
     */
    protected function handleExecutiveDashboard(int $chatId, User $user, string $lang)
    {
        $service = app(\App\Services\AdvancedReportService::class);

        // Default to today
        $data = $service->getExecutiveSummaryDashboard($user, \App\Enums\ReportPeriod::TODAY);

        if (isset($data['error'])) {
            $this->sendMessage($chatId, "❌ " . $data['error']);
            return response('OK');
        }

        $formatter = app(\App\Services\TelegramReportFormatter::class);
        $message = $formatter->formatExecutiveDashboard($data, $lang);
        $this->sendMessage($chatId, $message);

        return response('OK');
    }

    /**
     * Handle Currency Exchange Report
     */
    protected function handleCurrencyExchangeReport(int $chatId, User $user, string $lang)
    {
        $service = app(\App\Services\AdvancedReportService::class);

        // Default to this week
        $data = $service->getCurrencyExchangeReport(
            $user,
            \Carbon\Carbon::now()->startOfWeek(),
            \Carbon\Carbon::now()->endOfWeek()
        );

        if (isset($data['error'])) {
            $this->sendMessage($chatId, "❌ " . $data['error']);
            return response('OK');
        }

        $formatter = app(\App\Services\TelegramReportFormatter::class);
        $message = $formatter->formatCurrencyExchangeReport($data, $lang);
        $this->sendMessage($chatId, $message);

        return response('OK');
    }

    /**
     * Handle drawer balances report
     */
    protected function handleDrawerBalancesReport(int $chatId, User $user, string $lang)
    {
        // Get data from service
        $data = $this->reportService->getDrawerBalances($user);

        // Check for errors
        if (isset($data['error'])) {
            $this->sendMessage($chatId, "❌ " . $data['error']);
            return response('OK');
        }

        // Format and send message
        $message = $this->reportFormatter->formatDrawerBalances($data, $lang);

        // Add refresh button
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🔄 ' . __('telegram_pos.refresh', [], $lang),
                     'callback_data' => 'report:drawer_balances'],
                ],
                [
                    ['text' => '« ' . __('telegram_pos.back_to_reports', [], $lang),
                     'callback_data' => 'report:back'],
                ],
            ],
        ];

        $this->sendMessage($chatId, $message, $keyboard, 'inline');

        return response('OK');
    }
}
