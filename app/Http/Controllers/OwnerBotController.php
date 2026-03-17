<?php

namespace App\Http\Controllers;

use App\Models\CashExpense;
use App\Models\CashTransaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OwnerBotController extends Controller
{
    protected string $botToken;
    protected string $apiBase;

    public function __construct()
    {
        $this->botToken = config('services.owner_alert_bot.token', env('OWNER_ALERT_BOT_TOKEN'));
        $this->apiBase = "https://api.telegram.org/bot{$this->botToken}";
    }

    public function handleWebhook(Request $request)
    {
        $update = $request->all();

        // Only handle callback queries (button presses)
        if (isset($update['callback_query'])) {
            return $this->handleCallback($update['callback_query']);
        }

        return response('OK');
    }

    protected function handleCallback(array $callback)
    {
        $chatId = $callback['message']['chat']['id'] ?? null;
        $messageId = $callback['message']['message_id'] ?? null;
        $data = $callback['data'] ?? '';
        $callbackId = $callback['id'] ?? '';

        if (!$chatId || !$data) {
            return response('OK');
        }

        // Parse callback data: approve_expense_123 or reject_expense_123
        if (preg_match('/^(approve|reject)_expense_(\d+)$/', $data, $matches)) {
            $action = $matches[1];
            $expenseId = (int) $matches[2];
            return $this->handleExpenseAction($chatId, $messageId, $callbackId, $action, $expenseId);
        }

        $this->answerCallback($callbackId, 'Неизвестная команда');
        return response('OK');
    }

    public function handleExpenseAction(int $chatId, ?int $messageId, string $callbackId, string $action, int $expenseId)
    {
        $expense = CashExpense::with(['creator', 'category'])->find($expenseId);

        if (!$expense) {
            $this->answerCallback($callbackId, 'Расход не найден');
            return response('OK');
        }

        // Already processed?
        if ($expense->approved_at || $expense->rejected_at) {
            $status = $expense->approved_at ? 'уже одобрен' : 'уже отклонён';
            $this->answerCallback($callbackId, "Расход {$status}");
            return response('OK');
        }

        $ownerUser = User::where('telegram_user_id', $chatId)->first();

        // Only owner/admin/super_admin can approve or reject expenses
        if (!$ownerUser || !$ownerUser->hasAnyRole(['super_admin', 'admin', 'manager'])) {
            $this->answerCallback($callbackId, '⛔ Доступ запрещён');
            return response('OK');
        }

        $ownerId = $ownerUser->id;

        if ($action === 'approve') {
            $expense->update([
                'approved_by' => $ownerId,
                'approved_at' => now(),
            ]);

            $this->answerCallback($callbackId, '✅ Расход одобрен');

            // Update the original message
            $text = $this->buildExpenseMessage($expense) . "\n\n✅ <b>ОДОБРЕНО</b> — " . now('Asia/Tashkent')->format('d.m H:i');
            $this->editMessage($chatId, $messageId, $text);

            // Notify cashier
            $this->notifyCashier($expense, 'approved');

        } else {
            $expense->update([
                'rejected_by' => $ownerId,
                'rejected_at' => now(),
            ]);

            // Reverse the transaction
            $this->reverseExpenseTransaction($expense);

            $this->answerCallback($callbackId, '❌ Расход отклонён');

            $text = $this->buildExpenseMessage($expense) . "\n\n❌ <b>ОТКЛОНЕНО</b> — " . now('Asia/Tashkent')->format('d.m H:i');
            $this->editMessage($chatId, $messageId, $text);

            // Notify cashier
            $this->notifyCashier($expense, 'rejected');
        }

        return response('OK');
    }

    /**
     * Reverse the CashTransaction when expense is rejected
     */
    protected function reverseExpenseTransaction(CashExpense $expense): void
    {
        try {
            // Find the matching transaction and delete it or create a reversal
            $tx = CashTransaction::where('cashier_shift_id', $expense->cashier_shift_id)
                ->where('type', 'out')
                ->where('amount', $expense->amount)
                ->where('currency', $expense->currency)
                ->where('notes', $expense->description)
                ->latest()
                ->first();

            if ($tx) {
                // Create a reversal transaction (money back in)
                CashTransaction::create([
                    'cashier_shift_id' => $expense->cashier_shift_id,
                    'type' => 'in',
                    'amount' => $expense->amount,
                    'currency' => $expense->currency,
                    'category' => 'other',
                    'reference' => "Возврат: отклонённый расход #{$expense->id}",
                    'notes' => "Отклонено: {$expense->description}",
                    'occurred_at' => now(),
                ]);

                Log::info('Expense reversed', ['expense_id' => $expense->id, 'tx_id' => $tx->id]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to reverse expense transaction', [
                'expense_id' => $expense->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify cashier about approval decision
     */
    protected function notifyCashier(CashExpense $expense, string $decision): void
    {
        try {
            $cashierUser = $expense->creator;
            if (!$cashierUser || !$cashierUser->telegram_user_id) return;

            $emoji = $decision === 'approved' ? '✅' : '❌';
            $label = $decision === 'approved' ? 'одобрен' : 'отклонён';

            $text = "{$emoji} Ваш расход {$label}!\n\n"
                . "Категория: {$expense->category?->name}\n"
                . "Сумма: " . number_format($expense->amount, 0) . " {$expense->currency}\n"
                . "Описание: {$expense->description}";

            if ($decision === 'rejected') {
                $text .= "\n\n⚠️ Сумма возвращена в баланс смены.";
            }

            // Send via cashier bot (since that's where cashier interacts)
            $cashierBotToken = config('services.cashier_bot.token', env('TELEGRAM_CASHIER_BOT_TOKEN'));
            Http::timeout(10)->post("https://api.telegram.org/bot{$cashierBotToken}/sendMessage", [
                'chat_id' => $cashierUser->telegram_user_id,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to notify cashier', ['error' => $e->getMessage()]);
        }
    }

    protected function buildExpenseMessage(CashExpense $expense): string
    {
        return implode("\n", [
            "💸 <b>Расход на одобрение</b>",
            "",
            "👤 Сотрудник: " . ($expense->creator?->name ?? '?'),
            "📁 Категория: " . ($expense->category?->name ?? $expense->expense_category_id),
            "💰 Сумма: " . number_format($expense->amount, 0) . " {$expense->currency}",
            "📝 Описание: {$expense->description}",
            "",
            "⏰ " . ($expense->occurred_at?->timezone('Asia/Tashkent')->format('d.m.Y H:i') ?? now('Asia/Tashkent')->format('d.m.Y H:i')),
        ]);
    }

    /**
     * Send expense approval request to owner with buttons
     */
    public function sendApprovalRequest(CashExpense $expense): void
    {
        $expense->load(['creator', 'category']);

        $text = $this->buildExpenseMessage($expense);

        $keyboard = [
            'inline_keyboard' => [[
                ['text' => '✅ Одобрить', 'callback_data' => "approve_expense_{$expense->id}"],
                ['text' => '❌ Отклонить', 'callback_data' => "reject_expense_{$expense->id}"],
            ]],
        ];

        $ownerChatId = config('services.owner_alert_bot.owner_chat_id', env('OWNER_TELEGRAM_ID', '38738713'));

        try {
            Http::timeout(10)->post("{$this->apiBase}/sendMessage", [
                'chat_id' => $ownerChatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($keyboard),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send approval request', ['expense_id' => $expense->id, 'error' => $e->getMessage()]);
        }
    }

    // ── Telegram helpers ─────────────────────────────────────────

    protected function answerCallback(string $callbackId, string $text): void
    {
        try {
            Http::timeout(5)->post("{$this->apiBase}/answerCallbackQuery", [
                'callback_query_id' => $callbackId,
                'text' => $text,
                'show_alert' => true,
            ]);
        } catch (\Throwable $e) {
            Log::error('answerCallback failed', ['error' => $e->getMessage()]);
        }
    }

    protected function editMessage(int $chatId, ?int $messageId, string $text): void
    {
        if (!$messageId) return;
        try {
            Http::timeout(10)->post("{$this->apiBase}/editMessageText", [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable $e) {
            Log::error('editMessage failed', ['error' => $e->getMessage()]);
        }
    }
}
