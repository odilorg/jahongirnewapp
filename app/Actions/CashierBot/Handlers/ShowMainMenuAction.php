<?php

declare(strict_types=1);

namespace App\Actions\CashierBot\Handlers;

use App\Models\CashierShift;
use App\Models\TelegramPosSession;
use App\Models\User;
use App\Services\Cashier\BalanceCalculator;

/**
 * Shows the main cashier menu — status line + inline keyboard — and
 * resets the session to 'main_menu' state.
 *
 * Pure extraction from CashierBotController::showMainMenu plus the
 * menuKb() keyboard builder, which was only used here. The controller
 * keeps a 1-line delegator for the ~18 internal callers that still
 * invoke $this->showMainMenu(...).
 *
 * Returns two reply structs that the router sends in order:
 *   1. Status line ("Кассир-бот | Смена открыта\nБаланс: 500,000 UZS")
 *   2. Menu prompt + inline keyboard ("Выберите действие:" + buttons)
 *
 * @phpstan-type Reply array{text: string, kb?: array, type?: string}
 */
final class ShowMainMenuAction
{
    public function __construct(
        private readonly BalanceCalculator $balance,
    ) {}

    /**
     * @return array<int, Reply>
     */
    public function execute(TelegramPosSession $session): array
    {
        // Reset the session so any mid-flow state is cleared when the
        // user taps «Меню».
        $session->update(['state' => 'main_menu', 'data' => null]);

        $shift = $this->balance->getShift($session->user_id);
        $status = $shift ? 'Смена открыта' : 'Смена закрыта';
        $balanceLine = $shift
            ? "\nБаланс: " . $this->balance->fmtBal($this->balance->getBal($shift))
            : '';

        return [
            ['text' => "Кассир-бот | {$status}{$balanceLine}"],
            [
                'text' => 'Выберите действие:',
                'kb'   => $this->buildKeyboard($shift, $session->user_id),
                'type' => 'inline',
            ],
        ];
    }

    /**
     * Role-aware inline keyboard. Non-admins don't see the "внести"
     * (cash-in) button; when no shift is open, only "open" + "guide"
     * are shown.
     */
    private function buildKeyboard(?CashierShift $shift, ?int $userId): array
    {
        if (! $shift) {
            return ['inline_keyboard' => [
                [['text' => 'Открыть смену',  'callback_data' => 'open_shift']],
                [['text' => '📖 Инструкция',  'callback_data' => 'guide']],
            ]];
        }

        $isAdmin = $userId && User::find($userId)?->hasAnyRole(['super_admin', 'admin', 'manager']);

        $kb = [
            [['text' => '💵 Оплата', 'callback_data' => 'payment'], ['text' => '📤 Расход', 'callback_data' => 'expense']],
            [['text' => '🔄 Обмен',  'callback_data' => 'exchange'], ['text' => '💰 Баланс', 'callback_data' => 'balance']],
            [['text' => '📋 Мои операции', 'callback_data' => 'my_txns']],
        ];
        if ($isAdmin) {
            $kb[] = [['text' => '➕ Внести', 'callback_data' => 'cash_in']];
        }
        $kb[] = [['text' => '🔒 Закрыть смену', 'callback_data' => 'close_shift'], ['text' => '📖 Инструкция', 'callback_data' => 'guide']];

        return ['inline_keyboard' => $kb];
    }
}
