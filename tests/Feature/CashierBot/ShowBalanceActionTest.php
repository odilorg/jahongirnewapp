<?php

declare(strict_types=1);

namespace Tests\Feature\CashierBot;

use App\Actions\CashierBot\Handlers\ShowBalanceAction;
use App\Models\BeginningSaldo;
use App\Models\CashDrawer;
use App\Models\CashExpense;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parity pin for ShowBalanceAction — extracted from
 * CashierBotController::showBalance.
 *
 * Uses RefreshDatabase because BalanceCalculator::getBal queries
 * BeginningSaldo + CashTransaction rows.
 */
final class ShowBalanceActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_open_shift_returns_short_error_reply(): void
    {
        $user = User::factory()->create();

        $reply = app(ShowBalanceAction::class)->execute($user->id);

        $this->assertSame('Нет открытой смены.', $reply['text']);
        $this->assertArrayNotHasKey('kb', $reply);
    }

    public function test_null_user_id_returns_short_error_reply(): void
    {
        $reply = app(ShowBalanceAction::class)->execute(null);

        $this->assertSame('Нет открытой смены.', $reply['text']);
    }

    public function test_open_shift_returns_balance_summary_with_back_button(): void
    {
        [$user, $shift] = $this->openShift();

        BeginningSaldo::create(['cashier_shift_id' => $shift->id, 'currency' => 'UZS', 'amount' => 500_000]);
        CashTransaction::create([
            'cashier_shift_id' => $shift->id,
            'type'             => 'in',
            'category'         => 'sale',
            'currency'         => 'UZS',
            'amount'           => 200_000,
            'source_trigger'   => 'cashier_bot',
            'created_by'       => $user->id,
            'occurred_at'      => now(),
        ]);
        $category = ExpenseCategory::create(['name' => 'Test', 'icon' => '🧪']);
        CashExpense::create([
            'cashier_shift_id'     => $shift->id,
            'expense_category_id'  => $category->id,
            'currency'             => 'UZS',
            'amount'               => 50_000,
            'description'          => 'cleaner',
            'created_by'           => $user->id,
            'occurred_at'          => now(),
        ]);

        $reply = app(ShowBalanceAction::class)->execute($user->id);

        $this->assertStringContainsString('Баланс за смену', $reply['text']);
        // fmtBal uses number_format(..., 0) which defaults to comma thousands.
        // 500k beginning saldo + 200k transaction = 700,000 UZS; expense sits
        // in a separate table and doesn't affect getBal sums directly.
        $this->assertStringContainsString('700,000 UZS', $reply['text']);
        $this->assertStringContainsString('Операций: 1 | Расходов: 1', $reply['text']);
        $this->assertSame('inline', $reply['type']);
        $this->assertSame(
            [[['text' => 'Назад', 'callback_data' => 'menu']]],
            $reply['kb']['inline_keyboard']
        );
    }

    /**
     * @return array{0: User, 1: CashierShift}
     */
    private function openShift(): array
    {
        $drawer = CashDrawer::create(['name' => 'Balance test drawer', 'is_active' => true]);
        $user = User::factory()->create();
        $shift = CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $user->id,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);

        return [$user, $shift];
    }
}
