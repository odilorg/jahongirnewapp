<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\CashExpense;
use App\Models\ShiftHandover;
use App\Models\EndSaldo;
use App\Enums\CashTransactionSource;
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
use App\Services\CashierShiftService;
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
    protected BotPaymentService $botPaymentService;
    protected FxOverridePolicyEvaluator $overridePolicy;
    protected CashierShiftService $shiftService;
    protected CashierExpenseService $expenseService;
    protected CashierExchangeService $exchangeService;
    protected BotResolverInterface $botResolver;
    protected TelegramTransportInterface $transport;
    protected Beds24BookingService $beds24;
    protected \App\Services\Cashier\BalanceCalculator $balance;
    protected \App\Services\CashierBot\CashierBotCallbackRouter $callbackRouter;

    // Property ID for Beds24 — must match HousekeepingBotController
    protected const PROPERTY_ID = 41097;

    public function __construct(
        OwnerAlertService $ownerAlert,
        BotPaymentService $botPaymentService,
        FxOverridePolicyEvaluator $overridePolicy,
        CashierShiftService $shiftService,
        CashierExpenseService $expenseService,
        CashierExchangeService $exchangeService,
        BotResolverInterface $botResolver,
        TelegramTransportInterface $transport,
        Beds24BookingService $beds24,
        \App\Services\Cashier\BalanceCalculator $balance,
        \App\Services\CashierBot\CashierBotCallbackRouter $callbackRouter,
    ) {
        $this->ownerAlert = $ownerAlert;
        $this->botPaymentService = $botPaymentService;
        $this->overridePolicy = $overridePolicy;
        $this->shiftService = $shiftService;
        $this->expenseService = $expenseService;
        $this->exchangeService = $exchangeService;
        $this->botResolver = $botResolver;
        $this->transport = $transport;
        $this->beds24 = $beds24;
        $this->balance = $balance;
        $this->callbackRouter = $callbackRouter;
    }

    public function handleWebhook(Request $request)
    {
        $data = $request->all();
        $updateId = $data['update_id'] ?? null;

        // Durable ingest: persist raw update, return 200 fast, process async.
        // firstOrCreate makes this idempotent — Telegram retries after a prior 500
        // will find the existing row and skip dispatching again.
        $eventId = $updateId ? "cashier:{$updateId}" : null;

        if ($eventId) {
            $webhook = \App\Models\IncomingWebhook::firstOrCreate(
                ['event_id' => $eventId],
                [
                    'source'      => 'telegram:cashier',
                    'payload'     => $data,
                    'status'      => \App\Models\IncomingWebhook::STATUS_PENDING,
                    'received_at' => now(),
                ]
            );
        } else {
            $webhook = \App\Models\IncomingWebhook::create([
                'source'      => 'telegram:cashier',
                'event_id'    => null,
                'payload'     => $data,
                'status'      => \App\Models\IncomingWebhook::STATUS_PENDING,
                'received_at' => now(),
            ]);
        }

        // Only dispatch for newly created rows; duplicate retries get a 200 with no re-work.
        if ($webhook->wasRecentlyCreated) {
            \App\Jobs\ProcessTelegramUpdateJob::dispatch('cashier', $webhook->id);
        }

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
        $session->updateActivity(); // touches updated_at; isExpired() checks that column
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
        $result = app(\App\Actions\CashierBot\Handlers\HandleAuthAction::class)->execute($chatId, $contact);
        $reply = $result['reply'];
        $this->send($chatId, $reply['text'], $reply['kb'] ?? null, $reply['type'] ?? 'reply');

        if (! $result['ok']) {
            return response('OK');
        }

        return $this->showMainMenu($chatId, $result['session']);
    }

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function showMainMenu(int $chatId, $session)
    {
        // Delegates to ShowMainMenuAction which owns the status-line +
        // keyboard-building logic (including menuKb() which used to live
        // inline below). Kept as a delegator because ~18 internal call
        // sites still invoke $this->showMainMenu(...) directly.
        foreach (app(\App\Actions\CashierBot\Handlers\ShowMainMenuAction::class)->execute($session) as $reply) {
            $this->send($chatId, $reply['text'], $reply['kb'] ?? null, $reply['type'] ?? 'reply');
        }

        return response('OK');
    }

    protected function handleCallback(array $cb)
    {
        return $this->callbackRouter->dispatch($cb, $this);
    }

    // ── Callback idempotency lifecycle (succeed/fail only — claim moved to router) ──

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
            default => $this->resetSessionToMainMenu($s, $chatId),
        };
    }

    /**
     * Defensive fallback for sessions that arrive in an unrecognized state
     * (e.g. survived a deploy that removed a state, or any future drift).
     * Clears stale `data`, returns the user to the main menu, and logs the
     * event so ops can spot drift if the rate becomes non-trivial.
     */
    protected function resetSessionToMainMenu(TelegramPosSession $s, int $chatId)
    {
        Log::info('CashierBot: orphan session state reset', [
            'chat_id'   => $chatId,
            'user_id'   => $s->user_id,
            'old_state' => $s->state,
            'data_keys' => array_keys($s->data ?? []),
        ]);

        $s->update(['state' => 'main_menu', 'data' => null]);
        $this->send($chatId, 'Сессия устарела, начните заново.');

        return $this->showMainMenu($chatId, $s);
    }

    // ── SHIFT ───────────────────────────────────────────────────

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function openShift($s, int $chatId)
    {
        // The B2 drawer-singleton guard + carry-forward + balance line
        // lives in OpenShiftAction (feature-tested by OpenShiftSingletonTest).
        $result = app(\App\Actions\CashierBot\Handlers\OpenShiftAction::class)->execute($s);

        foreach ($result['replies'] as $reply) {
            $this->send($chatId, $reply['text'], $reply['kb'] ?? null, $reply['type'] ?? 'reply');
        }

        if ($result['show_main_menu']) {
            return $this->showMainMenu($chatId, $result['session']);
        }

        return response('OK');
    }

    // ── PAYMENT ─────────────────────────────────────────────────

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function startPayment($s, int $chatId)
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

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function selectGuest($s, int $chatId, string $data)
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

        // 2. Local DB — needed for FX presentation path
        $b = Beds24Booking::where('beds24_booking_id', $bid)->first();

        // 3. On-demand import — booking arrived via Beds24 API but was never synced locally.
        //    Live payload is already in session (fetched by startPayment/hPayRoom), no extra call.
        if (! $b && $liveGuest) {
            $b = $this->importBookingFromLiveData($bid, $liveGuest);
        }

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

        // Warn if still missing — import was attempted above but failed, or no live data was available
        if (! $b) {
            Log::warning('CashierBot: booking not in local DB and import failed — payment blocked', [
                'beds24_booking_id' => $bid,
                'amount_from_live'  => $d['booking_amount'],
                'guest_name'        => $d['guest_name'],
                'live_data_present' => (bool) $liveGuest,
                'import_attempted'  => (bool) $liveGuest,
            ]);
        }

        if ($b && $d['booking_amount'] > 0) {
            try {
                $botSessionId = (string) $chatId . ':' . ($d['shift_id'] ?? '0');
                $presentation = $this->botPaymentService->preparePayment($bid, $botSessionId);
                $d['fx_presentation'] = $presentation->toArray();

                $arrival = \Carbon\Carbon::parse($presentation->arrivalDate)->format('d.m');
                $s->update(['state' => 'payment_fx_currency', 'data' => $d]);

                // Hide buttons whose presented amount is ≤ 0 to avoid offering
                // a "USD: 0.00" choice that BotPaymentService would reject. UZS
                // is the booking-equivalent and effectively always populated, so
                // we keep its button shown even at 0 (showing 0 is rare and
                // operationally informative).
                $row = [
                    ['text' => '🇺🇿 UZS: ' . number_format($presentation->uzsPresented, 0, '.', ' '), 'callback_data' => 'cur_UZS'],
                ];
                if ($presentation->usdPresented > 0) {
                    $row[] = ['text' => '🇺🇸 USD: ' . number_format($presentation->usdPresented, 2, '.', ' '), 'callback_data' => 'cur_USD'];
                }
                if ($presentation->eurPresented > 0) {
                    $row[] = ['text' => '🇪🇺 EUR: ' . number_format($presentation->eurPresented, 0), 'callback_data' => 'cur_EUR'];
                }
                if ($presentation->rubPresented > 0) {
                    $row[] = ['text' => '🇷🇺 RUB: ' . number_format($presentation->rubPresented, 0, '.', ' '), 'callback_data' => 'cur_RUB'];
                }

                $this->send($chatId,
                    "💳 Бронь #{$bid} | {$presentation->guestName} | Заезд: {$arrival}\n"
                    . "Курс на {$presentation->fxRateDate}\n\n"
                    . "Выберите валюту оплаты:",
                    ['inline_keyboard' => [$row]],
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

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function selectCur($s, int $chatId, string $data)
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
                // Microphase 7.1: FX presentation invalid — stop immediately, do not fall through.
                Log::warning('CashierBot: failed to get presentedAmountFor — payment blocked', [
                    'currency' => $d['currency'],
                    'error'    => $e->getMessage(),
                ]);
                $this->send($chatId, "❌ Курсы ФX недоступны. Обратитесь к менеджеру.");
                return response('OK');
            }
        }

        // Microphase 7.1: FX presentation absent — hard block (defence-in-depth).
        // This path was the legacy manual/fallback route; it is no longer reachable
        // from real cashier traffic because selectGuest() blocks before we get here.
        $this->send($chatId, "❌ Курсы ФX недоступны. Обратитесь к менеджеру.");
        return response('OK');
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
    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function fxConfirmAmount($s, int $chatId)
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

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function selectMethod($s, int $chatId, string $data)
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
        }

        $this->send($chatId, $t, ['inline_keyboard' => [[
            ['text' => 'Подтвердить', 'callback_data' => 'confirm_payment'],
            ['text' => 'Отмена', 'callback_data' => 'cancel'],
        ]]], 'inline');
        return response('OK');
    }

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function confirmPayment($s, int $chatId, string $callbackId = '')
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
                // Microphases 7 & 8: FX presentation absent — hard block.
                // CashierPaymentService has been deleted; only the FX path above is reachable.
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
        } catch (\App\Exceptions\DuplicatePaymentException $e) {
            // Operator already recorded this booking once. Show the existing tx
            // details so they can confirm / hand off without retrying blindly.
            if ($callbackId) $this->failCallback($callbackId, $e->getMessage());
            $this->send($chatId, $this->formatDuplicatePaymentMessage((int) ($d['booking_id'] ?? 0)));
        } catch (\App\Exceptions\DuplicateGroupPaymentException $e) {
            if ($callbackId) $this->failCallback($callbackId, $e->getMessage());
            $this->send($chatId, $this->formatDuplicateGroupPaymentMessage((int) ($d['booking_id'] ?? 0)));
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

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function startExpense($s, int $chatId)
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

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function selectExpCat($s, int $chatId, string $data)
    {
        $cat = ExpenseCategory::find((int) str_replace('expcat_', '', $data));
        $d = $s->data ?? [];
        $d['cat_id'] = $cat->id ?? 0;
        $d['cat_name'] = $cat->name ?? '?';
        $s->update(['state' => 'expense_amount', 'data' => $d]);
        $this->send($chatId, "Категория: {$d['cat_name']}\nВведите сумму:\n• 50000 (UZS по умолчанию)\n• 20 USD\n• 15 EUR\n• 1500 RUB");
        return response('OK');
    }

    protected function hExpAmt($s, int $chatId, string $text)
    {
        $d = $s->data ?? [];
        [$amt, $cur] = $this->parseAmountCurrency($text);
        if ($amt <= 0) {
            $this->send($chatId, "Неверная сумма. Введите так:\n• 50000 (UZS)\n• 20 USD\n• 15 EUR\n• 1500 RUB");
            return response('OK');
        }
        if ($cur === null) {
            // Currency token present but unrecognised. DO NOT silently
            // default to UZS — that silently mis-records foreign-currency
            // expenses (financial-integrity rule).
            $this->send($chatId, "Не понял валюту. Введите ещё раз:\n• 50000 (UZS)\n• 20 USD\n• 15 EUR\n• 1500 RUB");
            return response('OK');
        }
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

        // Approval gate is opt-in. Default OFF — expenses go "straight thru"
        // (already recorded against the shift; the gate is a sign-off ping
        // only, not a hold-until-approved). Set CASHIER_EXPENSE_APPROVAL=true
        // in env to re-enable owner approval pings above the per-currency
        // thresholds.
        $approvalEnabled = (bool) config('services.cashier_bot.expense_approval_enabled', false);
        if ($approvalEnabled) {
            $thresholds = [
                'UZS' => config('services.cashier_bot.expense_approval_threshold_uzs', 500000),
                'USD' => config('services.cashier_bot.expense_approval_threshold_usd', 40),
                'EUR' => config('services.cashier_bot.expense_approval_threshold_eur', 35),
                'RUB' => config('services.cashier_bot.expense_approval_threshold_rub', 4000),
            ];
            $thr = $thresholds[$d['currency']] ?? $thresholds['UZS'];
            $d['needs_approval'] = ($d['amount'] > $thr);
        } else {
            $d['needs_approval'] = false;
        }

        $s->update(['state' => 'expense_confirm', 'data' => $d]);
        $t = "Подтвердите расход:\n\nКатегория: {$d['cat_name']}\nСумма: " . number_format($d['amount'], 0) . " {$d['currency']}\nОписание: {$d['desc']}";
        if ($d['needs_approval']) $t .= "\n\nТребуется одобрение владельца.";
        $this->send($chatId, $t, ['inline_keyboard' => [[
            ['text' => 'Подтвердить', 'callback_data' => 'confirm_expense'],
            ['text' => 'Отмена', 'callback_data' => 'cancel'],
        ]]], 'inline');
        return response('OK');
    }

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function confirmExpense($s, int $chatId, string $callbackId = '')
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

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function startCashIn($s, int $chatId)
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
        if ($amt <= 0) {
            $this->send($chatId, "Неверная сумма. Введите так:\n• 500 USD\n• 200 EUR\n• 2000000 UZS\n• 1500 RUB");
            return response('OK');
        }
        if ($cur === null) {
            $this->send($chatId, "Не понял валюту. Введите ещё раз:\n• 500 USD\n• 200 EUR\n• 2000000 UZS\n• 1500 RUB");
            return response('OK');
        }
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

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function confirmCashIn($s, int $chatId, string $callbackId = '')
    {
        try {
            $d = $s->data ?? [];
            $shift = CashierShift::find($d['shift_id'] ?? 0);
            if (!$shift || !$shift->isOpen()) {
                $this->send($chatId, "Смена не найдена или закрыта.");
                if ($callbackId) $this->failCallback($callbackId, 'Shift not found or closed');
                return $this->showMainMenu($chatId, $s);
            }
            if (!User::find($s->user_id)?->hasAnyRole(['super_admin', 'admin', 'manager'])) {
                $this->send($chatId, "⛔ Недостаточно прав.");
                if ($callbackId) $this->failCallback($callbackId, 'Insufficient role');
                return response('OK');
            }
            $amt = (float) ($d['amount'] ?? 0);
            $cur = $d['currency'] ?? 'USD';
            if ($amt <= 0) {
                $this->send($chatId, "Сумма не найдена.");
                if ($callbackId) $this->failCallback($callbackId, 'Amount missing');
                return $this->showMainMenu($chatId, $s);
            }

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

            if ($callbackId) $this->succeedCallback($callbackId);
            $this->send($chatId, "✅ Внесено: " . number_format($amt, 0) . " {$cur}\nБаланс: " . $this->fmtBal($this->getBal($shift->fresh())));
            return $this->showMainMenu($chatId, $s);
        } catch (\Throwable $e) {
            if ($callbackId) $this->failCallback($callbackId, $e->getMessage());
            throw $e;
        }
    }

    // showBalance + showMyTransactions were extracted to
    // App\Actions\CashierBot\Handlers\ShowBalanceAction and
    // ShowMyTransactionsAction; the router dispatches them via
    // dispatchReply() above.

    // ── CLOSE SHIFT ─────────────────────────────────────────────

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function startClose($s, int $chatId)
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

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function confirmClose($s, int $chatId, string $callbackId = '')
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

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function startExchange($s, int $chatId)
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

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function selectExCur($s, int $chatId, string $data)
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

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function selectExOutCur($s, int $chatId, string $data)
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

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function confirmExchange($s, int $chatId, string $callbackId = '')
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

    /**
     * Upsert a minimal Beds24Booking row from a raw Beds24 API booking array.
     *
     * Called when the cashier selects a guest who is confirmed in Beds24 but whose
     * booking has not yet been synced to the local DB (e.g. arrived via a channel
     * whose webhook was delayed or missed). The live payload is already in the
     * session from startPayment()/hPayRoom() — no additional API call is made.
     *
     * Uses updateOrCreate so it is idempotent with the normal webhook path.
     *
     * Returns the upserted model on success, null on any failure (caller falls
     * through to the existing "FX unavailable" operator message).
     */
    protected function importBookingFromLiveData(string $bid, array $liveGuest): ?Beds24Booking
    {
        // Guard: minimum viable fields required by downstream consumers.
        //
        // arrival_date is mandatory — PaymentPresentation::fromSync() calls
        // $booking->arrival_date->toDateString(), which throws on null.
        // A row without arrival_date would import successfully then crash in
        // the FX path, which is worse than returning null and showing the
        // standard "FX unavailable" message to the operator.
        if (empty($liveGuest['arrival'])) {
            Log::warning('CashierBot: on-demand import skipped — arrival_date missing from live payload', [
                'beds24_booking_id' => $bid,
                'live_keys'         => array_keys($liveGuest),
            ]);
            return null;
        }

        try {
            $firstName = trim($liveGuest['firstName'] ?? '');
            $lastName  = trim($liveGuest['lastName']  ?? '');
            $guestName = trim("{$firstName} {$lastName}") ?: 'Guest';

            $price   = (float) ($liveGuest['price']   ?? 0);
            $deposit = (float) ($liveGuest['deposit'] ?? 0);
            $balance = max(0.0, $price - $deposit);

            // Map Beds24 status to our known values; treat unknown as 'confirmed'.
            $rawStatus     = (string) ($liveGuest['status'] ?? 'confirmed');
            $bookingStatus = in_array($rawStatus, ['confirmed', 'new'], true) ? $rawStatus : 'confirmed';

            // Identity key: external Beds24 booking ID (not our local PK).
            // updateOrCreate is idempotent with the normal webhook path.
            //
            // Only Beds24-owned fields are listed in the update set.
            // Internal operational fields (notes, admin_confirmed_at, payment_type)
            // are intentionally absent — they are not overwritten on repeated imports.
            $booking = Beds24Booking::updateOrCreate(
                ['beds24_booking_id' => $bid],
                [
                    'property_id'    => (string) ($liveGuest['propertyId'] ?? '41097'),
                    'guest_name'     => $guestName,
                    'arrival_date'   => $liveGuest['arrival'],
                    'departure_date' => $liveGuest['departure'] ?? null,
                    'num_adults'     => max(1, (int) ($liveGuest['numAdult'] ?? 1)),
                    'num_children'   => max(0, (int) ($liveGuest['numChild'] ?? 0)),
                    'total_amount'   => $price,
                    'invoice_balance'=> $balance,
                    'currency'       => 'USD',
                    'booking_status' => $bookingStatus,
                    'room_id'        => $liveGuest['roomId']   ?? null,
                    'room_name'      => $liveGuest['roomName'] ?? null,
                ]
            );

            Log::info('CashierBot: booking imported on-demand from live Beds24 data', [
                'beds24_booking_id' => $bid,
                'property_id'       => $liveGuest['propertyId'] ?? null,
                'guest_name'        => $guestName,
                'arrival_date'      => $liveGuest['arrival'],
                'total_amount'      => $price,
                'invoice_balance'   => $balance,
                'booking_status'    => $bookingStatus,
                'action'            => $booking->wasRecentlyCreated ? 'created' : 'updated',
            ]);

            return $booking;

        } catch (\Throwable $e) {
            Log::error('CashierBot: on-demand booking import failed', [
                'beds24_booking_id' => $bid,
                'error'             => $e->getMessage(),
                'live_keys'         => array_keys($liveGuest),
            ]);
            return null;
        }
    }

    // These three helpers used to be 40 LOC of inline logic. They were
    // extracted to \App\Services\Cashier\BalanceCalculator so per-intent
    // Actions can inject and reuse them. Kept as thin delegators here so
    // the ~20 existing call sites elsewhere in this controller don't need
    // to change until those intents are extracted.
    protected function getShift(?int $uid): ?CashierShift
    {
        return $this->balance->getShift($uid);
    }

    protected function getBal(CashierShift $shift): array
    {
        return $this->balance->getBal($shift);
    }

    protected function fmtBal(array $b): string
    {
        return $this->balance->fmtBal($b);
    }

    /**
     * Format the operator-facing message shown when a booking already
     * has a cashier_bot payment row. Goal: tell operator exactly what
     * is in the system so they don't blindly retry. Falls back to a
     * generic message when the existing tx can't be looked up.
     */
    protected function formatDuplicatePaymentMessage(int $bookingId): string
    {
        if ($bookingId <= 0) {
            return "⚠️ По этому бронированию оплата уже зарегистрирована. Повторное внесение невозможно.";
        }

        $tx = CashTransaction::where('beds24_booking_id', $bookingId)
            ->where('source_trigger', CashTransactionSource::CashierBot->value)
            ->orderBy('id', 'desc')
            ->first();

        if (!$tx) {
            return "⚠️ По этому бронированию оплата уже зарегистрирована. Повторное внесение невозможно.";
        }

        $methodLabel = match ($tx->payment_method) {
            'cash'     => 'наличные',
            'card'     => 'карта',
            'transfer' => 'перевод',
            null, ''   => 'не указан',
            default    => $tx->payment_method,
        };

        $amount   = number_format((float) $tx->amount, 0, '.', ' ');
        $currency = is_object($tx->currency) ? $tx->currency->value : (string) $tx->currency;
        $when     = optional($tx->occurred_at)->format('d.m.Y H:i') ?? '—';

        return "⚠️ По бронированию #{$bookingId} оплата уже зарегистрирована.\n\n"
             . "• Способ: {$methodLabel}\n"
             . "• Сумма: {$amount} {$currency}\n"
             . "• Дата: {$when}\n\n"
             . "Повторное внесение невозможно. Если запись ошибочна — обратитесь к менеджеру.";
    }

    /**
     * Group-payment variant: any sibling of the same group_master_booking_id
     * was already paid via the bot. Operator entered a different sibling.
     */
    protected function formatDuplicateGroupPaymentMessage(int $attemptedBookingId): string
    {
        $existing = $attemptedBookingId > 0
            ? CashTransaction::where('beds24_booking_id', $attemptedBookingId)
                ->orWhere(function ($q) use ($attemptedBookingId) {
                    $q->whereNotNull('group_master_booking_id')
                      ->where(function ($qq) use ($attemptedBookingId) {
                          $qq->where('group_master_booking_id', $attemptedBookingId)
                             ->orWhere('beds24_booking_id', $attemptedBookingId);
                      });
                })
                ->where('source_trigger', CashTransactionSource::CashierBot->value)
                ->where('is_group_payment', true)
                ->orderBy('id', 'desc')
                ->first()
            : null;

        if (!$existing) {
            return "⚠️ По этой групповой брони оплата уже зарегистрирована (другой сегмент группы). Проверьте историю и обратитесь к менеджеру.";
        }

        $methodLabel = match ($existing->payment_method) {
            'cash' => 'наличные', 'card' => 'карта', 'transfer' => 'перевод',
            null, '' => 'не указан', default => $existing->payment_method,
        };
        $amount   = number_format((float) $existing->amount, 0, '.', ' ');
        $currency = is_object($existing->currency) ? $existing->currency->value : (string) $existing->currency;
        $when     = optional($existing->occurred_at)->format('d.m.Y H:i') ?? '—';

        return "⚠️ Это бронирование — часть группы, по которой оплата уже зарегистрирована.\n\n"
             . "• Сегмент с оплатой: #{$existing->beds24_booking_id}\n"
             . "• Способ: {$methodLabel}\n"
             . "• Сумма: {$amount} {$currency}\n"
             . "• Дата: {$when}\n\n"
             . "Повторное внесение невозможно. Проверьте историю или обратитесь к менеджеру.";
    }

    // menuKb was moved into ShowMainMenuAction::buildKeyboard. It was
    // only used by showMainMenu, so the Action owns it now.

    /**
     * Parse amount and currency from flexible user input.
     *
     * Returns [amount, currency|null]. Currency is null when the input
     * contains a non-numeric token that the parser cannot confidently
     * resolve to UZS/USD/EUR/RUB — callers MUST re-prompt instead of
     * silently defaulting to UZS, otherwise foreign-currency amounts
     * get misrecorded as UZS (financial-integrity rule).
     *
     * UZS is returned ONLY for bare numeric input ("50000") with no
     * currency token at all.
     *
     * Accepted forms:
     *   bare:    50000                       → UZS
     *   suffix:  20 USD / 20USD / 20 usd
     *            45 EUR / 45EUR / 45 евро / 45евро
     *            1500 RUB / 1500 руб
     *            20$ / 20 $ / 15€ / 1500₽
     *   prefix:  $20 / €15 / ₽1500 / USD 20 / EUR 45
     *
     * Numbers may use spaces or commas as thousand separators
     * ("1,000,000" → 1000000, "1 000 000" → 1000000) and a dot as
     * decimal point. Comma-as-decimal is also accepted ("20,5" → 20.5)
     * but only when there is a single comma and no dot.
     */
    protected function parseAmountCurrency(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [0.0, null];
        }

        $symbolMap = ['$' => 'USD', '€' => 'EUR', '₽' => 'RUB'];
        $wordMap = [
            'доллар' => 'USD', 'долларов' => 'USD', 'долларa' => 'USD',
            'баксов'  => 'USD', 'бакс'    => 'USD', 'usd'     => 'USD',
            'евро'    => 'EUR', 'eur'     => 'EUR',
            'сум'     => 'UZS', 'сумов'   => 'UZS', 'uzs'     => 'UZS',
            'руб'     => 'RUB', 'рублей'  => 'RUB', 'рубль'   => 'RUB', 'rub' => 'RUB',
        ];
        $codes = ['UZS', 'USD', 'EUR', 'RUB'];

        // 1) Symbol prefix: $20, €15, ₽1500
        foreach ($symbolMap as $sym => $cur) {
            if (str_starts_with($text, $sym)) {
                return [$this->parseAmountToken(substr($text, strlen($sym))), $cur];
            }
        }

        // 2) Symbol suffix: 20$, 15€, 1500₽, "20 $"
        foreach ($symbolMap as $sym => $cur) {
            if (str_ends_with(rtrim($text), $sym)) {
                $stripped = rtrim(rtrim($text), $sym);
                return [$this->parseAmountToken($stripped), $cur];
            }
        }

        // 3) Currency-code prefix: "USD 20", "EUR 45"
        foreach ($codes as $code) {
            if (preg_match('/^' . $code . '\s+(.+)$/i', $text, $m)) {
                return [$this->parseAmountToken($m[1]), $code];
            }
        }

        // 4) Currency-code suffix WITHOUT space: "20EUR", "45USD"
        if (preg_match('/^(.+?)(UZS|USD|EUR|RUB)$/i', $text, $m)) {
            return [$this->parseAmountToken($m[1]), strtoupper($m[2])];
        }

        // 5) Russian-word suffix WITHOUT space: "45евро", "1500руб"
        foreach ($wordMap as $word => $cur) {
            if (preg_match('/^(.+?)' . preg_quote($word, '/') . '\b/iu', $text, $m)) {
                $token = trim($m[1]);
                if ($token !== '' && preg_match('/[0-9]/', $token)) {
                    return [$this->parseAmountToken($token), $cur];
                }
            }
        }

        // 6) Space-separated: "20 USD", "45 евро", "1500 руб", "50000 сум"
        $parts = preg_split('/\s+/', $text);
        $first = $parts[0] ?? '';
        $amt = $this->parseAmountToken($first);
        $curText = strtolower(trim($parts[1] ?? ''));

        if (count($parts) === 1 && $curText === '') {
            // Bare numeric input: default to UZS only when there is no
            // currency token at all (financial-integrity rule). If the
            // single token contains trailing non-numeric junk that none
            // of the recognised forms above resolved (e.g. "20EU",
            // "20XYZ"), return null currency to force a re-prompt.
            $cleanNumeric = preg_replace('/[\s,.]/', '', $first);
            if (preg_match('/[^0-9]/', (string) $cleanNumeric)) {
                return [$amt, null];
            }
            return [$amt, 'UZS'];
        }

        $curUpper = strtoupper($curText);
        if (in_array($curUpper, $codes, true)) {
            return [$amt, $curUpper];
        }
        if (isset($symbolMap[$curText])) {
            return [$amt, $symbolMap[$curText]];
        }
        foreach ($wordMap as $word => $cur) {
            if (str_starts_with($curText, $word)) {
                return [$amt, $cur];
            }
        }

        // Token present but unrecognised → null currency forces re-prompt.
        // DO NOT silently fall back to UZS.
        return [$amt, null];
    }

    /**
     * Parse a numeric token tolerating thousand separators (space or
     * comma) and decimal point. "1,000,000" → 1000000.0, "20,5" → 20.5,
     * "20.5" → 20.5, "20" → 20.0.
     */
    private function parseAmountToken(string $token): float
    {
        $token = trim($token);
        if ($token === '') {
            return 0.0;
        }

        // Strip thousand-separator spaces.
        $token = str_replace(' ', '', $token);

        $hasDot   = str_contains($token, '.');
        $hasComma = str_contains($token, ',');

        if ($hasDot && $hasComma) {
            // Mixed → assume comma is thousand separator, dot is decimal.
            $token = str_replace(',', '', $token);
        } elseif ($hasComma && substr_count($token, ',') === 1
                  && preg_match('/^\d+,\d{1,2}$/', $token)) {
            // Single comma with 1-2 digits after → comma-as-decimal ("20,5").
            $token = str_replace(',', '.', $token);
        } else {
            // Comma is thousand separator: "1,000,000" → "1000000".
            $token = str_replace(',', '', $token);
        }

        return floatval($token);
    }

    protected function phoneKb(): array
    {
        return ['keyboard' => [[['text' => 'Отправить номер', 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
    }

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function send(int $chatId, string $text, ?array $kb = null, string $type = 'reply')
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

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function aCb(string $id)
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

    // ── Action reply dispatch ───────────────────────────────────
    //
    // Read-only intents (showGuide, showBalance, showMyTransactions) have
    // been extracted to App\Actions\CashierBot\Handlers\*. Each Action
    // returns a reply array { text, kb?, type? } that this dispatcher
    // hands off to $this->send(). Telegram envelope I/O stays on the
    // controller per the extraction plan's Option-A seam.

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function dispatchReply(int $chatId, array $reply)
    {
        $this->send($chatId, $reply['text'], $reply['kb'] ?? null, $reply['type'] ?? 'reply');

        return response('OK');
    }

    /** @internal Used only by CashierBotCallbackRouter during A2/A3 extraction. */
    public function dispatchGuide(int $chatId, ?string $topic)
    {
        return $this->dispatchReply($chatId, app(\App\Actions\CashierBot\Handlers\ShowGuideAction::class)->execute($topic));
    }

}
