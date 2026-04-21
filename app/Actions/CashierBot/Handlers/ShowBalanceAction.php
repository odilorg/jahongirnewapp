<?php

declare(strict_types=1);

namespace App\Actions\CashierBot\Handlers;

use App\Models\CashExpense;
use App\Models\CashTransaction;
use App\Services\Cashier\BalanceCalculator;

/**
 * Handles the "balance" callback from @j_cashier_bot.
 *
 * Pure extraction from CashierBotController::showBalance. Read-only —
 * no state mutation, no money side effects. Behaviour must be
 * byte-identical.
 *
 * Reply shape: ['text' => string, 'kb' => array|null, 'type' => 'inline'].
 * Router passes that to $this->send().
 */
final class ShowBalanceAction
{
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

        $bal = $this->balance->getBal($shift);
        $txn = CashTransaction::where('cashier_shift_id', $shift->id)->count();
        $exp = CashExpense::where('cashier_shift_id', $shift->id)->count();

        $text = "Баланс за смену\n\n" . $this->balance->fmtBal($bal)
            . "\n\nОпераций: {$txn} | Расходов: {$exp}\nОткрыта: "
            . $shift->opened_at->timezone('Asia/Tashkent')->format('H:i');

        return [
            'text' => $text,
            'kb'   => ['inline_keyboard' => [[['text' => 'Назад', 'callback_data' => 'menu']]]],
            'type' => 'inline',
        ];
    }
}
