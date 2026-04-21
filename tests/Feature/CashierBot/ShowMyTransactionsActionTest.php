<?php

declare(strict_types=1);

namespace Tests\Feature\CashierBot;

use App\Actions\CashierBot\Handlers\ShowMyTransactionsAction;
use App\Models\CashDrawer;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parity pin for ShowMyTransactionsAction — extracted from
 * CashierBotController::showMyTransactions.
 */
final class ShowMyTransactionsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_open_shift_returns_short_error_reply(): void
    {
        $user = User::factory()->create();

        $reply = app(ShowMyTransactionsAction::class)->execute($user->id);

        $this->assertSame('Нет открытой смены.', $reply['text']);
    }

    public function test_empty_shift_returns_placeholder_with_menu_button(): void
    {
        [$user] = $this->openShift();

        $reply = app(ShowMyTransactionsAction::class)->execute($user->id);

        $this->assertSame('За эту смену операций ещё нет.', $reply['text']);
        $this->assertSame(
            [[['text' => '« Меню', 'callback_data' => 'menu']]],
            $reply['kb']['inline_keyboard']
        );
    }

    public function test_shift_with_payments_renders_list_with_icons_and_signs(): void
    {
        [$user, $shift] = $this->openShift();

        // A payment in and an expense out — pick icons + signs deterministically.
        CashTransaction::create([
            'cashier_shift_id' => $shift->id,
            'type'             => 'in',
            'category'         => 'payment',
            'currency'         => 'UZS',
            'amount'           => 500_000,
            'notes'            => 'Room 12',
            'source_trigger'   => 'manual',
            'created_by'       => $user->id,
            'occurred_at'      => now()->setTimezone('Asia/Tashkent')->setTime(9, 30),
        ]);
        CashTransaction::create([
            'cashier_shift_id' => $shift->id,
            'type'             => 'out',
            'category'         => 'expense',
            'currency'         => 'UZS',
            'amount'           => 80_000,
            'source_trigger'   => 'manual',
            'created_by'       => $user->id,
            'occurred_at'      => now()->setTimezone('Asia/Tashkent')->setTime(12, 15),
        ]);

        $reply = app(ShowMyTransactionsAction::class)->execute($user->id);

        $this->assertSame('inline', $reply['type']);
        $this->assertStringContainsString('📋 *Операции смены*', $reply['text']);
        $this->assertStringContainsString('💵 +500 000 UZS', $reply['text']);
        $this->assertStringContainsString('_Room 12_', $reply['text']);
        $this->assertStringContainsString('📤 −80 000 UZS', $reply['text']);
    }

    public function test_exchange_transaction_renders_paired_leg(): void
    {
        [$user, $shift] = $this->openShift();

        CashTransaction::create([
            'cashier_shift_id'  => $shift->id,
            'type'              => 'in',
            'category'          => 'exchange',
            'currency'          => 'USD',
            'amount'            => 100,
            'related_currency'  => 'UZS',
            'related_amount'    => 1_280_000,
            'source_trigger'    => 'manual',
            'created_by'        => $user->id,
            'occurred_at'       => now(),
        ]);

        $reply = app(ShowMyTransactionsAction::class)->execute($user->id);

        $this->assertStringContainsString('🔄', $reply['text']);
        $this->assertStringContainsString('+100 USD / 1 280 000 UZS', $reply['text']);
    }

    /**
     * @return array{0: User, 1: CashierShift}
     */
    private function openShift(): array
    {
        $drawer = CashDrawer::create(['name' => 'Txns test drawer', 'is_active' => true]);
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
