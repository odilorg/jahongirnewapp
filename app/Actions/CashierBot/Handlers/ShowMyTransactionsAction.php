<?php

declare(strict_types=1);

namespace App\Actions\CashierBot\Handlers;

use App\Models\CashTransaction;
use App\Services\Cashier\BalanceCalculator;

/**
 * Handles the "my_txns" callback from @j_cashier_bot.
 *
 * Pure extraction from CashierBotController::showMyTransactions. Read-only,
 * capped at the 20 most recent transactions. Behaviour must be byte-identical.
 *
 * The enum-cast unwrap dance (is_string || ->value ?? default) is kept
 * verbatim because this code is the rendering layer — forcing enums through
 * the model cast boundary is upstream work that doesn't belong in this
 * extraction.
 */
final class ShowMyTransactionsAction
{
    private const TIMEZONE = 'Asia/Tashkent';
    private const LIMIT    = 20;

    public function __construct(
        private readonly BalanceCalculator $balance,
    ) {}

    /**
     * @return array{text: string, kb?: array, type?: string}
     */
    public function execute(?int $userId): array
    {
        $shift = $this->balance->getShift($userId);
        if (! $shift) {
            return ['text' => 'Нет открытой смены.'];
        }

        $txns = CashTransaction::where('cashier_shift_id', $shift->id)
            ->drawerTruth()
            ->orderByDesc('occurred_at')
            ->limit(self::LIMIT)
            ->get();

        if ($txns->isEmpty()) {
            return [
                'text' => 'За эту смену операций ещё нет.',
                'kb'   => ['inline_keyboard' => [[['text' => '« Меню', 'callback_data' => 'menu']]]],
                'type' => 'inline',
            ];
        }

        $lines = ["📋 *Операции смены* (последние {$txns->count()}):\n"];
        foreach ($txns as $tx) {
            $typeVal = is_string($tx->type) ? $tx->type : ($tx->type->value ?? 'out');
            $catVal  = is_string($tx->category) ? $tx->category : ($tx->category?->value ?? '');
            $cur     = is_string($tx->currency) ? $tx->currency : ($tx->currency?->value ?? '');
            $amt     = number_format((float) $tx->amount, 0, '.', ' ');
            $time    = $tx->occurred_at?->timezone(self::TIMEZONE)->format('H:i') ?? '?';

            $icon = match (true) {
                $catVal === 'payment'  => '💵',
                $catVal === 'expense'  => '📤',
                $catVal === 'exchange' => '🔄',
                $catVal === 'cash_in'  => '➕',
                $typeVal === 'in'      => '⬆️',
                default                => '⬇️',
            };

            $sign = $typeVal === 'in' ? '+' : '−';

            // For exchanges we show the paired leg inline so staff can see
            // both sides of the trade without scrolling.
            if ($catVal === 'exchange' && $tx->related_amount && $tx->related_currency) {
                $relCur = is_string($tx->related_currency) ? $tx->related_currency : ($tx->related_currency?->value ?? '');
                $relAmt = number_format((float) $tx->related_amount, 0, '.', ' ');
                $lines[] = "{$time}  {$icon} {$sign}{$amt} {$cur} / {$relAmt} {$relCur}";
            } else {
                $lines[] = "{$time}  {$icon} {$sign}{$amt} {$cur}" . ($tx->notes ? "  _{$tx->notes}_" : '');
            }
        }

        return [
            'text' => implode("\n", $lines),
            'kb'   => ['inline_keyboard' => [[['text' => '« Меню', 'callback_data' => 'menu']]]],
            'type' => 'inline',
        ];
    }
}
