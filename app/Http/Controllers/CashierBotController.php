<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\CashExpense;
use App\Models\ShiftHandover;
use App\Models\Beds24Booking;
use App\Models\TelegramPosSession;
use App\Models\ExpenseCategory;
use App\Services\OwnerAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class CashierBotController extends Controller
{
    protected string $botToken;
    protected OwnerAlertService $ownerAlert;

    public function __construct(OwnerAlertService $ownerAlert)
    {
        $this->botToken = config('services.cashier_bot.token', config('services.owner_alert_bot.token'));
        $this->ownerAlert = $ownerAlert;
    }

    public function handleWebhook(Request $request)
    {
        Log::info('CashierBot webhook', ['data' => $request->all()]);
        if ($callback = $request->input('callback_query')) return $this->handleCallback($callback);
        if ($message = $request->input('message')) return $this->handleMessage($message);
        return response('OK');
    }

    protected function handleMessage(array $message)
    {
        $chatId = $message['chat']['id'] ?? null;
        $text = trim($message['text'] ?? '');
        $photo = $message['photo'] ?? null;
        $contact = $message['contact'] ?? null;
        if (!$chatId) return response('OK');
        if ($contact) return $this->handleAuth($chatId, $contact);

        $session = TelegramPosSession::where('chat_id', $chatId)->first();
        if (!$session || !$session->user_id) {
            $this->send($chatId, "Отправьте номер телефона для авторизации.", $this->phoneKb());
            return response('OK');
        }
        if ($photo && $session->state === 'shift_close_photo') return $this->handleShiftPhoto($session, $chatId, $photo);
        if ($text === '/start' || $text === '/menu') return $this->showMainMenu($chatId, $session);
        return $this->handleState($session, $chatId, $text);
    }

    protected function handleAuth(int $chatId, array $contact)
    {
        $phone = preg_replace('/[^0-9]/', '', $contact['phone_number'] ?? '');
        $user = User::where('phone_number', 'LIKE', '%' . substr($phone, -9))->first();
        if (!$user) { $this->send($chatId, "Номер не найден. Обратитесь к руководству."); return response('OK'); }
        TelegramPosSession::updateOrCreate(['chat_id' => $chatId], ['user_id' => $user->id, 'state' => 'main_menu', 'data' => null]);
        $user->update(['telegram_user_id' => $chatId]);
        $this->send($chatId, "Добро пожаловать, {$user->name}!");
        return $this->showMainMenu($chatId, TelegramPosSession::where('chat_id', $chatId)->first());
    }

    protected function showMainMenu(int $chatId, $session)
    {
        $session->update(['state' => 'main_menu', 'data' => null]);
        $shift = $this->getShift($session->user_id);
        $st = $shift ? "Смена открыта" : "Смена закрыта";
        $bal = $shift ? "\nБаланс: " . $this->fmtBal($this->getBal($shift)) : '';
        $this->send($chatId, "Кассир-бот | {$st}{$bal}");
        $this->send($chatId, "Выберите действие:", $this->menuKb($shift), 'inline');
        return response('OK');
    }

    protected function handleCallback(array $cb)
    {
        $chatId = $cb['message']['chat']['id'] ?? null;
        $data = $cb['data'] ?? '';
        if (!$chatId) return response('OK');
        $this->aCb($cb['id'] ?? '');

        // Handle owner approval callbacks (owner may not have a session)
        if (preg_match('/^(approve|reject)_expense_(\d+)$/', $data, $matches)) {
            return app(\App\Http\Controllers\OwnerBotController::class)
                ->handleExpenseAction($chatId, $cb['message']['message_id'] ?? null, $cb['id'] ?? '', $matches[1], (int)$matches[2]);
        }

        $s = TelegramPosSession::where('chat_id', $chatId)->first();
        if (!$s) return response('OK');

        return match(true) {
            $data === 'open_shift' => $this->openShift($s, $chatId),
            $data === 'payment' => $this->startPayment($s, $chatId),
            $data === 'expense' => $this->startExpense($s, $chatId),
            $data === 'balance' => $this->showBalance($s, $chatId),
            $data === 'close_shift' => $this->startClose($s, $chatId),
            $data === 'menu' => $this->showMainMenu($chatId, $s),
            str_starts_with($data, 'guest_') => $this->selectGuest($s, $chatId, $data),
            str_starts_with($data, 'cur_') => $this->selectCur($s, $chatId, $data),
            str_starts_with($data, 'method_') => $this->selectMethod($s, $chatId, $data),
            str_starts_with($data, 'expcat_') => $this->selectExpCat($s, $chatId, $data),
            $data === 'confirm_payment' => $this->confirmPayment($s, $chatId),
            $data === 'confirm_expense' => $this->confirmExpense($s, $chatId),
            $data === 'confirm_close' => $this->confirmClose($s, $chatId),
            $data === 'cancel' => $this->showMainMenu($chatId, $s),
            default => response('OK'),
        };
    }

    protected function handleState($s, int $chatId, string $text)
    {
        return match($s->state) {
            'payment_room' => $this->hPayRoom($s, $chatId, $text),
            'payment_amount' => $this->hPayAmt($s, $chatId, $text),
            'expense_amount' => $this->hExpAmt($s, $chatId, $text),
            'expense_desc' => $this->hExpDesc($s, $chatId, $text),
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
        CashierShift::create(['cash_drawer_id' => $drawer->id, 'user_id' => $s->user_id, 'status' => 'open', 'opened_at' => now()]);
        $this->send($chatId, "Смена открыта! Касса: {$drawer->name}");
        return $this->showMainMenu($chatId, $s);
    }

    // ── PAYMENT ─────────────────────────────────────────────────

    protected function startPayment($s, int $chatId)
    {
        $shift = $this->getShift($s->user_id);
        if (!$shift) { $this->send($chatId, "Сначала откройте смену."); return response('OK'); }
        $s->update(['state' => 'payment_room', 'data' => ['shift_id' => $shift->id]]);
        $this->send($chatId, "Введите номер комнаты:");
        return response('OK');
    }

    protected function hPayRoom($s, int $chatId, string $text)
    {
        $d = $s->data ?? [];
        $d['room'] = $text;
        $today = Carbon::today()->format('Y-m-d');
        $guests = Beds24Booking::where('room_name', 'LIKE', "%{$text}%")
            ->where('arrival_date', '<=', $today)->where('departure_date', '>=', $today)
            ->where('booking_status', 'confirmed')->get();

        if ($guests->isEmpty()) {
            $d['guest_name'] = 'Ручной ввод';
            $d['booking_id'] = null;
            $s->update(['state' => 'payment_amount', 'data' => $d]);
            $this->send($chatId, "Комната {$text} — гость не найден в Beds24.\nВведите сумму:");
            return response('OK');
        }
        $s->update(['state' => 'payment_guest_select', 'data' => $d]);
        $btns = $guests->map(fn($g) => [['text' => "{$g->guest_name} ({$g->channel})", 'callback_data' => "guest_{$g->beds24_booking_id}"]])->toArray();
        $btns[] = [['text' => 'Другой гость', 'callback_data' => 'guest_manual']];
        $this->send($chatId, "Комната {$text}. Выберите гостя:", ['inline_keyboard' => $btns], 'inline');
        return response('OK');
    }

    protected function selectGuest($s, int $chatId, string $data)
    {
        $d = $s->data ?? [];
        $bid = str_replace('guest_', '', $data);
        if ($bid === 'manual') { $d['guest_name'] = 'Ручной ввод'; $d['booking_id'] = null; }
        else {
            $b = Beds24Booking::where('beds24_booking_id', $bid)->first();
            $d['guest_name'] = $b ? $b->guest_name : '?';
            $d['booking_id'] = $bid;
        }
        $s->update(['state' => 'payment_amount', 'data' => $d]);
        $this->send($chatId, "Гость: {$d['guest_name']}\nВведите сумму:");
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
        $s->update(['state' => 'payment_method', 'data' => $d]);
        $this->send($chatId, "Способ оплаты:", ['inline_keyboard' => [[
            ['text' => 'Наличные', 'callback_data' => 'method_cash'],
            ['text' => 'Карта', 'callback_data' => 'method_card'],
            ['text' => 'Перевод', 'callback_data' => 'method_transfer'],
        ]]], 'inline');
        return response('OK');
    }

    protected function selectMethod($s, int $chatId, string $data)
    {
        $d = $s->data ?? [];
        $d['method'] = str_replace('method_', '', $data);
        $s->update(['state' => 'payment_confirm', 'data' => $d]);
        $ml = match($d['method']) { 'cash' => 'Наличные', 'card' => 'Карта', 'transfer' => 'Перевод', default => $d['method'] };
        $t = "Подтвердите:\n\nКомната: {$d['room']}\nГость: {$d['guest_name']}\nСумма: " . number_format($d['amount'], 0) . " {$d['currency']}\nСпособ: {$ml}";
        if (!empty($d['booking_id'])) $t .= "\nBeds24: #{$d['booking_id']}";
        $this->send($chatId, $t, ['inline_keyboard' => [[
            ['text' => 'Подтвердить', 'callback_data' => 'confirm_payment'],
            ['text' => 'Отмена', 'callback_data' => 'cancel'],
        ]]], 'inline');
        return response('OK');
    }

    protected function confirmPayment($s, int $chatId)
    {
        $d = $s->data ?? [];
        $shift = CashierShift::find($d['shift_id'] ?? 0);
        if (!$shift) { $this->send($chatId, "Смена не найдена."); return $this->showMainMenu($chatId, $s); }
        try {
            CashTransaction::create([
                'cashier_shift_id' => $shift->id, 'type' => 'in', 'amount' => $d['amount'],
                'currency' => $d['currency'], 'category' => 'sale',
                'beds24_booking_id' => $d['booking_id'] ?? null, 'payment_method' => $d['method'],
                'guest_name' => $d['guest_name'], 'room_number' => $d['room'],
                'reference' => $d['booking_id'] ? "Beds24 #{$d['booking_id']}" : "Комната {$d['room']}",
                'notes' => "Оплата: {$d['guest_name']}", 'created_by' => $s->user_id, 'occurred_at' => now(),
            ]);
            $this->send($chatId, "Оплата записана!\nБаланс: " . $this->fmtBal($this->getBal($shift)));
        } catch (\Exception $e) {
            Log::error('Payment failed', ['e' => $e->getMessage()]);
            $this->send($chatId, "Ошибка: " . $e->getMessage());
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
        $parts = preg_split('/\s+/', $text);
        $amt = floatval(str_replace(',', '.', $parts[0] ?? '0'));
        $cur = strtoupper($parts[1] ?? 'UZS');
        if ($amt <= 0) { $this->send($chatId, "Неверная сумма."); return response('OK'); }
        $d['amount'] = $amt;
        $d['currency'] = in_array($cur, ['UZS', 'USD', 'EUR']) ? $cur : 'UZS';
        $s->update(['state' => 'expense_desc', 'data' => $d]);
        $this->send($chatId, "Сумма: " . number_format($amt, 0) . " {$d['currency']}\nОписание расхода:");
        return response('OK');
    }

    protected function hExpDesc($s, int $chatId, string $text)
    {
        $d = $s->data ?? [];
        $d['desc'] = $text;
        $thr = config('services.cashier_bot.expense_approval_threshold_uzs', 500000);
        $d['needs_approval'] = ($d['currency'] === 'UZS' && $d['amount'] > $thr);
        $s->update(['state' => 'expense_confirm', 'data' => $d]);
        $t = "Подтвердите расход:\n\nКатегория: {$d['cat_name']}\nСумма: " . number_format($d['amount'], 0) . " {$d['currency']}\nОписание: {$d['desc']}";
        if ($d['needs_approval']) $t .= "\n\nТребуется одобрение владельца.";
        $this->send($chatId, $t, ['inline_keyboard' => [[
            ['text' => 'Подтвердить', 'callback_data' => 'confirm_expense'],
            ['text' => 'Отмена', 'callback_data' => 'cancel'],
        ]]], 'inline');
        return response('OK');
    }

    protected function confirmExpense($s, int $chatId)
    {
        $d = $s->data ?? [];
        $shift = CashierShift::find($d['shift_id'] ?? 0);
        if (!$shift) { $this->send($chatId, "Смена не найдена."); return $this->showMainMenu($chatId, $s); }
        try {
            CashExpense::create([
                'cashier_shift_id' => $shift->id, 'expense_category_id' => $d['cat_id'],
                'amount' => $d['amount'], 'currency' => $d['currency'], 'description' => $d['desc'],
                'requires_approval' => $d['needs_approval'] ?? false,
                'created_by' => $s->user_id, 'occurred_at' => now(),
            ]);
            CashTransaction::create([
                'cashier_shift_id' => $shift->id, 'type' => 'out', 'amount' => $d['amount'],
                'currency' => $d['currency'], 'category' => 'expense',
                'reference' => "Расход: {$d['cat_name']}", 'notes' => $d['desc'],
                'created_by' => $s->user_id, 'occurred_at' => now(),
            ]);
            if ($d['needs_approval'] ?? false) {
                $expense = CashExpense::where('cashier_shift_id', $shift->id)
                    ->where('amount', $d['amount'])
                    ->where('description', $d['desc'])
                    ->latest()->first();
                if ($expense) {
                    app(\App\Http\Controllers\OwnerBotController::class)->sendApprovalRequest($expense);
                }
            }
            $this->send($chatId, "Расход записан!\nБаланс: " . $this->fmtBal($this->getBal($shift)));
        } catch (\Exception $e) {
            Log::error('Expense failed', ['e' => $e->getMessage()]);
            $this->send($chatId, "Ошибка: " . $e->getMessage());
        }
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
                $diff = $cnt - $e;
                $ds = $diff == 0 ? '' : ($diff > 0 ? " (+{$diff})" : " ({$diff})");
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
        $exp = $d['expected'] ?? [];
        $lines = [];
        foreach (['uzs', 'usd', 'eur'] as $c) {
            $e = $exp[strtoupper($c)] ?? 0;
            $cnt = $d['counted_' . $c] ?? 0;
            $diff = $cnt - $e;
            $ds = $diff == 0 ? '' : ($diff > 0 ? " (+{$diff})" : " ({$diff})");
            $lines[] = strtoupper($c) . ": ожид. " . number_format($e, 0) . " / факт " . number_format($cnt, 0) . $ds;
        }
        $this->send($chatId, "Итог:\n\n" . implode("\n", $lines), ['inline_keyboard' => [[
            ['text' => 'Закрыть смену', 'callback_data' => 'confirm_close'],
            ['text' => 'Отмена', 'callback_data' => 'cancel'],
        ]]], 'inline');
        return response('OK');
    }

    protected function confirmClose($s, int $chatId)
    {
        $d = $s->data ?? [];
        $shift = CashierShift::find($d['shift_id'] ?? 0);
        if (!$shift) { $this->send($chatId, "Смена не найдена."); return $this->showMainMenu($chatId, $s); }
        try {
            $ho = ShiftHandover::create([
                'outgoing_shift_id' => $shift->id,
                'counted_uzs' => $d['counted_uzs'] ?? 0, 'counted_usd' => $d['counted_usd'] ?? 0, 'counted_eur' => $d['counted_eur'] ?? 0,
                'expected_uzs' => $d['expected']['UZS'] ?? 0, 'expected_usd' => $d['expected']['USD'] ?? 0, 'expected_eur' => $d['expected']['EUR'] ?? 0,
                'cash_photo_path' => $d['photo_id'] ?? null,
            ]);
            $shift->update(['status' => 'closed', 'closed_at' => now()]);

            $user = User::find($s->user_id);
            $txn = CashTransaction::where('cashier_shift_id', $shift->id)->count();
            $msg = "Смена закрыта\n\nСотрудник: " . ($user->name ?? '?')
                . "\nВремя: " . $shift->opened_at->format('H:i') . '-' . now()->timezone('Asia/Tashkent')->format('H:i')
                . "\nОпераций: {$txn}\n";
            foreach (['UZS', 'USD', 'EUR'] as $c) {
                $e = $d['expected'][$c] ?? 0;
                $cnt = $d['counted_' . strtolower($c)] ?? 0;
                if ($e > 0 || $cnt > 0) {
                    $diff = $cnt - $e;
                    $msg .= "\n{$c}: " . number_format($e, 0) . " -> " . number_format($cnt, 0);
                    if ($diff != 0) $msg .= " (" . ($diff > 0 ? '+' : '') . number_format($diff, 0) . ")";
                }
            }
            // Build owner notification
            $discrepancies = [];
            foreach (['UZS', 'USD', 'EUR'] as $cur) {
                $exp = $d['expected'][$cur] ?? 0;
                $cnt = $d['counted_' . strtolower($cur)] ?? 0;
                $diff = $cnt - $exp;
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
                }
            }

            // Severity: 🟢 <1%, 🟡 1-5%, 🔴 >5%
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

            $this->ownerAlert->sendShiftCloseReport($ownerMsg);

            if (!empty($d['photo_id'])) {
                $oid = config('services.owner_alert_bot.owner_chat_id', env('OWNER_TELEGRAM_ID', '38738713'));
                Http::post("https://api.telegram.org/bot{$this->botToken}/sendPhoto", [
                    'chat_id' => $oid, 'photo' => $d['photo_id'], 'caption' => '📸 Фото кассы при закрытии смены',
                ]);
            }
            $this->send($chatId, "Смена закрыта!");
        } catch (\Exception $e) {
            Log::error('Close shift failed', ['e' => $e->getMessage()]);
            $this->send($chatId, "Ошибка: " . $e->getMessage());
        }
        return $this->showMainMenu($chatId, $s);
    }

    // ── HELPERS ──────────────────────────────────────────────────

    protected function getShift(int $uid): ?CashierShift
    {
        return CashierShift::where('user_id', $uid)->where('status', 'open')->latest('opened_at')->first();
    }

    protected function getBal(CashierShift $shift): array
    {
        $b = ['UZS' => 0, 'USD' => 0, 'EUR' => 0];
        foreach (CashTransaction::where('cashier_shift_id', $shift->id)->get() as $tx) {
            $c = is_string($tx->currency) ? $tx->currency : ($tx->currency->value ?? 'UZS');
            if (!isset($b[$c])) $b[$c] = 0;
            $b[$c] += ($tx->type->value === 'in' || $tx->type === 'in' ? $tx->amount : -$tx->amount);
        }
        return $b;
    }

    protected function fmtBal(array $b): string
    {
        $p = [];
        foreach ($b as $c => $a) { if ($a != 0) $p[] = number_format($a, 0) . " {$c}"; }
        return $p ? implode(' | ', $p) : '0';
    }

    protected function menuKb(?CashierShift $shift): array
    {
        if (!$shift) return ['inline_keyboard' => [[['text' => 'Открыть смену', 'callback_data' => 'open_shift']]]];
        return ['inline_keyboard' => [
            [['text' => 'Расход', 'callback_data' => 'expense'], ['text' => 'Баланс', 'callback_data' => 'balance']],
            [['text' => 'Закрыть смену', 'callback_data' => 'close_shift']],
        ]];
    }

    protected function phoneKb(): array
    {
        return ['keyboard' => [[['text' => 'Отправить номер', 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true];
    }

    protected function send(int $chatId, string $text, ?array $kb = null, string $type = 'reply')
    {
        $p = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($kb) $p['reply_markup'] = json_encode($kb);
        Http::post("https://api.telegram.org/bot{$this->botToken}/sendMessage", $p);
    }

    protected function aCb(string $id)
    {
        Http::post("https://api.telegram.org/bot{$this->botToken}/answerCallbackQuery", ['callback_query_id' => $id]);
    }
}
