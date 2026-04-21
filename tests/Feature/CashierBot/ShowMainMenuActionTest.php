<?php

declare(strict_types=1);

namespace Tests\Feature\CashierBot;

use App\Actions\CashierBot\Handlers\ShowMainMenuAction;
use App\Models\CashDrawer;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\TelegramPosSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Parity pin for ShowMainMenuAction — extracted from
 * CashierBotController::showMainMenu + menuKb().
 */
final class ShowMainMenuActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_open_shift_shows_closed_status_and_minimal_keyboard(): void
    {
        $user = User::factory()->create();
        $session = TelegramPosSession::create([
            'chat_id' => 600_001,
            'user_id' => $user->id,
            'state'   => 'payment_amount',
            'data'    => ['shift_id' => 1],
        ]);

        $replies = app(ShowMainMenuAction::class)->execute($session);

        // Status line first, then menu prompt.
        $this->assertCount(2, $replies);
        $this->assertStringContainsString('Кассир-бот | Смена закрыта', $replies[0]['text']);
        $this->assertStringNotContainsString('Баланс:', $replies[0]['text']);
        $this->assertSame('Выберите действие:', $replies[1]['text']);
        $this->assertSame('inline', $replies[1]['type']);

        // No-shift keyboard has exactly two buttons: open + guide.
        $rows = $replies[1]['kb']['inline_keyboard'];
        $this->assertCount(2, $rows);
        $this->assertSame('open_shift', $rows[0][0]['callback_data']);
        $this->assertSame('guide',      $rows[1][0]['callback_data']);

        // Session state must be reset.
        $session->refresh();
        $this->assertSame('main_menu', $session->state);
        $this->assertNull($session->data);
    }

    public function test_open_shift_shows_balance_and_standard_keyboard_for_cashier(): void
    {
        [$user, $session, $shift] = $this->openShift();

        CashTransaction::create([
            'cashier_shift_id' => $shift->id,
            'type'             => 'in',
            'category'         => 'sale',
            'currency'         => 'UZS',
            'amount'           => 250_000,
            'source_trigger'   => 'cashier_bot',
            'created_by'       => $user->id,
            'occurred_at'      => now(),
        ]);

        $replies = app(ShowMainMenuAction::class)->execute($session);

        $this->assertStringContainsString('Смена открыта', $replies[0]['text']);
        $this->assertStringContainsString('Баланс: 250,000 UZS', $replies[0]['text']);

        $rows = $replies[1]['kb']['inline_keyboard'];
        // Standard cashier keyboard: payment+expense / exchange+balance /
        // my_txns / close_shift+guide — four rows, no admin "внести".
        $this->assertCount(4, $rows);
        $callbacks = array_map(fn($row) => array_column($row, 'callback_data'), $rows);
        $flat = array_merge(...$callbacks);
        $this->assertContains('payment',     $flat);
        $this->assertContains('expense',     $flat);
        $this->assertContains('exchange',    $flat);
        $this->assertContains('balance',     $flat);
        $this->assertContains('my_txns',     $flat);
        $this->assertContains('close_shift', $flat);
        $this->assertContains('guide',       $flat);
        $this->assertNotContains('cash_in',  $flat, 'non-admin must not see the cash-in button');
    }

    public function test_admin_sees_cash_in_button_on_top_of_standard_keyboard(): void
    {
        Role::findOrCreate('admin');

        [$user, $session] = $this->openShift();
        $user->assignRole('admin');

        $replies = app(ShowMainMenuAction::class)->execute($session);

        $rows = $replies[1]['kb']['inline_keyboard'];
        $this->assertCount(5, $rows, 'admin keyboard has five rows — one extra for cash_in');
        $callbacks = array_merge(...array_map(fn($row) => array_column($row, 'callback_data'), $rows));
        $this->assertContains('cash_in', $callbacks);
    }

    /**
     * @return array{0: User, 1: TelegramPosSession, 2: CashierShift}
     */
    private function openShift(): array
    {
        $drawer = CashDrawer::create(['name' => 'Menu test drawer ' . uniqid(), 'is_active' => true]);
        $user = User::factory()->create();
        $shift = CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $user->id,
            'status'         => 'open',
            'opened_at'      => now(),
        ]);
        $session = TelegramPosSession::create([
            'chat_id' => 600_010 + $user->id,
            'user_id' => $user->id,
            'state'   => 'main_menu',
            'data'    => null,
        ]);

        return [$user, $session, $shift];
    }
}
