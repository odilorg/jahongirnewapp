<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\CashExpense;
use App\Models\ShiftHandover;
use App\Models\EndSaldo;
use App\Enums\Currency;
use App\Models\BeginningSaldo;
use App\Models\Beds24Booking;
use App\Models\TelegramPosSession;
use App\Models\ExpenseCategory;
use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\DTO\PaymentPresentation;
use App\DTO\RecordPaymentData;
use App\Enums\OverrideTier;
use App\Services\BotPaymentService;
use App\Services\CashierExchangeService;
use App\Services\CashierExpenseService;
use App\Services\CashierPaymentService;
use App\Services\CashierShiftService;
use App\Services\ExchangeRateService;
use App\Services\Fx\OverridePolicyEvaluator as FxOverridePolicyEvaluator;
use App\Services\Beds24BookingService;
use App\Services\OwnerAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CashierBotController extends Controller
{
    protected OwnerAlertService $ownerAlert;
    protected CashierPaymentService $paymentService;
    protected BotPaymentService $botPaymentService;
    protected FxOverridePolicyEvaluator $overridePolicy;
    protected CashierShiftService $shiftService;
    protected CashierExpenseService $expenseService;
    protected CashierExchangeService $exchangeService;
    protected ExchangeRateService $fxRateService;
    protected BotResolverInterface $botResolver;
    protected TelegramTransportInterface $transport;
    protected Beds24BookingService $beds24;

    // Property ID for Beds24 — must match HousekeepingBotController
    protected const PROPERTY_ID = 41097;

    public function __construct(
        OwnerAlertService $ownerAlert,
        CashierPaymentService $paymentService,
        BotPaymentService $botPaymentService,
        FxOverridePolicyEvaluator $overridePolicy,
        CashierShiftService $shiftService,
        CashierExpenseService $expenseService,
        CashierExchangeService $exchangeService,
        ExchangeRateService $fxRateService,
        BotResolverInterface $botResolver,
        TelegramTransportInterface $transport,
        Beds24BookingService $beds24,
    ) {
        $this->ownerAlert = $ownerAlert;
        $this->paymentService = $paymentService;
        $this->botPaymentService = $botPaymentService;
        $this->overridePolicy = $overridePolicy;
        $this->shiftService = $shiftService;
        $this->expenseService = $expenseService;
        $this->exchangeService = $exchangeService;
        $this->fxRateService = $fxRateService;
        $this->botResolver = $botResolver;
        $this->transport = $transport;
        $this->beds24 = $beds24;
    }

    public function handleWebhook(Request $request)
    {
        $data = $request->all();
        $updateId = $data['update_id'] ?? null;

        // Durable ingest: persist raw update, return 200 fast, process async
        $webhook = \App\Models\IncomingWebhook::create([
            'source'      => 'telegram:cashier',
            'event_id'    => $updateId ? "cashier:{$updateId}" : null,
            'payload'     => $data,
            'status'      => \App\Models\IncomingWebhook::STATUS_PENDING,
            'received_at' => now(),
        ]);

        \App\Jobs\ProcessTelegramUpdateJob::dispatch('cashier', $webhook->id);

        return response('OK');
    }

    /**
     * Process a Telegram update (called from queue job, not from HTTP).
     */
    public function processUpdate(array $data): void
    {
        try {
            if ($callback = $data['callback_query'] ?? null) { $this->handleCallback($callback); return; }
            if ($message = $data['message'] ?? null) { $this->handleMessage($message); return; }
        } catch (\Throwable $e) {
            Log::error('CashierBot unhandled error', ['e' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->alertOwnerOnError('Webhook', $e);
            throw $e;
        }
    }

    protected function handleMessage(array $message)
    {
        $chatId = $message['chat']['id'] ?? null;
        $text = trim($message['text'] ?? '');
        $photo = $message['photo'] ?? null;
        $contact = $message['contact'] ?? null;
        if (!$chatId) return response('OK');

        // Phone auth only works in private chats — ignore group messages
        if (($message['chat']['type'] ?? 'private') !== 'private') return response('OK');

        if ($contact) return $this->handleAuth($chatId, $contact);

        $session = TelegramPosSession::where('chat_id', $chatId)->first();
        if (!$session || !$session->user_id) {
            $this->send($chatId, "Отправьте номер телефона для авторизации.", $this->phoneKb());
            return response('OK');
        }
        // Only expire idle sessions, not active workflows
        if ($session->isExpired() && in_array($session->state, ['main_menu', 'idle', null])) {
            $session->update(['user_id' => null, 'state' => 'idle', 'data' => null]);
            $this->send($chatId, "Сессия истекла. Отправьте номер телефона.", $this->phoneKb());
            return response('OK');
        }
        // Hard TTL: expire active financial sessions after 4 hours to prevent
        // abandoned sessions from being inherited by the next person
        if ($session->last_activity_at && Carbon::parse($session->last_activity_at)->addHours(4)->isPast()) {
            $session->update(['user_id' => null, 'state' => 'idle', 'data' => null]);
            $this->send($chatId, "Сессия истекла (4ч). Отправьте номер телефона.", $this->phoneKb());
            return response('OK');
        }
        $session->updateActivity();
        if ($photo && $session->state === 'shift_close_photo') return $this->handleShiftPhoto($session, $chatId, $photo);
        if ($text === '/start' || $text === '/menu') return $this->showMainMenu($chatId, $session);
        if ($text === '/logout') {
            $session->update(['user_id' => null, 'state' => 'idle', 'data' => null]);
            $this->send($chatId, "Вы вышли. Отправьте номер телефона для входа.", $this->phoneKb());
            return response('OK');
        }
        return $this->handleState($session, $chatId, $text);
    }

    protected function handleAuth(int $chatId, array $contact)
    {
        $phone = preg_replace('/[^0-9]/', '', $contact['phone_number'] ?? '');
        $user = User::where('phone_number', 'LIKE', '%' . substr($phone, -9))->first();
        if (!$user) { $this->send($chatId, "Номер не найден. Обратитесь к руководству."); return response('OK'); }
        $timeoutMinutes = config('services.telegram_pos_bot.session_timeout', 15);
        TelegramPosSession::updateOrCreate(['chat_id' => $chatId], [
            'telegram_user_id' => $contact['user_id'] ?? $chatId,
            'user_id'          => $user->id,
            'state'            => 'main_menu',
            'data'             => null,
            'last_activity_at' => now(),
            'expires_at'       => now()->addMinutes($timeoutMinutes),
        ]);
        // Don't overwrite user telegram_user_id (shared with POS bot)
        // Remove the phone reply-keyboard so the user can't accidentally re-auth
        $this->send($chatId, "Добро пожаловать, {$user->name}!", ['remove_keyboard' => true], 'reply');
        return $this->showMainMenu($chatId, TelegramPosSession::where('chat_id', $chatId)->first());
    }

    protected function showMainMenu(int $chatId, $session)
    {
        $session->update(['state' => 'main_menu', 'data' => null]);
        $shift = $this->getShift($session->user_id);
        $st = $shift ? "Смена открыта" : "Смена закрыта";
        $bal = $shift ? "\nБаланс: " . $this->fmtBal($this->getBal($shift)) : '';
        $this->send($chatId, "Кассир-бот | {$st}{$bal}");
        $this->send($chatId, "Выберите действие:", $this->menuKb($shift, $session->user_id), 'inline');
        return response('OK');
    }

    /** Actions that create financial side effects — must be idempotency-guarded */
    private const IDEMPOTENT_ACTIONS = [
        'confirm_payment',
        'confirm_expense',
        'confirm_exchange',
        'confirm_close',
    ];

    protected function handleCallback(array $cb)
    {
        $chatId = $cb['message']['chat']['id'] ?? null;
        $data = $cb['data'] ?? '';
        $callbackId = $cb['id'] ?? '';
        if (!$chatId) return response('OK');
        $this->aCb($callbackId);

        // Handle owner approval callbacks (owner may not have a session)
        if (preg_match('/^(approve|reject)_expense_(\d+)$/', $data, $matches)) {
            return app(\App\Http\Controllers\OwnerBotController::class)
                ->handleExpenseAction($chatId, $cb['message']['message_id'] ?? null, $callbackId, $matches[1], (int)$matches[2]);
        }

        // Idempotency guard for financial confirm actions
        if (in_array($data, self::IDEMPOTENT_ACTIONS, true) && $callbackId) {
            $claimResult = $this->claimCallback($callbackId, $chatId, $data);
            if ($claimResult !== 'claimed') {
                Log::info('CashierBot: callback not claimable', [
                    'callback_id' => $callbackId,
                    'action'      => $data,
                    'result'      => $claimResult,
                ]);
                $msg = $claimResult === 'succeeded'
                    ? "⚠️ Эта операция уже обработана."
                    : "⏳ Операция в процессе, подождите.";
                $this->send($chatId, $msg);
                return response('OK');
            }
        }

        $s = TelegramPosSession::where('chat_id', $chatId)->first();
        if (!$s) return response('OK');

        return match(true) {
            $data === 'open_shift' => $this->openShift($s, $chatId),
            $data === 'payment' => $this->startPayment($s, $chatId),
            $data === 'expense' => $this->startExpense($s, $chatId),
            $data === 'exchange' => $this->startExchange($s, $chatId),
            $data === 'balance' => $this->showBalance($s, $chatId),
            $data === 'cash_in' => $this->startCashIn($s, $chatId),
            $data === 'confirm_cash_in' => $this->confirmCashIn($s, $chatId),
            $data === 'close_shift' => $this->startClose($s, $chatId),
            $data === 'menu' => $this->showMainMenu($chatId, $s),
            str_starts_with($data, 'guest_') => $this->selectGuest($s, $chatId, $data),
            str_starts_with($data, 'cur_') => $this->selectCur($s, $chatId, $data),
            $data === 'fx_confirm_amount' => $this->fxConfirmAmount($s, $chatId),
            $data === 'rate_use_reference' => $this->acceptReferenceRate($s, $chatId),
            str_starts_with($data, 'excur_') => $this->selectExCur($s, $chatId, $data),
            str_starts_with($data, 'exout_') => $this->selectExOutCur($s, $chatId, $data),
            str_starts_with($data, 'method_') => $this->selectMethod($s, $chatId, $data),
            str_starts_with($data, 'expcat_') => $this->selectExpCat($s, $chatId, $data),
            $data === 'confirm_payment' => $this->confirmPayment($s, $chatId, $callbackId),
            $data === 'confirm_expense' => $this->confirmExpense($s, $chatId, $callbackId),
            $data === 'confirm_exchange' => $this->confirmExchange($s, $chatId, $callbackId),
            $data === 'confirm_close' => $this->confirmClose($s, $chatId, $callbackId),
            $data === 'cancel' => $this->showMainMenu($chatId, $s),
            $data === 'my_txns' => $this->showMyTransactions($s, $chatId),
            $data === 'guide' => $this->showGuide($chatId),
            str_starts_with($data, 'guide_') => $this->showGuideTopic($chatId, substr($data, 6)),
            default => response('OK'),
        };
    }

    // ── Callback idempotency lifecycle ──────────────────────

    /**
     * Attempt to claim a callback for processing.
     *
     * @return 'claimed'|'succeeded'|'processing' — what happened
     */
    private function claimCallback(string $callbackId, int $chatId, string $action): string
    {
        // Check if already exists
        $existing = DB::table('telegram_processed_callbacks')
            ->where('callback_query_id', $callbackId)
            ->first();

        if ($existing) {
            if ($existing->status === 'succeeded') return 'succeeded';
            if ($existing->status === 'processing') return 'processing';

            // status === 'failed' → allow retry by deleting the failed row
            DB::table('telegram_processed_callbacks')
                ->where('callback_query_id', $callbackId)
                ->where('status', 'failed')
                ->delete();
        }

        // Attempt to claim via INSERT with UNIQUE constraint
        try {
            DB::table('telegram_processed_callbacks')->insert([
                'callback_query_id' => $callbackId,
                'chat_id'           => $chatId,
                'action'            => $action,
                'status'            => 'processing',
                'claimed_at'        => now(),
            ]);
            return 'claimed';
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Lost the race — another request claimed it between our check and insert
            $row = DB::table('telegram_processed_callbacks')
                ->where('callback_query_id', $callbackId)
                ->first();
            return $row?->status === 'succeeded' ? 'succeeded' : 'processing';
        }
    }

    /**
     * Mark a claimed callback as succeeded. Called after financial operation completes.
     */
    private function succeedCallback(string $callbackId): void
    {
        DB::table('telegram_processed_callbacks')
            ->where('callback_query_id', $callbackId)
            ->where('status', 'processing')
            ->update(['status' => 'succeeded', 'completed_at' => now()]);
    }

    /**
     * Mark a claimed callback as failed. Called when financial operation fails.
     * This allows the user to retry the same callback.
     */
    private function failCallback(string $callbackId, string $error = ''): void
    {
        DB::table('telegram_processed_callbacks')
            ->where('callback_query_id', $callbackId)
            ->where('status', 'processing')
            ->update([
                'status'       => 'failed',
                'error'        => mb_substr($error, 0, 500),
                'completed_at' => now(),
            ]);
    }

    protected function handleState($s, int $chatId, string $text)
    {
        return match($s->state) {
            'payment_room' => $this->hPayRoom($s, $chatId, $text),
            'payment_amount' => $this->hPayAmt($s, $chatId, $text),
            'payment_exchange_rate' => $this->hPayRate($s, $chatId, $text),
            'payment_fx_amount' => $this->hPayFxAmount($s, $chatId, $text),
            'payment_fx_override_reason' => $this->hPayFxOverrideReason($s, $chatId, $text),
            'expense_amount' => $this->hExpAmt($s, $chatId, $text),
            'expense_desc' => $this->hExpDesc($s, $chatId, $text),
            'cash_in_amount' => $this->hCashInAmt($s, $chatId, $text),
            'exchange_in_amount' => $this->hExInAmt($s, $chatId, $text),
            'exchange_out_amount' => $this->hExOutAmt($s, $chatId, $text),
            'shift_count_uzs' => $this->hCount($s, $chatId, $text, 'UZS'),
            'shift_count_usd' => $this->hCount($s, $chatId, $text, 'USD'),
            'shift_count_eur' => $this->hCount($s, $chatId, $text, 'EUR'),
            default => $this->showMainMenu($chatId, $s),
        };
    }

    // ── SHIFT ───────────────────────────────────────────────────

    protected function openShift($s, int $chatId)
    {
        if ($this->getShift($s->user_id)) { $this->send($chatId, "Смена уже открыта."); return $this->showMainMenu($chatId, $s); }
        $drawer = \App\Models\CashDrawer::where('is_active', true)->first();
        if (!$drawer) { $this->send($chatId, "Нет активной кассы."); return response('OK'); }

        // Prevent multiple open shifts on the same drawer
        $existingShift = CashierShift::where('cash_drawer_id', $drawer->id)
            ->where('status', 'open')
            ->with('user')
            ->first();
        if ($existingShift) {
            $name = $existingShift->user->name ?? 'другой кассир';
            $this->send($chatId, "⚠️ Касса занята!\n\nСмена уже открыта: {$name}\nОткрыта: " . $existingShift->opened_at->timezone('Asia/Tashkent')->format('d.m H:i') . "\n\nДождитесь закрытия смены.");
            return response('OK');
        }

        $shift = CashierShift::create(['cash_drawer_id' => $drawer->id, 'user_id' => $s->user_id, 'status' => 'open', 'opened_at' => now()]);

        // Carry forward balances from last closed shift on same drawer
        $prevHandover = ShiftHandover::whereHas('outgoingShift', fn($q) => $q->where('cash_drawer_id', $drawer->id))
            ->latest('id')->first();
        if ($prevHandover) {
            foreach (['UZS' => $prevHandover->counted_uzs, 'USD' => $prevHandover->counted_usd, 'EUR' => $prevHandover->counted_eur] as $cur => $amt) {
                if ($amt != 0) {
                    BeginningSaldo::create(['cashier_shift_id' => $shift->id, 'currency' => $cur, 'amount' => $amt]);
                }
            }
        }

        $bal = $this->getBal($shift);
        $balStr = $this->fmtBal($bal);
        $msg = "Смена открыта! Касса: {$drawer->name}";
        if ($balStr !== '0') $msg .= "\nНачальный баланс: " . $balStr;
        $this->send($chatId, $msg);
        return $this->showMainMenu($chatId, $s);
    }

    // ── PAYMENT ─────────────────────────────────────────────────

    protected function startPayment($s, int $chatId)
    {
        $shift = $this->getShift($s->user_id);
        if (!$shift) { $this->send($chatId, "Сначала откройте смену."); return response('OK'); }

        $d = ['shift_id' => $shift->id];

        // Pull in-house guests live from Beds24 API (arrivals today + stayovers).
        // Falls back to manual entry if API call fails.
        $guests = $this->fetchInHouseGuests();

        if (!empty($guests)) {
            // Index by booking ID so selectGuest can read live fields without re-fetching
            $d['_live_guests'] = collect($guests)->keyBy('id')->toArray();
            $s->update(['state' => 'payment_guest_select', 'data' => $d]);
            $btns = array_map(fn($g) => [[
                'text'          => "#{$g['id']} {$g['firstName']} {$g['lastName']}",
                'callback_data' => "guest_{$g['id']}",
            ]], $guests);
            $btns[] = [['text' => '✏️ Ручной ввод', 'callback_data' => 'guest_manual']];
            $this->send($chatId, "Сегодняшние заезды:", ['inline_keyboard' => $btns], 'inline');
            return response('OK');
        }

        // No in-house guests — ask for booking ID manually
        $s->update(['state' => 'payment_room', 'data' => $d]);
        $this->send($chatId, "Гостей на сегодня не найдено.\nВведите номер брони Beds24:");
        return response('OK');
    }

    /**
     * Fetch today's in-house guests from Beds24 API live.
     * Returns arrivals today + stayovers (arrived before today, departing today or later).
     * Returns empty array on API failure so caller falls back to manual entry.
     */
    protected function fetchInHouseGuests(): array
    {
        try {
            $today = Carbon::today()->format('Y-m-d');
            $propertyId = [(string) self::PROPERTY_ID];

            // Arrivals today only — cashier needs to collect payment at check-in
            $arrivalsResp = $this->beds24->getBookings([
                'arrival'    => $today,
                'propertyId' => $propertyId,
            ]);

            $all = collect($arrivalsResp['data'] ?? [])
                ->filter(fn($b) => !in_array($b['status'] ?? '', ['cancelled', 'declined']))
                ->unique('id')
                ->sortBy(fn($b) => ($b['firstName'] ?? '') . ' ' . ($b['lastName'] ?? ''))
                ->values()
                ->all();

            return $all;
        } catch (\Throwable $e) {
            Log::warning('CashierBot: Beds24 guest fetch failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Fallback: cashier typed a Beds24 booking ID manually.
     * State 'payment_room' is kept for backward compatibility with handleState().
     */
    protected function hPayRoom($s, int $chatId, string $text)
    {
        $d = $s->data ?? [];
        $bid = trim($text);

        // Accept only numeric booking IDs
        if (! ctype_digit($bid)) {
            $this->send($chatId, "Введите числовой номер брони Beds24 (например: 79454530):");
            return response('OK');
        }

        // Try local DB first; if missing, fetch live from Beds24 API
        $b = Beds24Booking::where('beds24_booking_id', $bid)->first();
        if (! $b) {
            try {
                $resp       = $this->beds24->getBooking($bid);
                $liveGuest  = $resp['data'][0] ?? null;
                if (! $liveGuest) {
                    $this->send($chatId, "Бронь #{$bid} не найдена в Beds24.\nПроверьте номер и повторите:");
                    return response('OK');
                }
                // Stash in session so selectGuest can use live fields directly
                $d                     = $s->data ?? [];
                $d['_live_guests'][$bid] = $liveGuest;
                $s->update(['data' => $d]);
            } catch (\Throwable $e) {
                Log::warning('CashierBot: live booking lookup failed for manual ID', [
                    'bid'   => $bid,
                    'error' => $e->getMessage(),
                ]);
                $this->send($chatId, "Бронь #{$bid} не найдена.\nПроверьте номер и повторите:");
                return response('OK');
            }
        }

        // Booking confirmed (local or live) — hand off to selectGuest
        return $this->selectGuest($s, $chatId, "guest_{$bid}");
    }

    protected function selectGuest($s, int $chatId, string $data)
    {
        $d = $s->data ?? [];
        $bid = str_replace('guest_', '', $data);

        if ($bid === 'manual') {
            // Microphase 7: manual/legacy entry point removed.
            // Manual payments bypass the FX snapshot controls — operators must contact manager.
            $this->send($chatId, "❌ Курсы ФX недоступны. Обратитесь к менеджеру.");
            return response('OK');
        }

        // 1. Use live guest payload stored in session during arrivals list fetch (no extra API call)
        $liveGuest  = $d['_live_guests'][$bid] ?? null;
        $snap       = $this->extractLiveBookingSnapshot($liveGuest, $bid);

        // 2. Local DB — defensive fallback; still needed for FX presentation path
        $b = Beds24Booking::where('beds24_booking_id', $bid)->first();

        // Merge: live snapshot wins, local DB fills gaps, hard defaults last
        $d['guest_name']       = $snap['guest_name']       ?? ($b?->guest_name ?? '?');
        $d['booking_id']       = $bid;
        $d['booking_currency'] = $snap['booking_currency'] ?? ($b ? strtoupper($b->currency ?? 'USD') : 'USD');
        $d['booking_amount']   = $snap['booking_amount']   ?? ($b ? (float)($b->invoice_balance > 0 ? $b->invoice_balance : $b->total_amount) : null);

        // Log whenever a fallback was used — helps catch stale sync or parse issues
        if ($snap['currency_source'] !== 'live_rate_description') {
            Log::warning('CashierBot: booking currency not from live payload', [
                'beds24_booking_id'  => $bid,
                'raw_rateDescription'=> $liveGuest['rateDescription'] ?? null,
                'currency_source'    => $snap['currency_source'],
                'resolved_currency'  => $d['booking_currency'],
            ]);
        }

        // Try to get a frozen FX presentation from the sync system (requires local booking record)
        if ($b && $d['booking_amount'] > 0) {
            try {
                $botSessionId = (string) $chatId . ':' . ($d['shift_id'] ?? '0');
                $presentation = $this->botPaymentService->preparePayment($bid, $botSessionId);
                $d['fx_presentation'] = $presentation->toArray();

                $arrival = \Carbon\Carbon::parse($presentation->arrivalDate)->format('d.m');
                $s->update(['state' => 'payment_fx_currency', 'data' => $d]);
                $this->send($chatId,
                    "💳 Бронь #{$bid} | {$presentation->guestName} | Заезд: {$arrival}\n"
                    . "Курс на {$presentation->fxRateDate}\n\n"
                    . "Выберите валюту оплаты:",
                    ['inline_keyboard' => [[
                        ['text' => '🇺🇿 UZS: ' . number_format($presentation->uzsPresented, 0, '.', ' '), 'callback_data' => 'cur_UZS'],
                        ['text' => '🇪🇺 EUR: ' . number_format($presentation->eurPresented, 0), 'callback_data' => 'cur_EUR'],
                        ['text' => '🇷🇺 RUB: ' . number_format($presentation->rubPresented, 0, '.', ' '), 'callback_data' => 'cur_RUB'],
                    ]]],
                    'inline'
                );
                return response('OK');

            } catch (\Throwable $e) {
                // FX presentation unavailable — hard block (Microphase 7).
                Log::warning('CashierBot: FX presentation unavailable — payment blocked', [
                    'beds24_booking_id' => $bid,
                    'error'             => $e->getMessage(),
                ]);
            }
        }

        // FX presentation unavailable: booking not locally synced, zero amount, or FX service down.
        // Microphase 7: legacy/manual fallback removed — operator must contact manager.
        $this->send($chatId, "❌ Курсы ФX недоступны. Обратитесь к менеджеру.");
        return response('OK');
    }

    protected function hPayAmt($s, int $chatId, string $text)
    {
        $amt = floatval(str_replace([' ', ','], ['', '.'], $text));
        if ($amt <= 0) { $this->send($chatId, "Неверная сумма."); return response('OK'); }
        $d = $s->data ?? [];
        $d['amount'] = $amt;
        $s->update(['state' => 'payment_currency', 'data' => $d]);
        $this->send($chatId, "Сумма: " . number_format($amt, 0) . "\nВалюта:", ['inline_keyboard' => [[
            ['text' => 'UZS', 'callback_data' => 'cur_UZS'],
            ['text' => 'USD', 'callback_data' => 'cur_USD'],
            ['text' => 'EUR', 'callback_data' => 'cur_EUR'],
        ]]], 'inline');
        return response('OK');
    }

    protected function selectCur($s, int $chatId, string $data)
    {
        $d = $s->data ?? [];
        $d['currency'] = str_replace('cur_', '', $data);

        // FX presentation path: booking has a frozen sync — show pre-computed amount and ask for actual
        if (! empty($d['fx_presentation'])) {
            try {
                $p = PaymentPresentation::fromArray($d['fx_presentation']);
                $presented = $p->presentedAmountFor($d['currency']);
                $d['fx_presented_amount'] = $presented;
                $s->update(['state' => 'payment_fx_amount', 'data' => $d]);
                $this->send($chatId,
                    "💰 По форме: " . number_format((int) $presented, 0, '.', ' ') . " {$d['currency']}\n\n"
                    . "Введите фактически полученную сумму или нажмите ✅:",
                    ['inline_keyboard' => [[
                        ['text' => '✅ Принять (' . number_format((int) $presented, 0, '.', ' ') . " {$d['currency']})", 'callback_data' => 'fx_confirm_amount'],
                    ]]],
                    'inline'
                );
                return response('OK');
            } catch (\Throwable $e) {
                // Invalid currency or bad session data — fall through to legacy path
                Log::warning('CashierBot: failed to get presentedAmountFor, falling back', [
                    'currency' => $d['currency'],
                    'error'    => $e->getMessage(),
                ]);
                $d['fx_presentation'] = null;
            }
        }

        // Legacy path: no FX presentation (manual entry or old-style booking)
        $needsFxStep = !empty($d['booking_currency'])
            && $d['booking_currency'] !== $d['currency']
            && $d['currency'] === 'UZS'
            && !empty($d['booking_amount']);

        if ($needsFxStep) {
            return $this->askExchangeRate($s, $chatId, $d);
        }

        $s->update(['state' => 'payment_method', 'data' => $d]);
        $this->send($chatId, "Способ оплаты:", ['inline_keyboard' => [[
            ['text' => 'Наличные', 'callback_data' => 'method_cash'],
            ['text' => 'Карта', 'callback_data' => 'method_card'],
            ['text' => 'Перевод', 'callback_data' => 'method_transfer'],
        ]]], 'inline');
        return response('OK');
    }

    /**
     * Fetch reference rate and ask cashier to confirm or enter their applied rate.
     */
    protected function askExchangeRate($s, int $chatId, array $d)
    {
        $refData = $this->fxRateService->getUsdToUzs();

        if ($refData) {
            $d['reference_rate']   = $refData['rate'];
            $d['reference_source'] = $refData['source'];
            $d['reference_date']   = $refData['effective_date'];
            $sourceLabel           = $this->fxRateService->sourceLabel($refData['source']);
            $suggested             = number_format($refData['rate'], 2);
            $expectedUzs           = number_format($d['booking_amount'] * $refData['rate'], 0);

            $msg = "💱 Курс обмена USD→UZS\n\n"
                 . "Брон.: {$d['booking_amount']} {$d['booking_currency']}\n"
                 . "Курс ЦБ ({$sourceLabel}, {$refData['effective_date']}): {$suggested}\n"
                 . "Расч. сумма: ~{$expectedUzs} UZS\n\n"
                 . "Введите курс обмена или нажмите ✅ чтобы принять курс ЦБ:";

            $s->update(['state' => 'payment_exchange_rate', 'data' => $d]);
            $this->send($chatId, $msg, ['inline_keyboard' => [[
                ['text' => "✅ Принять курс ЦБ ({$suggested})", 'callback_data' => 'rate_use_reference'],
            ]]], 'inline');
        } else {
            // All sources failed — manual entry only
            $d['reference_rate']   = null;
            $d['reference_source'] = 'manual_only';
            $d['reference_date']   = null;

            $s->update(['state' => 'payment_exchange_rate', 'data' => $d]);
            $this->send($chatId, "⚠️ Не удалось получить курс ЦБ автоматически.\n\nВведите курс обмена USD→UZS вручную:");
        }
        return response('OK');
    }

    /**
     * Cashier typed a custom exchange rate.
     */
    protected function hPayRate($s, int $chatId, string $text)
    {
        $rate = (float) str_replace([' ', ','], ['', '.'], $text);
        if ($rate <= 0) {
            $this->send($chatId, "Неверный курс. Введите число, например: 12800");
            return response('OK');
        }
        $d = $s->data ?? [];
        $d['applied_rate'] = $rate;
        return $this->proceedToPaymentMethod($s, $chatId, $d);
    }

    /**
     * Cashier accepted the reference (CBU) rate as their applied rate.
     */
    protected function acceptReferenceRate($s, int $chatId)
    {
        $d = $s->data ?? [];
        $d['applied_rate'] = $d['reference_rate'] ?? null;
        if (!$d['applied_rate']) {
            $this->send($chatId, "Курс недоступен. Введите вручную:");
            return response('OK');
        }
        return $this->proceedToPaymentMethod($s, $chatId, $d);
    }

    protected function proceedToPaymentMethod($s, int $chatId, array $d)
    {
        $s->update(['state' => 'payment_method', 'data' => $d]);
        $this->send($chatId, "Способ оплаты:", ['inline_keyboard' => [[
            ['text' => 'Наличные', 'callback_data' => 'method_cash'],
            ['text' => 'Карта', 'callback_data' => 'method_card'],
            ['text' => 'Перевод', 'callback_data' => 'method_transfer'],
        ]]], 'inline');
        return response('OK');
    }

    // ── FX PRESENTATION PAYMENT PATH ────────────────────────────

    /**
     * Cashier clicked "✅ Принять" — accept the pre-computed presented amount as paid.
     */
    protected function fxConfirmAmount($s, int $chatId)
    {
        $d = $s->data ?? [];
        $presented = (float) ($d['fx_presented_amount'] ?? 0);
        if ($presented <= 0) {
            $this->send($chatId, "Ошибка сессии. Начните заново.");
            return $this->showMainMenu($chatId, $s);
        }
        $d['amount'] = $presented;
        $d['override_tier'] = OverrideTier::None->value;
        $d['override_reason'] = null;
        return $this->proceedToPaymentMethod($s, $chatId, $d);
    }

    /**
     * Cashier typed an actual amount (may differ from presented).
     * Evaluates override tier and routes accordingly.
     */
    protected function hPayFxAmount($s, int $chatId, string $text)
    {
        $amount = (float) str_replace([' ', ','], ['', '.'], $text);
        if ($amount <= 0) {
            $this->send($chatId, "Неверная сумма. Введите число, например: 850000");
            return response('OK');
        }

        $d = $s->data ?? [];
        $presented = (float) ($d['fx_presented_amount'] ?? 0);
        $d['amount'] = $amount;

        if ($presented <= 0) {
            // No presented amount to compare — treat as no-override
            $d['override_tier'] = OverrideTier::None->value;
            $d['override_reason'] = null;
            return $this->proceedToPaymentMethod($s, $chatId, $d);
        }

        $currency = Currency::tryFrom(strtoupper($d['currency'] ?? 'UZS')) ?? Currency::UZS;
        $evaluation = $this->overridePolicy->evaluate($currency, $presented, $amount);
        $tier = $evaluation->tier;
        $d['override_tier'] = $tier->value;
        $d['within_tolerance'] = $evaluation->withinTolerance;
        $d['variance_pct'] = $evaluation->variancePct;

        return match ($tier) {
            OverrideTier::None => $this->proceedToPaymentMethod($s, $chatId, $d),

            OverrideTier::Cashier => $this->askFxOverrideReason($s, $chatId, $d, $presented),

            OverrideTier::Manager, OverrideTier::Blocked => $this->blockFxOverride($s, $chatId, $d, $tier, $presented),
        };
    }

    /**
     * Cashier tier override: ask for a reason before proceeding.
     */
    private function askFxOverrideReason($s, int $chatId, array $d, float $presented): mixed
    {
        $variance = round(abs($d['amount'] - $presented));
        $pct = $presented > 0 ? round(abs($d['amount'] - $presented) / $presented * 100, 1) : 0;

        $s->update(['state' => 'payment_fx_override_reason', 'data' => $d]);
        $this->send($chatId,
            "⚠️ Сумма отличается от формы на " . number_format($variance, 0, '.', ' ')
            . " {$d['currency']} ({$pct}%)\n\n"
            . "По форме: " . number_format((int) $presented, 0, '.', ' ') . " {$d['currency']}\n"
            . "Фактически: " . number_format((int) $d['amount'], 0, '.', ' ') . " {$d['currency']}\n\n"
            . "Укажите причину расхождения:"
        );
        return response('OK');
    }

    /**
     * Manager/Blocked tier: tell cashier to escalate offline.
     */
    private function blockFxOverride($s, int $chatId, array $d, OverrideTier $tier, float $presented): mixed
    {
        $variance = round(abs($d['amount'] - $presented));
        $pct = $presented > 0 ? round(abs($d['amount'] - $presented) / $presented * 100, 1) : 0;

        $label = $tier === OverrideTier::Manager
            ? "⛔ Расхождение ({$pct}%) требует одобрения менеджера."
            : "🚫 Расхождение ({$pct}%) превышает максимально допустимое.";

        $this->send($chatId,
            "{$label}\n\n"
            . "По форме: " . number_format((int) $presented, 0, '.', ' ') . " {$d['currency']}\n"
            . "Фактически: " . number_format((int) $d['amount'], 0, '.', ' ') . " {$d['currency']}\n\n"
            . "Эскалируйте вопрос руководству офлайн. Начните ввод заново с правильной суммой."
        );
        return $this->showMainMenu($chatId, $s);
    }

    /**
     * Cashier entered the override reason — proceed to payment method.
     */
    protected function hPayFxOverrideReason($s, int $chatId, string $text)
    {
        if (strlen(trim($text)) < 3) {
            $this->send($chatId, "Причина слишком короткая. Опишите подробнее:");
            return response('OK');
        }
        $d = $s->data ?? [];
        $d['override_reason'] = trim($text);
        return $this->proceedToPaymentMethod($s, $chatId, $d);
    }

    // ────────────────────────────────────────────────────────────

    protected function selectMethod($s, int $chatId, string $data)
    {
        $d = $s->data ?? [];
        $d['method'] = str_replace('method_', '', $data);
        $s->update(['state' => 'payment_confirm', 'data' => $d]);
        $ml = match($d['method']) { 'cash' => 'Наличные', 'card' => 'Карта', 'transfer' => 'Перевод', default => $d['method'] };
        $room = $d['room'] ?? ($d['booking_id'] ? "Beds24 #{$d['booking_id']}" : '—');
        $t = "Подтвердите:\n\nБронь: {$room}\nГость: {$d['guest_name']}\nСумма: " . number_format($d['amount'], 0) . " {$d['currency']}\nСпособ: {$ml}";

        // FX presentation path: show comparison against the printed form
        if (! empty($d['fx_presentation']) && ! empty($d['fx_presented_amount'])) {
            $presented = (float) $d['fx_presented_amount'];
            $diff = round((float) $d['amount'] - $presented);
            $t .= "\n\n📋 По форме: " . number_format((int) $presented, 0, '.', ' ') . " {$d['currency']}";
            if ($diff !== 0.0) {
                $t .= "\nРасхождение: " . ($diff >= 0 ? '+' : '') . number_format((int) $diff, 0, '.', ' ') . " {$d['currency']}";
                if (! empty($d['override_reason'])) {
                    $t .= "\nПричина: {$d['override_reason']}";
                }
            }

        // Legacy path: show applied exchange rate comparison
        } elseif (!empty($d['applied_rate']) && !empty($d['booking_amount']) && !empty($d['booking_currency'])) {
            $expectedAtApplied  = $d['booking_amount'] * $d['applied_rate'];
            $collectionVariance = round((float)$d['amount'] - $expectedAtApplied, 0);
            $t .= "\n\n💱 Курс (применён): " . number_format($d['applied_rate'], 2);
            if (!empty($d['reference_rate']) && $d['reference_source'] !== 'manual_only') {
                $sourceLabel = $this->fxRateService->sourceLabel($d['reference_source']);
                $fxVariance  = round((float)$d['amount'] - ($d['booking_amount'] * $d['reference_rate']), 0);
                $t .= "\nКурс ЦБ ({$sourceLabel}): " . number_format($d['reference_rate'], 2);
                $t .= "\nРазница vs ЦБ: " . ($fxVariance >= 0 ? '+' : '') . number_format($fxVariance, 0) . " UZS";
            }
            if ($collectionVariance != 0) {
                $t .= "\nРасхождение: " . ($collectionVariance >= 0 ? '+' : '') . number_format($collectionVariance, 0) . " UZS";
            }
        }

        $this->send($chatId, $t, ['inline_keyboard' => [[
            ['text' => 'Подтвердить', 'callback_data' => 'confirm_payment'],
            ['text' => 'Отмена', 'callback_data' => 'cancel'],
        ]]], 'inline');
        return response('OK');
    }

    protected function confirmPayment($s, int $chatId, string $callbackId = '')
    {
        $d = $s->data ?? [];
        $shift = CashierShift::find($d['shift_id'] ?? 0);
        if (!$shift || !$shift->isOpen()) {
            $this->send($chatId, "Смена не найдена или закрыта.");
            if ($callbackId) $this->failCallback($callbackId, 'Shift not found or closed');
            return $this->showMainMenu($chatId, $s);
        }

        try {
            // FX presentation path: use BotPaymentService which validates the frozen DTO
            if (! empty($d['fx_presentation'])) {
                $recordData = new RecordPaymentData(
                    presentation:    PaymentPresentation::fromArray($d['fx_presentation']),
                    shiftId:         $shift->id,
                    cashierId:       $s->user_id,
                    currencyPaid:    $d['currency'],
                    amountPaid:      (float) $d['amount'],
                    paymentMethod:   $d['method'],
                    overrideReason:  $d['override_reason'] ?? null,
                    managerApproval: null,
                );
                $this->botPaymentService->recordPayment($recordData);
            } else {
                // Microphase 7: FX presentation absent — hard block.
                // CashierPaymentService is no longer reachable from this controller.
                if ($callbackId) $this->failCallback($callbackId, 'FX presentation unavailable');
                $this->send($chatId, "❌ Курсы ФX недоступны. Обратитесь к менеджеру.");
                return $this->showMainMenu($chatId, $s);
            }

            if ($callbackId) $this->succeedCallback($callbackId);
            $this->send($chatId, "✅ Оплата записана!\nБаланс: " . $this->fmtBal($this->getBal($shift->fresh())));
        } catch (\App\Exceptions\StalePaymentSessionException $e) {
            if ($callbackId) $this->failCallback($callbackId, $e->getMessage());
            $this->send($chatId, "⏱ Сессия устарела (прошло > 20 мин). Начните заново.");
        } catch (\App\Exceptions\BookingNotPayableException $e) {
            if ($callbackId) $this->failCallback($callbackId, $e->getMessage());
            $this->send($chatId, "⚠️ Бронирование недоступно для оплаты (отменено?). Начните заново.");
        } catch (\App\Exceptions\PaymentBlockedException $e) {
            if ($callbackId) $this->failCallback($callbackId, $e->getMessage());
            $this->send($chatId, "🚫 Оплата заблокирована. Эскалируйте вопрос руководству.");
        } catch (\Exception $e) {
            if ($callbackId) $this->failCallback($callbackId, $e->getMessage());
            Log::error('Payment failed', ['e' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->alertOwnerOnError('Payment', $e, $s->user_id);
            $this->send($chatId, "❌ Ошибка при записи оплаты. Попробуйте снова.");
        }

        return $this->showMainMenu($chatId, $s);
    }

    // ── EXPENSE ─────────────────────────────────────────────────

    protected function startExpense($s, int $chatId)
    {
        $shift = $this->getShift($s->user_id);
        if (!$shift) { $this->send($chatId, "Сначала откройте смену."); return response('OK'); }
        $cats = ExpenseCategory::all();
        if ($cats->isEmpty()) {
            foreach (['Еда', 'Уборка', 'Ремонт', 'Такси', 'Хозтовары', 'Другое'] as $n) ExpenseCategory::firstOrCreate(['name' => $n]);
            $cats = ExpenseCategory::all();
        }
        $btns = $cats->map(fn($c) => [['text' => $c->name, 'callback_data' => "expcat_{$c->id}"]])->toArray();
        $s->update(['state' => 'expense_category', 'data' => ['shift_id' => $shift->id]]);
        $this->send($chatId, "Категория расхода:", ['inline_keyboard' => $btns], 'inline');
        return response('OK');
    }

    protected function selectExpCat($s, int $chatId, string $data)
    {
        $cat = ExpenseCategory::find((int) str_replace('expcat_', '', $data));
        $d = $s->data ?? [];
        $d['cat_id'] = $cat->id ?? 0;
        $d['cat_name'] = $cat->name ?? '?';
        $s->update(['state' => 'expense_amount', 'data' => $d]);
        $this->send($chatId, "Категория: {$d['cat_name']}\nВведите сумму (напр: 50000 или 20 USD):");
        return response('OK');
    }

    protected function hExpAmt($s, int $chatId, string $text)
    {
        $d = $s->data ?? [];
        [$amt, $cur] = $this->parseAmountCurrency($text);
        if ($amt <= 0) { $this->send($chatId, "Неверная сумма."); return response('OK'); }
        $d['amount'] = $amt;
        $d['currency'] = $cur;
        $s->update(['state' => 'expense_desc', 'data' => $d]);
        $this->send($chatId, "Сумма: " . number_format($amt, 0) . " {$d['currency']}\nОписание расхода:");
        return response('OK');
    }

    protected function hExpDesc($s, int $chatId, string $text)
    {
        $d = $s->data ?? [];
        $d['desc'] = $text;
        // Approval thresholds per currency (UZS equivalent ~$40)
        $thresholds = ['UZS' => config('services.cashier_bot.expense_approval_threshold_uzs', 500000), 'USD' => 40, 'EUR' => 35];
        $thr = $thresholds[$d['currency']] ?? $thresholds['UZS'];
        $d['needs_approval'] = ($d['amount'] > $thr);
        $s->update(['state' => 'expense_confirm', 'data' => $d]);
        $t = "Подтвердите расход:\n\nКатегория: {$d['cat_name']}\nСумма: " . number_format($d['amount'], 0) . " {$d['currency']}\nОписание: {$d['desc']}";
        if ($d['needs_approval']) $t .= "\n\nТребуется одобрение владельца.";
        $this->send($chatId, $t, ['inline_keyboard' => [[
            ['text' => 'Подтвердить', 'callback_data' => 'confirm_expense'],
            ['text' => 'Отмена', 'callback_data' => 'cancel'],
        ]]], 'inline');
        return response('OK');
    }

    protected function confirmExpense($s, int $chatId, string $callbackId = '')
    {
        $d = $s->data ?? [];
        $shift = CashierShift::find($d['shift_id'] ?? 0);
        if (!$shift || !$shift->isOpen()) {
            $this->send($chatId, "Смена не найдена или закрыта.");
            if ($callbackId) $this->failCallback($callbackId, 'Shift not found or closed');
            return $this->showMainMenu($chatId, $s);
        }

        try {
            $expense = $this->expenseService->recordExpense($shift->id, $d, $s->user_id, $callbackId);

            // Outside transaction: non-critical approval notification
            if (($d['needs_approval'] ?? false) && $expense) {
                try {
                    app(\App\Http\Controllers\OwnerBotController::class)->sendApprovalRequest($expense);
                } catch (\Exception $e) {
                    Log::warning('Expense approval notification failed', ['expense_id' => $expense->id, 'e' => $e->getMessage()]);
                }
            }
            $this->send($chatId, "Расход записан!\nБаланс: " . $this->fmtBal($this->getBal($shift->fresh())));
        } catch (\Exception $e) {
            if ($callbackId) $this->failCallback($callbackId, $e->getMessage());
            Log::error('Expense failed', ['e' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->alertOwnerOnError('Expense', $e, $s->user_id);
            $this->send($chatId, "Ошибка при записи расхода. Попробуйте снова.");
        }

        return $this->showMainMenu($chatId, $s);
    }

    // ── CASH IN (admin only) ────────────────────────────────────

    protected function startCashIn($s, int $chatId)
    {
        $shift = $this->getShift($s->user_id);
        if (!$shift) { $this->send($chatId, "Сначала откройте смену."); return response('OK'); }
        if (!User::find($s->user_id)?->hasAnyRole(['super_admin', 'admin', 'manager'])) {
            $this->send($chatId, "⛔ Недостаточно прав."); return response('OK');
        }
        $s->update(['state' => 'cash_in_amount', 'data' => ['shift_id' => $shift->id]]);
        $this->send($chatId, "Введите сумму и валюту для внесения (одна за раз):\n\nПримеры:\n• 500 USD\n• 200 EUR\n• 2000000 UZS");
        return response('OK');
    }

    protected function hCashInAmt($s, int $chatId, string $text)
    {
        [$amt, $cur] = $this->parseAmountCurrency($text);
        if ($amt <= 0) { $this->send($chatId, "Неверная сумма. Попробуйте ещё раз:"); return response('OK'); }
        $d = $s->data ?? [];
        $d['amount'] = $amt;
        $d['currency'] = $cur;
        $s->update(['state' => 'cash_in_amount', 'data' => $d]); // keep state until confirm
        $this->send($chatId,
            "Внести в кассу:\n\n" . number_format($amt, 0) . " {$cur}\n\nПодтвердите:",
            ['inline_keyboard' => [[
                ['text' => '✅ Внести', 'callback_data' => 'confirm_cash_in'],
                ['text' => '❌ Отмена', 'callback_data' => 'cancel'],
            ]]], 'inline'
        );
        return response('OK');
    }

    protected function confirmCashIn($s, int $chatId)
    {
        $d = $s->data ?? [];
        $shift = CashierShift::find($d['shift_id'] ?? 0);
        if (!$shift || !$shift->isOpen()) {
            $this->send($chatId, "Смена не найдена или закрыта.");
            return $this->showMainMenu($chatId, $s);
        }
        if (!User::find($s->user_id)?->hasAnyRole(['super_admin', 'admin', 'manager'])) {
            $this->send($chatId, "⛔ Недостаточно прав."); return response('OK');
        }
        $amt = (float) ($d['amount'] ?? 0);
        $cur = $d['currency'] ?? 'USD';
        if ($amt <= 0) { $this->send($chatId, "Сумма не найдена."); return $this->showMainMenu($chatId, $s); }

        CashTransaction::create([
            'cashier_shift_id' => $shift->id,
            'type'             => 'in',
            'category'         => 'deposit',
            'source_trigger'   => 'manual_admin',
            'currency'         => $cur,
            'amount'           => $amt,
            'notes'            => 'Внесение наличных (начальный баланс)',
            'created_by'       => $s->user_id,
            'occurred_at'      => now(),
        ]);

        $this->send($chatId, "✅ Внесено: " . number_format($amt, 0) . " {$cur}\nБаланс: " . $this->fmtBal($this->getBal($shift->fresh())));
        return $this->showMainMenu($chatId, $s);
    }

    // ── BALANCE ─────────────────────────────────────────────────

    protected function showBalance($s, int $chatId)
    {
        $shift = $this->getShift($s->user_id);
        if (!$shift) { $this->send($chatId, "Нет открытой смены."); return response('OK'); }
        $bal = $this->getBal($shift);
        $txn = CashTransaction::where('cashier_shift_id', $shift->id)->count();
        $exp = CashExpense::where('cashier_shift_id', $shift->id)->count();
        $this->send($chatId, "Баланс за смену\n\n" . $this->fmtBal($bal)
            . "\n\nОпераций: {$txn} | Расходов: {$exp}\nОткрыта: "
            . $shift->opened_at->timezone('Asia/Tashkent')->format('H:i'),
            ['inline_keyboard' => [[['text' => 'Назад', 'callback_data' => 'menu']]]], 'inline');
        return response('OK');
    }

    protected function showMyTransactions($s, int $chatId)
    {
        $shift = $this->getShift($s->user_id);
        if (!$shift) { $this->send($chatId, "Нет открытой смены."); return response('OK'); }

        $txns = CashTransaction::where('cashier_shift_id', $shift->id)
            ->drawerTruth()
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get();

        if ($txns->isEmpty()) {
            $this->send($chatId, "За эту смену операций ещё нет.", ['inline_keyboard' => [[['text' => '« Меню', 'callback_data' => 'menu']]]], 'inline');
            return response('OK');
        }

        $tz   = 'Asia/Tashkent';
        $lines = ["📋 *Операции смены* (последние {$txns->count()}):\n"];
        foreach ($txns as $tx) {
            $typeVal = is_string($tx->type) ? $tx->type : ($tx->type->value ?? 'out');
            $catVal  = is_string($tx->category) ? $tx->category : ($tx->category?->value ?? '');
            $cur     = is_string($tx->currency) ? $tx->currency : ($tx->currency?->value ?? '');
            $amt     = number_format((float) $tx->amount, 0, '.', ' ');
            $time    = $tx->occurred_at?->timezone($tz)->format('H:i') ?? '?';

            $icon = match(true) {
                $catVal === 'payment'  => '💵',
                $catVal === 'expense'  => '📤',
                $catVal === 'exchange' => '🔄',
                $catVal === 'cash_in'  => '➕',
                $typeVal === 'in'      => '⬆️',
                default                => '⬇️',
            };

            $sign = $typeVal === 'in' ? '+' : '−';

            // For exchanges show paired leg if available
            if ($catVal === 'exchange' && $tx->related_amount && $tx->related_currency) {
                $relCur = is_string($tx->related_currency) ? $tx->related_currency : ($tx->related_currency?->value ?? '');
                $relAmt = number_format((float) $tx->related_amount, 0, '.', ' ');
                $lines[] = "{$time}  {$icon} {$sign}{$amt} {$cur} / {$relAmt} {$relCur}";
            } else {
                $lines[] = "{$time}  {$icon} {$sign}{$amt} {$cur}" . ($tx->notes ? "  _{$tx->notes}_" : '');
            }
        }

        $this->send($chatId, implode("\n", $lines),
            ['inline_keyboard' => [[['text' => '« Меню', 'callback_data' => 'menu']]]], 'inline');
        return response('OK');
    }

    // ── CLOSE SHIFT ─────────────────────────────────────────────

    protected function startClose($s, int $chatId)
    {
        $shift = $this->getShift($s->user_id);
        if (!$shift) { $this->send($chatId, "Нет открытой смены."); return response('OK'); }
        $bal = $this->getBal($shift);
        $s->update(['state' => 'shift_count_uzs', 'data' => ['shift_id' => $shift->id, 'expected' => $bal]]);
        $this->send($chatId, "Закрытие смены\n\nОжидаемый баланс:\n" . $this->fmtBal($bal) . "\n\nПосчитайте UZS:");
        return response('OK');
    }

    protected function hCount($s, int $chatId, string $text, string $cur)
    {
        $amt = floatval(str_replace([' ', ','], ['', '.'], $text));
        if ($amt < 0) { $this->send($chatId, "Сумма не может быть отрицательной. Введите ещё раз:"); return response('OK'); }
        $d = $s->data ?? [];
        $d['counted_' . strtolower($cur)] = $amt;
        $next = match($cur) {
            'UZS' => ['shift_count_usd', 'Посчитайте USD (0 если нет):'],
            'USD' => ['shift_count_eur', 'Посчитайте EUR (0 если нет):'],
            'EUR' => ['shift_close_confirm', null], // Skip photo, go to confirm
        };
        $s->update(['state' => $next[0], 'data' => $d]);
        if ($next[0] === 'shift_close_confirm') {
            return $this->showCloseConfirm($s, $chatId);
        }
        $this->send($chatId, $next[1]);
        return response('OK');
    }

    protected function showCloseConfirm($s, int $chatId)
    {
        $d = $s->data ?? [];
        $exp = $d['expected'] ?? [];
        $lines = [];
        foreach (['uzs', 'usd', 'eur'] as $c) {
            $e = $exp[strtoupper($c)] ?? 0;
            $cnt = $d['counted_' . $c] ?? 0;
            if ($e != 0 || $cnt != 0) {
                $diff = round($cnt - $e, 2);
                $ds = abs($diff) < 0.01 ? '' : ($diff > 0 ? " (+" . number_format($diff, 0) . ")" : " (" . number_format($diff, 0) . ")");
                $lines[] = strtoupper($c) . ": ожид. " . number_format($e, 0) . " / факт " . number_format($cnt, 0) . $ds;
            }
        }
        $this->send($chatId, "Итог:\n\n" . implode("\n", $lines), ['inline_keyboard' => [[
            ['text' => 'Закрыть смену', 'callback_data' => 'confirm_close'],
            ['text' => 'Отмена', 'callback_data' => 'cancel'],
        ]]], 'inline');
        return response('OK');
    }

    protected function handleShiftPhoto($s, int $chatId, array $photos)
    {
        $photo = end($photos);
        $d = $s->data ?? [];
        $d['photo_id'] = $photo['file_id'] ?? '';
        $s->update(['state' => 'shift_close_confirm', 'data' => $d]);
        return $this->showCloseConfirm($s, $chatId);
    }

    protected function confirmClose($s, int $chatId, string $callbackId = '')
    {
        $d = $s->data ?? [];
        $shift = CashierShift::find($d['shift_id'] ?? 0);
        if (!$shift) {
            $this->send($chatId, "Смена не найдена.");
            if ($callbackId) $this->failCallback($callbackId, 'Shift not found');
            return $this->showMainMenu($chatId, $s);
        }

        try {
            $ho = $this->shiftService->closeShift($shift->id, $d, $callbackId);

            // === Outside transaction: non-critical notifications ===
            $this->sendShiftCloseNotifications($shift->fresh(), $s, $d, $ho);

            $this->send($chatId, "Смена закрыта!");
        } catch (\Exception $e) {
            if ($callbackId) $this->failCallback($callbackId, $e->getMessage());
            Log::error('Close shift failed', ['e' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->alertOwnerOnError('Close shift', $e, $s->user_id);
            // Clear stale counted amounts so retry forces re-count
            $s->update(['state' => 'main_menu', 'data' => null]);
            $this->send($chatId, "Ошибка при закрытии смены. Попробуйте заново.");
        }

        return $this->showMainMenu($chatId, $s);
    }

    /**
     * Build and send owner notification after shift close. Non-critical — failures are logged only.
     */
    private function sendShiftCloseNotifications(CashierShift $shift, $session, array $d, ShiftHandover $ho): void
    {
        $user = User::find($session->user_id);
        $txn = CashTransaction::where('cashier_shift_id', $shift->id)->count();

        // Build discrepancy data
        $discrepancies = [];
        foreach (['UZS', 'USD', 'EUR'] as $cur) {
            $exp = $d['expected'][$cur] ?? 0;
            $cnt = $d['counted_' . strtolower($cur)] ?? 0;
            $diff = round($cnt - $exp, 2);
            if ($exp > 0 || $cnt > 0) {
                $discrepancies[$cur] = ['expected' => $exp, 'counted' => $cnt, 'diff' => $diff];
            }
        }

        $hasDisc = $ho->hasDiscrepancy();
        $maxDiffPct = 0;
        foreach ($discrepancies as $cur => $vals) {
            if ($vals['expected'] > 0) {
                $pct = abs($vals['diff'] / $vals['expected']) * 100;
                $maxDiffPct = max($maxDiffPct, $pct);
            } elseif ($vals['counted'] != 0 && $vals['expected'] == 0) {
                $maxDiffPct = 100;
            }
        }

        $severity = $maxDiffPct < 1 ? '🟢' : ($maxDiffPct < 5 ? '🟡' : '🔴');
        $severityLabel = $maxDiffPct < 1 ? 'Без расхождений' : ($maxDiffPct < 5 ? 'Небольшое расхождение' : 'КРУПНОЕ РАСХОЖДЕНИЕ');

        $ownerMsg = "{$severity} <b>Смена закрыта</b>\n\n"
            . "👤 Сотрудник: " . ($user->name ?? '?') . "\n"
            . "⏰ " . $shift->opened_at->timezone('Asia/Tashkent')->format('H:i') . '–' . now('Asia/Tashkent')->format('H:i') . "\n"
            . "📊 Операций: {$txn}\n\n";

        foreach ($discrepancies as $cur => $vals) {
            $diffSign = $vals['diff'] >= 0 ? '+' : '';
            $diffStr = $vals['diff'] != 0 ? " (<b>{$diffSign}" . number_format($vals['diff'], 0) . "</b>)" : '';
            $ownerMsg .= "<b>{$cur}:</b> " . number_format($vals['expected'], 0) . " → " . number_format($vals['counted'], 0) . $diffStr . "\n";
        }

        if ($hasDisc) {
            $ownerMsg .= "\n⚠️ <b>{$severityLabel}</b> (" . round($maxDiffPct, 1) . "%)";
        } else {
            $ownerMsg .= "\n✅ {$severityLabel}";
        }

        try {
            $this->ownerAlert->sendShiftCloseReport($ownerMsg);

            if (!empty($d['photo_id'])) {
                $oid = (int) config('services.owner_alert_bot.owner_chat_id', env('OWNER_TELEGRAM_ID', '0'));
                if ($oid !== 0) {
                    $ownerBot = $this->botResolver->resolve('owner-alert');
                    $this->transport->call($ownerBot, 'sendPhoto', [
                        'chat_id' => $oid, 'photo' => $d['photo_id'], 'caption' => '📸 Фото кассы при закрытии смены',
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Close shift: owner notification failed', ['e' => $e->getMessage()]);
        }
    }


    // ── EXCHANGE ──────────────────────────────────────────────

    protected function startExchange($s, int $chatId)
    {
        $shift = $this->getShift($s->user_id);
        if (!$shift) { $this->send($chatId, "Сначала откройте смену."); return response('OK'); }
        $s->update(['state' => 'exchange_in_currency', 'data' => ['shift_id' => $shift->id]]);
        $this->send($chatId, "🔄 <b>Обмен валюты</b>\n\nВалюта ПРИЁМА (что получаете):", ['inline_keyboard' => [
            [
                ['text' => 'UZS', 'callback_data' => 'excur_UZS'],
                ['text' => 'USD', 'callback_data' => 'excur_USD'],
                ['text' => 'EUR', 'callback_data' => 'excur_EUR'],
            ],
            [['text' => '❌ Отмена', 'callback_data' => 'cancel']],
        ]], 'inline');
        return response('OK');
    }

    protected function selectExCur($s, int $chatId, string $data)
    {
        $d = $s->data ?? [];
        $d['in_currency'] = str_replace('excur_', '', $data);
        $s->update(['state' => 'exchange_in_amount', 'data' => $d]);
        $this->send($chatId, "Валюта приёма: <b>{$d['in_currency']}</b>\nВведите сумму ПРИЁМА:");
        return response('OK');
    }

    protected function hExInAmt($s, int $chatId, string $text)
    {
        $amt = floatval(str_replace([' ', ','], ['', '.'], $text));
        if ($amt <= 0) { $this->send($chatId, "Неверная сумма. Введите ещё раз:"); return response('OK'); }
        $d = $s->data ?? [];
        $d['in_amount'] = $amt;
        $s->update(['state' => 'exchange_out_currency', 'data' => $d]);

        // Show out currency options excluding the in currency
        $btns = [];
        foreach (['UZS', 'USD', 'EUR'] as $c) {
            if ($c !== $d['in_currency']) {
                $btns[] = ['text' => $c, 'callback_data' => "exout_{$c}"];
            }
        }
        $this->send($chatId, "Приём: <b>" . number_format($amt, 0) . " {$d['in_currency']}</b>\n\nВалюта ВЫДАЧИ (что отдаёте):", ['inline_keyboard' => [
            $btns,
            [['text' => '❌ Отмена', 'callback_data' => 'cancel']],
        ]], 'inline');
        return response('OK');
    }

    protected function selectExOutCur($s, int $chatId, string $data)
    {
        $d = $s->data ?? [];
        $d['out_currency'] = str_replace('exout_', '', $data);
        $s->update(['state' => 'exchange_out_amount', 'data' => $d]);
        $this->send($chatId, "Приём: <b>" . number_format($d['in_amount'], 0) . " {$d['in_currency']}</b>\nВыдача: <b>{$d['out_currency']}</b>\n\nВведите сумму ВЫДАЧИ:");
        return response('OK');
    }

    protected function hExOutAmt($s, int $chatId, string $text)
    {
        $amt = floatval(str_replace([' ', ','], ['', '.'], $text));
        if ($amt <= 0) { $this->send($chatId, "Неверная сумма. Введите ещё раз:"); return response('OK'); }
        $d = $s->data ?? [];
        $d['out_amount'] = $amt;
        $s->update(['state' => 'exchange_confirm', 'data' => $d]);

        // Calculate approximate rate
        $rate = '';
        if ($d['in_currency'] === 'UZS' && $d['out_currency'] !== 'UZS') {
            $r = round($d['in_amount'] / $amt, 0);
            $rate = "\nКурс: ~" . number_format($r, 0) . " UZS/{$d['out_currency']}";
        } elseif ($d['out_currency'] === 'UZS' && $d['in_currency'] !== 'UZS') {
            $r = round($d['out_amount'] / $d['in_amount'], 0);
            $rate = "\nКурс: ~" . number_format($r, 0) . " UZS/{$d['in_currency']}";
        }

        $this->send($chatId, "🔄 <b>Подтвердите обмен:</b>\n\n📥 Приём: <b>" . number_format($d['in_amount'], 0) . " {$d['in_currency']}</b>"
            . "\n📤 Выдача: <b>" . number_format($amt, 0) . " {$d['out_currency']}</b>"
            . $rate, ['inline_keyboard' => [[
                ['text' => '✅ Подтвердить', 'callback_data' => 'confirm_exchange'],
                ['text' => '❌ Отмена', 'callback_data' => 'cancel'],
            ]]], 'inline');
        return response('OK');
    }

    protected function confirmExchange($s, int $chatId, string $callbackId = '')
    {
        $d = $s->data ?? [];
        $shift = CashierShift::find($d['shift_id'] ?? 0);
        if (!$shift || !$shift->isOpen()) {
            $this->send($chatId, "Смена не найдена или закрыта.");
            if ($callbackId) $this->failCallback($callbackId, 'Shift not found or closed');
            return $this->showMainMenu($chatId, $s);
        }

        try {
            $this->exchangeService->recordExchange($shift->id, $d, $s->user_id, $callbackId);

            // Outside transaction: non-critical messaging
            $this->send($chatId, "✅ Обмен записан!\n\n📥 +" . number_format($d['in_amount'], 0) . " {$d['in_currency']}\n📤 -" . number_format($d['out_amount'], 0) . " {$d['out_currency']}\n\nБаланс: " . $this->fmtBal($this->getBal($shift->fresh())));
        } catch (\Exception $e) {
            if ($callbackId) $this->failCallback($callbackId, $e->getMessage());
            Log::error('Exchange failed', ['e' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->alertOwnerOnError('Exchange', $e, $s->user_id);
            $this->send($chatId, "Ошибка при записи обмена. Попробуйте снова.");
        }

        return $this->showMainMenu($chatId, $s);
    }

    // ── HELPERS ──────────────────────────────────────────────────

    /**
     * Extract guest name, outstanding amount, and currency from a live Beds24 booking payload.
     *
     * Returns a structured snapshot with a `currency_source` field so callers can log
     * when a fallback was used (regex failed or live payload missing).
     *
     * @param  array|null  $liveGuest  Raw Beds24 API booking object (may be null if not in session)
     * @param  string      $bid        Beds24 booking ID (for log context only)
     * @return array{guest_name:string|null, booking_amount:float|null, booking_currency:string|null, currency_source:string}
     */
    protected function extractLiveBookingSnapshot(?array $liveGuest, string $bid): array
    {
        if (! $liveGuest) {
            return [
                'guest_name'       => null,
                'booking_amount'   => null,
                'booking_currency' => null,
                'currency_source'  => 'no_live_payload',
            ];
        }

        $firstName = trim($liveGuest['firstName'] ?? '');
        $lastName  = trim($liveGuest['lastName']  ?? '');
        $guestName = trim("{$firstName} {$lastName}") ?: null;

        // Outstanding = total price minus deposit already collected; floor to price if result ≤ 0
        $amount = (float)($liveGuest['price'] ?? 0) - (float)($liveGuest['deposit'] ?? 0);
        if ($amount <= 0) $amount = (float)($liveGuest['price'] ?? 0);

        // Beds24 REST GET /bookings has no dedicated currency field.
        // Currency is reliably embedded in rateDescription e.g. "2026-06-07 (ID Rate) USD 43.20"
        $currency       = null;
        $currencySource = 'no_live_payload';

        if (! empty($liveGuest['rateDescription']) &&
            preg_match('/\b(USD|EUR|GBP|RUB|UZS|JPY|CNY|AUD|CAD|CHF)\b/', $liveGuest['rateDescription'], $m)) {
            $currency       = $m[1];
            $currencySource = 'live_rate_description';
        } else {
            $currencySource = 'live_payload_present_but_currency_regex_failed';
        }

        return [
            'guest_name'       => $guestName,
            'booking_amount'   => $amount > 0 ? $amount : null,
            'booking_currency' => $currency,
            'currency_source'  => $currencySource,
        ];
    }

    protected function getShift(?int $uid): ?CashierShift
    {
        if (!$uid) return null;
        return CashierShift::where('user_id', $uid)->where('status', 'open')->latest('opened_at')->first();
    }

    protected function getBal(CashierShift $shift): array
    {
        $b = ['UZS' => 0, 'USD' => 0, 'EUR' => 0];

        // Include beginning saldos (carried forward from previous shift)
        foreach (BeginningSaldo::where('cashier_shift_id', $shift->id)->get() as $bs) {
            $c = is_string($bs->currency) ? $bs->currency : ($bs->currency->value ?? 'UZS');
            if (!isset($b[$c])) $b[$c] = 0;
            $b[$c] += $bs->amount;
        }

        // Add transactions (drawerTruth excludes beds24_external audit rows)
        foreach (CashTransaction::where('cashier_shift_id', $shift->id)->drawerTruth()->get() as $tx) {
            $c = is_string($tx->currency) ? $tx->currency : ($tx->currency->value ?? 'UZS');
            if (!isset($b[$c])) $b[$c] = 0;
            $typeVal = is_string($tx->type) ? $tx->type : ($tx->type->value ?? 'out');
            if ($typeVal === 'in_out') continue; // complex transactions handled separately
            $b[$c] += ($typeVal === 'in' ? $tx->amount : -$tx->amount);
        }
        return $b;
    }

    protected function fmtBal(array $b): string
    {
        $p = [];
        foreach ($b as $c => $a) { if ($a != 0) $p[] = number_format($a, 0) . " {$c}"; }
        return $p ? implode(' | ', $p) : '0';
    }

    protected function menuKb(?CashierShift $shift, ?int $userId = null): array
    {
        if (!$shift) return ['inline_keyboard' => [[['text' => 'Открыть смену', 'callback_data' => 'open_shift']], [['text' => '📖 Инструкция', 'callback_data' => 'guide']]]];
        $isAdmin = $userId && User::find($userId)?->hasAnyRole(['super_admin', 'admin', 'manager']);
        $kb = [
            [['text' => '💵 Оплата', 'callback_data' => 'payment'], ['text' => '📤 Расход', 'callback_data' => 'expense']],
            [['text' => '🔄 Обмен', 'callback_data' => 'exchange'], ['text' => '💰 Баланс', 'callback_data' => 'balance']],
            [['text' => '📋 Мои операции', 'callback_data' => 'my_txns']],
        ];
        if ($isAdmin) {
            $kb[] = [['text' => '➕ Внести', 'callback_data' => 'cash_in']];
        }
        $kb[] = [['text' => '🔒 Закрыть смену', 'callback_data' => 'close_shift'], ['text' => '📖 Инструкция', 'callback_data' => 'guide']];
        return ['inline_keyboard' => $kb];
    }


    /**
     * Parse amount and currency from flexible user input.
     * Supports: "50000", "20 USD", "20 usd", "$20", "20$", "€15", "15€", "20 $", "20 долларов"
     */
    protected function parseAmountCurrency(string $text): array
    {
        $text = trim($text);
        $symbolMap = ['$' => 'USD', '€' => 'EUR', '₽' => 'RUB'];
        $wordMap = [
            'доллар' => 'USD', 'долларов' => 'USD', 'баксов' => 'USD', 'бакс' => 'USD',
            'евро' => 'EUR', 'сум' => 'UZS', 'сумов' => 'UZS',
        ];

        // Check for symbol prefix: $20, €15
        foreach ($symbolMap as $sym => $cur) {
            if (str_starts_with($text, $sym)) {
                $amt = floatval(str_replace([' ', ','], ['', '.'], substr($text, strlen($sym))));
                return [$amt, $cur];
            }
        }

        // Check for symbol suffix: 20$, 15€, "20 $"
        foreach ($symbolMap as $sym => $cur) {
            if (str_ends_with(rtrim($text), $sym)) {
                $amt = floatval(str_replace([' ', ',', $sym], ['', '.', ''], $text));
                return [$amt, $cur];
            }
        }

        // Split by spaces: "20 USD", "20 usd", "50000 сум"
        $parts = preg_split('/\s+/', $text);
        $amt = floatval(str_replace(',', '.', $parts[0] ?? '0'));
        $curText = strtolower(trim($parts[1] ?? ''));

        if (!$curText) {
            return [$amt, 'UZS'];
        }

        $curUpper = strtoupper($curText);
        if (in_array($curUpper, ['UZS', 'USD', 'EUR', 'RUB'])) {
            return [$amt, $curUpper];
        }

        // Check symbol as second part: "20 $"
        if (isset($symbolMap[$curText])) {
            return [$amt, $symbolMap[$curText]];
        }

        // Check Russian words
        foreach ($wordMap as $word => $cur) {
            if (str_starts_with($curText, $word)) {
                return [$amt, $cur];
            }
        }

        return [$amt, 'UZS'];
    }

    protected function phoneKb(): array
    {
        return ['keyboard' => [[['text' => 'Отправить номер', 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
    }

    protected function send(int $chatId, string $text, ?array $kb = null, string $type = 'reply')
    {
        $extra = ['parse_mode' => 'HTML'];
        if ($kb) $extra['reply_markup'] = json_encode($kb);
        try {
            $bot = $this->botResolver->resolve('cashier');
            $result = $this->transport->sendMessage($bot, $chatId, $text, $extra);
            if (!$result->succeeded()) {
                Log::warning('CashierBot send failed', ['chat' => $chatId, 'status' => $result->httpStatus]);
            }
        } catch (\Throwable $e) {
            Log::error('CashierBot send error', ['chat' => $chatId, 'error' => $e->getMessage()]);
        }
    }

    protected function aCb(string $id)
    {
        if (!$id) return;
        try {
            $bot = $this->botResolver->resolve('cashier');
            $this->transport->call($bot, 'answerCallbackQuery', ['callback_query_id' => $id]);
        } catch (\Throwable $e) {
            Log::warning('CashierBot aCb failed', ['id' => $id, 'error' => $e->getMessage()]);
        }
    }

    protected function alertOwnerOnError(string $context, \Throwable $e, ?int $userId = null): void
    {
        try {
            $user = $userId ? (User::find($userId)?->name ?? "ID:{$userId}") : 'unknown';
            $msg = "\xF0\x9F\x94\xB4 <b>Cashier Bot Error</b>\n\n"
                . "\xF0\x9F\x93\x8D {$context}\n"
                . "\xF0\x9F\x91\xA4 {$user}\n"
                . "\xE2\x9D\x8C " . mb_substr($e->getMessage(), 0, 200) . "\n"
                . "\xF0\x9F\x93\x84 " . basename($e->getFile()) . ":" . $e->getLine();
            $this->ownerAlert->sendShiftCloseReport($msg);
        } catch (\Throwable $ignore) {}
    }

    // ── GUIDE / INSTRUCTIONS ────────────────────────────────────

    protected function showGuide(int $chatId)
    {
        $text = "📖 <b>Инструкция — Кассир Бот</b>\n\nВыберите раздел:";
        $kb = ['inline_keyboard' => [
            [['text' => '💵 Оплата гостя', 'callback_data' => 'guide_payment'], ['text' => '📤 Расходы', 'callback_data' => 'guide_expense']],
            [['text' => '🔄 Обмен валюты', 'callback_data' => 'guide_exchange'], ['text' => '💰 Баланс', 'callback_data' => 'guide_balance']],
            [['text' => '🟢 Открытие смены', 'callback_data' => 'guide_open'], ['text' => '🔒 Закрытие смены', 'callback_data' => 'guide_close']],
            [['text' => '💡 Советы и правила', 'callback_data' => 'guide_tips']],
            [['text' => '« Назад в меню', 'callback_data' => 'menu']],
        ]];
        $this->send($chatId, $text, $kb, 'inline');
        return response('OK');
    }

    protected function showGuideTopic(int $chatId, string $topic)
    {
        $content = match($topic) {
            'payment' =>
                "💵 <b>Оплата гостя</b>\n\n"
                . "1. Нажмите «💵 Оплата»\n"
                . "2. Выберите гостя из списка заездов\n"
                . "3. Или нажмите ✏️ Ручной ввод и введите номер брони\n"
                . "4. Введите сумму (например: <code>500000</code>)\n"
                . "5. Выберите валюту (UZS/USD/EUR)\n"
                . "6. Выберите способ оплаты (нал/карта/перевод)\n"
                . "7. Подтвердите ✅\n\n"
                . "💡 <b>Форматы суммы:</b>\n"
                . "• <code>500000</code> — простое число\n"
                . "• <code>500 000</code> — с пробелом\n"
                . "• <code>$100</code> или <code>100$</code> — автоматически USD\n"
                . "• <code>100 долларов</code> — автоматически USD",
            'expense' =>
                "📤 <b>Расходы</b>\n\n"
                . "1. Нажмите «📤 Расход»\n"
                . "2. Выберите категорию:\n"
                . "   🛒 Хозтовары, 🍽️ Еда, 🔧 Ремонт,\n"
                . "   🚕 Транспорт, 👕 Прачечная и др.\n"
                . "3. Введите сумму\n"
                . "4. Опишите расход (например: «Моющее средство»)\n"
                . "5. Подтвердите ✅\n\n"
                . "💡 <b>Умный ввод суммы:</b>\n"
                . "• <code>50000</code> — автоматически UZS\n"
                . "• <code>$20</code> или <code>20$</code> — автоматически USD\n"
                . "• <code>€15</code> — автоматически EUR\n"
                . "• <code>20 долларов</code> — автоматически USD",
            'exchange' =>
                "🔄 <b>Обмен валюты</b>\n\n"
                . "<b>Пример:</b> Гость даёт $100, вы даёте 1,280,000 сум\n\n"
                . "1. Нажмите «🔄 Обмен»\n"
                . "2. Выберите валюту ПРИЁМА → USD\n"
                . "3. Введите сумму приёма → <code>100</code>\n"
                . "4. Выберите валюту ВЫДАЧИ → UZS\n"
                . "5. Введите сумму выдачи → <code>1280000</code>\n"
                . "6. Подтвердите ✅\n\n"
                . "📌 Бот создаст 2 записи:\n"
                . "• 📥 +100 USD (приход)\n"
                . "• 📤 -1,280,000 UZS (расход)",
            'balance' =>
                "💰 <b>Баланс</b>\n\n"
                . "Нажмите «💰 Баланс» чтобы увидеть:\n\n"
                . "• Текущий баланс по каждой валюте\n"
                . "• Общий приход\n"
                . "• Общий расход\n"
                . "• Количество транзакций\n\n"
                . "💡 Проверяйте баланс 2-3 раза в день!",
            'open' =>
                "🟢 <b>Открытие смены</b>\n\n"
                . "1. Нажмите «Открыть смену»\n"
                . "2. Бот подтвердит открытие\n"
                . "3. Появится главное меню с кнопками\n\n"
                . "⚠️ <b>Правила:</b>\n"
                . "• Открывайте смену в начале рабочего дня\n"
                . "• Только одна смена может быть открыта\n"
                . "• Если прошлая не закрыта — закройте сначала",
            'close' =>
                "🔒 <b>Закрытие смены</b>\n\n"
                . "1. Нажмите «🔒 Закрыть смену»\n"
                . "2. Бот попросит пересчитать кассу\n"
                . "3. Введите фактическую сумму по каждой валюте\n"
                . "4. Бот сравнит с системой:\n"
                . "   ✅ Совпадает — «Отлично!»\n"
                . "   ⚠️ Разница — покажет расхождение\n\n"
                . "⚠️ <b>Важно:</b>\n"
                . "• ОБЯЗАТЕЛЬНО закрывайте смену!\n"
                . "• Считайте деньги внимательно\n"
                . "• О большом расхождении — сообщите менеджеру",
            'tips' =>
                "💡 <b>Советы и правила</b>\n\n"
                . "1️⃣ Записывайте КАЖДУЮ операцию СРАЗУ\n\n"
                . "2️⃣ Добавляйте заметки — «Гость #205», «Моющее»\n\n"
                . "3️⃣ Проверяйте баланс 2-3 раза в день\n\n"
                . "4️⃣ При закрытии — считайте деньги дважды\n\n"
                . "5️⃣ Ошиблись? Сразу сообщите менеджеру\n\n"
                . "6️⃣ Не давайте телефон посторонним\n\n"
                . "❓ Проблемы? Обратитесь к менеджеру",
            default => "Раздел не найден.",
        };

        $kb = ['inline_keyboard' => [
            [['text' => '« К инструкции', 'callback_data' => 'guide']],
        ]];
        $this->send($chatId, $content, $kb, 'inline');
        return response('OK');
    }

}
