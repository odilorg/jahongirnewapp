<?php

namespace App\Http\Controllers;

use App\Services\TelegramPosService;
use App\Services\TelegramMessageFormatter;
use App\Services\TelegramKeyboardBuilder;
use App\Actions\StartShiftAction;
use App\Actions\CloseShiftAction;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramPosController extends Controller
{
    protected $botToken;
    
    public function __construct(
        protected TelegramPosService $posService,
        protected TelegramMessageFormatter $formatter,
        protected TelegramKeyboardBuilder $keyboard,
        protected StartShiftAction $startShiftAction,
        protected CloseShiftAction $closeShiftAction
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
     * Handle start shift
     */
    protected function handleStartShift(int $chatId, $session)
    {
        if (!$session || !$session->user_id) {
            $this->sendMessage($chatId, $this->formatter->formatError(__('telegram_pos.unauthorized'), 'en'));
            return response('OK');
        }
        
        $lang = $session->language;
        $user = $session->user;
        
        // Check if user already has an open shift
        $existingShift = CashierShift::getUserOpenShift($user->id);
        
        if ($existingShift) {
            $this->sendMessage(
                $chatId,
                $this->formatter->formatError(
                    __('telegram_pos.shift_already_open', ['drawer' => $existingShift->cashDrawer->name], $lang),
                    $lang
                )
            );
            return response('OK');
        }
        
        try {
            // Use the existing StartShiftAction to start shift
            $shift = $this->startShiftAction->quickStart($user);
            
            // Send success message with shift details
            $this->sendMessage(
                $chatId,
                $this->formatter->formatShiftStarted($shift, $lang)
            );
            
            // Log activity
            $this->posService->logActivity($user->id, 'shift_started', "Shift #{$shift->id} started via Telegram", $session->telegram_user_id);
            
        } catch (\Exception $e) {
            $this->sendMessage(
                $chatId,
                $this->formatter->formatError(
                    __('telegram_pos.shift_start_failed', ['reason' => $e->getMessage()], $lang),
                    $lang
                )
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
            $this->sendMessage($chatId, $this->formatter->formatError(__('telegram_pos.unauthorized'), 'en'));
            return response('OK');
        }
        
        $lang = $session->language;
        $user = $session->user;
        
        // Get user's open shift
        $shift = CashierShift::getUserOpenShift($user->id);
        
        if (!$shift) {
            $this->sendMessage(
                $chatId,
                $this->formatter->formatNoOpenShift($lang)
            );
            return response('OK');
        }
        
        // Send shift details with running balances
        $this->sendMessage(
            $chatId,
            $this->formatter->formatShiftDetails($shift, $lang)
        );
        
        // Log activity
        $this->posService->logActivity($user->id, 'shift_viewed', "Viewed shift #{$shift->id} via Telegram", $session->telegram_user_id);
        
        return response('OK');
    }
    
    /**
     * Handle record transaction - initiate transaction flow
     */
    protected function handleRecordTransaction(int $chatId, $session)
    {
        if (!$session || !$session->user_id) {
            $this->sendMessage($chatId, $this->formatter->formatError(__('telegram_pos.unauthorized'), 'en'));
            return response('OK');
        }
        
        $lang = $session->language;
        $user = $session->user;
        
        // Check if user has open shift
        $shift = CashierShift::getUserOpenShift($user->id);
        
        if (!$shift) {
            $this->sendMessage(
                $chatId,
                $this->formatter->formatError(__('telegram_pos.shift_not_open', [], $lang), $lang)
            );
            return response('OK');
        }
        
        // Start transaction recording flow
        $session->setState('recording_transaction');
        $session->setData('shift_id', $shift->id);
        $session->setData('transaction_step', 'type');
        $session->setData('transaction_data', []);
        
        // Ask for transaction type
        $this->sendMessage(
            $chatId,
            __('telegram_pos.select_transaction_type', [], $lang),
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
            $this->sendMessage($chatId, $this->formatter->formatError(__('telegram_pos.unauthorized'), 'en'));
            return response('OK');
        }
        
        $lang = $session->language;
        $user = $session->user;
        
        // Get user's open shift
        $shift = CashierShift::getUserOpenShift($user->id);
        
        if (!$shift) {
            $this->sendMessage(
                $chatId,
                $this->formatter->formatNoOpenShift($lang)
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
                $this->closeShiftAction->execute($shift, $user, [
                    'counted_end_saldos' => [],
                    'notes' => 'Closed via Telegram - No transactions'
                ]);
                
                $this->sendMessage($chatId, __('telegram_pos.shift_closed', [], $lang));
                $this->posService->logActivity($user->id, 'shift_closed', "Shift #{$shift->id} closed via Telegram", $session->telegram_user_id);
                
            } catch (\Exception $e) {
                $this->sendMessage($chatId, $this->formatter->formatError($e->getMessage(), $lang));
            }
            
            return response('OK');
        }
        
        // Start close shift flow - store shift and currencies in session
        $session->setState('closing_shift');
        $session->setData('shift_id', $shift->id);
        $session->setData('currencies', $allCurrencies->values()->toArray());
        $session->setData('current_currency_index', 0);
        $session->setData('counted_amounts', []);
        
        // Ask for first currency amount
        $firstCurrency = $allCurrencies->first();
        $expectedAmount = $shift->getNetBalanceForCurrency($firstCurrency);
        
        $message = __('telegram_pos.enter_counted_amount', ['currency' => $firstCurrency->value], $lang);
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
            $session->setState('authenticated');
            $session->setData('shift_id', null);
            $session->setData('currencies', null);
            $session->setData('current_currency_index', null);
            $session->setData('counted_amounts', null);
            
            $this->sendMessage(
                $chatId,
                __('telegram_pos.cancelled', [], $lang) ?? 'Cancelled',
                $this->keyboard->mainMenuKeyboard($lang)
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
        
        $shiftId = $session->getData('shift_id');
        $currencies = collect($session->getData('currencies'));
        $currentIndex = $session->getData('current_currency_index');
        $countedAmounts = $session->getData('counted_amounts', []);
        
        // Get shift
        $shift = CashierShift::find($shiftId);
        
        if (!$shift) {
            $this->sendMessage($chatId, $this->formatter->formatError(__('telegram_pos.shift_not_found', [], $lang) ?? 'Shift not found', $lang));
            $session->setState('authenticated');
            return response('OK');
        }
        
        // Validate amount input
        $amount = trim($text);
        if (!is_numeric($amount) || $amount < 0) {
            $this->sendMessage($chatId, __('telegram_pos.invalid_amount', [], $lang));
            return response('OK');
        }
        
        // Store counted amount for current currency
        $currentCurrency = $currencies[$currentIndex];
        $countedAmounts[] = [
            'currency' => is_string($currentCurrency) ? $currentCurrency : $currentCurrency->value,
            'counted_end_saldo' => (float) $amount,
            'denominations' => [], // Can be extended later for denomination breakdown
        ];
        
        $session->setData('counted_amounts', $countedAmounts);
        
        // Move to next currency
        $nextIndex = $currentIndex + 1;
        
        if ($nextIndex < $currencies->count()) {
            // Ask for next currency
            $session->setData('current_currency_index', $nextIndex);
            
            $nextCurrency = $currencies[$nextIndex];
            $currencyEnum = is_string($nextCurrency) ? \App\Enums\Currency::from($nextCurrency) : $nextCurrency;
            $expectedAmount = $shift->getNetBalanceForCurrency($currencyEnum);
            
            $message = __('telegram_pos.enter_counted_amount', ['currency' => $currencyEnum->value], $lang);
            $message .= "\n\n💰 " . __('telegram_pos.running_balance', [], $lang) . ": " . $currencyEnum->formatAmount($expectedAmount);
            
            $this->sendMessage($chatId, $message, $this->keyboard->cancelKeyboard($lang));
        } else {
            // All amounts collected, close the shift
            try {
                $closedShift = $this->closeShiftAction->execute($shift, $user, [
                    'counted_end_saldos' => $countedAmounts,
                    'notes' => 'Closed via Telegram Bot',
                ]);
                
                // Send success message
                $this->sendMessage(
                    $chatId,
                    $this->formatter->formatShiftClosed($closedShift, $lang),
                    $this->keyboard->mainMenuKeyboard($lang)
                );
                
                // Log activity
                $this->posService->logActivity($user->id, 'shift_closed', "Shift #{$shift->id} closed via Telegram", $session->telegram_user_id);
                
                // Reset session state
                $session->setState('authenticated');
                $session->setData('shift_id', null);
                $session->setData('currencies', null);
                $session->setData('current_currency_index', null);
                $session->setData('counted_amounts', null);
                
            } catch (\Exception $e) {
                $this->sendMessage(
                    $chatId,
                    $this->formatter->formatError($e->getMessage(), $lang),
                    $this->keyboard->mainMenuKeyboard($lang)
                );
                
                Log::error('Telegram POS: Close shift failed', [
                    'user_id' => $user->id,
                    'shift_id' => $shiftId,
                    'error' => $e->getMessage()
                ]);
                
                // Reset session state
                $session->setState('authenticated');
                $session->setData('shift_id', null);
                $session->setData('currencies', null);
                $session->setData('current_currency_index', null);
                $session->setData('counted_amounts', null);
            }
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
            $this->sendMessage($chatId, __('telegram_pos.cancelled', [], $lang), $this->keyboard->mainMenuKeyboard($lang));
            return response('OK');
        }
        
        $transactionData = $session->getData('transaction_data', []);
        $transactionData['type'] = $type;
        $session->setData('transaction_data', $transactionData);
        $session->setData('transaction_step', 'amount');
        
        // Ask for amount
        $this->sendMessage($chatId, __('telegram_pos.enter_amount', [], $lang), $this->keyboard->cancelKeyboard($lang));
        
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
            $this->sendMessage($chatId, __('telegram_pos.cancelled', [], $lang), $this->keyboard->mainMenuKeyboard($lang));
            return response('OK');
        }
        
        $transactionData = $session->getData('transaction_data', []);
        $step = $session->getData('transaction_step');
        
        if ($step === 'currency') {
            $transactionData['currency'] = $currency;
            $session->setData('transaction_data', $transactionData);
            
            // Check if complex transaction
            if ($transactionData['type'] === 'in_out') {
                $session->setData('transaction_step', 'out_amount');
                $this->sendMessage($chatId, __('telegram_pos.enter_out_amount', [], $lang), $this->keyboard->cancelKeyboard($lang));
            } else {
                $session->setData('transaction_step', 'category');
                $this->sendMessage($chatId, __('telegram_pos.select_category', [], $lang), $this->keyboard->categorySelectionKeyboard($lang), 'inline');
            }
        } elseif ($step === 'out_currency') {
            $transactionData['out_currency'] = $currency;
            $session->setData('transaction_data', $transactionData);
            $session->setData('transaction_step', 'category');
            $this->sendMessage($chatId, __('telegram_pos.select_category', [], $lang), $this->keyboard->categorySelectionKeyboard($lang), 'inline');
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
            $this->sendMessage($chatId, __('telegram_pos.cancelled', [], $lang), $this->keyboard->mainMenuKeyboard($lang));
            return response('OK');
        }
        
        $transactionData = $session->getData('transaction_data', []);
        $transactionData['category'] = $category;
        $session->setData('transaction_data', $transactionData);
        $session->setData('transaction_step', 'notes');
        
        // Ask for notes
        $this->sendMessage(
            $chatId,
            __('telegram_pos.add_notes', [], $lang),
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
     * Handle transaction recording flow - text input
     */
    protected function handleTransactionRecordingFlow($session, string $text)
    {
        $chatId = $session->chat_id;
        $lang = $session->language;
        $user = $session->user;
        $step = $session->getData('transaction_step');
        $transactionData = $session->getData('transaction_data', []);
        
        switch ($step) {
            case 'amount':
                // Validate amount
                if (!is_numeric($text) || $text <= 0) {
                    $this->sendMessage($chatId, __('telegram_pos.invalid_amount', [], $lang));
                    return response('OK');
                }
                
                $transactionData['amount'] = (float) $text;
                $session->setData('transaction_data', $transactionData);
                $session->setData('transaction_step', 'currency');
                
                // Ask for currency
                $this->sendMessage($chatId, __('telegram_pos.select_currency', [], $lang), $this->keyboard->currencySelectionKeyboard($lang), 'inline');
                break;
                
            case 'out_amount':
                // Validate out amount for complex transaction
                if (!is_numeric($text) || $text <= 0) {
                    $this->sendMessage($chatId, __('telegram_pos.invalid_amount', [], $lang));
                    return response('OK');
                }
                
                $transactionData['out_amount'] = (float) $text;
                $session->setData('transaction_data', $transactionData);
                $session->setData('transaction_step', 'out_currency');
                
                // Ask for out currency
                $this->sendMessage($chatId, __('telegram_pos.select_out_currency', [], $lang), $this->keyboard->currencySelectionKeyboard($lang), 'inline');
                break;
                
            case 'notes':
                // Record transaction with notes
                return $this->recordTransaction($session, $chatId, $text);
                
            default:
                $this->sendMessage($chatId, __('telegram_pos.error_occurred', [], $lang));
                $this->resetTransactionFlow($session);
        }
        
        return response('OK');
    }
    
    /**
     * Record the transaction using RecordTransactionAction
     */
    protected function recordTransaction($session, int $chatId, ?string $notes)
    {
        $lang = $session->language;
        $user = $session->user;
        $shiftId = $session->getData('shift_id');
        $transactionData = $session->getData('transaction_data', []);
        
        $shift = CashierShift::find($shiftId);
        
        if (!$shift || !$shift->isOpen()) {
            $this->sendMessage($chatId, $this->formatter->formatError(__('telegram_pos.shift_not_open', [], $lang), $lang));
            $this->resetTransactionFlow($session);
            return response('OK');
        }
        
        // Prepare transaction data for RecordTransactionAction
        $data = [
            'type' => $transactionData['type'],
            'amount' => $transactionData['amount'],
            'currency' => $transactionData['currency'],
            'category' => $transactionData['category'] ?? null,
            'notes' => $notes,
        ];
        
        // Add out currency and amount for complex transactions
        if ($transactionData['type'] === 'in_out') {
            $data['out_amount'] = $transactionData['out_amount'] ?? null;
            $data['out_currency'] = $transactionData['out_currency'] ?? null;
        }
        
        try {
            // Record the transaction using existing action
            $this->posService->logActivity($user->id, 'transaction_started', 'Recording transaction via Telegram', $session->telegram_user_id);
            
            $transaction = app(RecordTransactionAction::class)->execute($shift, $user, $data);
            
            // Send success message
            $message = __('telegram_pos.transaction_recorded', [], $lang) . "\n\n";
            $message .= "🆔 ID: {$transaction->id}\n";
            $message .= "📝 " . __('telegram_pos.' . $transactionData['type'] === 'in' ? 'cash_in' : ($transactionData['type'] === 'out' ? 'cash_out' : 'complex_transaction'), [], $lang) . "\n";
            $message .= "💰 {$transactionData['amount']} {$transactionData['currency']}\n";
            
            if ($transactionData['type'] === 'in_out') {
                $message .= "💸 {$transactionData['out_amount']} {$transactionData['out_currency']}\n";
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
            
            $this->sendMessage($chatId, $message, $this->keyboard->mainMenuKeyboard($lang));
            
            // Log success
            $this->posService->logActivity($user->id, 'transaction_recorded', "Transaction #{$transaction->id} recorded via Telegram", $session->telegram_user_id);
            
            // Reset flow
            $this->resetTransactionFlow($session);
            
        } catch (\Exception $e) {
            $this->sendMessage(
                $chatId,
                $this->formatter->formatError(__('telegram_pos.transaction_failed', ['reason' => $e->getMessage()], $lang), $lang),
                $this->keyboard->mainMenuKeyboard($lang)
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
        $session->setState('authenticated');
        $session->setData('shift_id', null);
        $session->setData('transaction_step', null);
        $session->setData('transaction_data', null);
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

