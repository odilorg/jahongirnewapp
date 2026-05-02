<?php

namespace Tests\Unit\CashierBot;

use App\Http\Controllers\CashierBotController;
use App\Models\TelegramPosSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Mockery;
use Tests\TestCase;

/**
 * Asserts the expense-approval gate is opt-in. Default OFF — expenses
 * go straight through with no owner ping. When CASHIER_EXPENSE_APPROVAL=true,
 * amounts above per-currency thresholds set $d['needs_approval']=true.
 */
class ExpenseApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private function callHExpDesc(string $currency, float $amount): array
    {
        $session = TelegramPosSession::create([
            'chat_id' => 9000000 + random_int(1, 999999),
            'state'   => 'expense_amount',
            'data'    => [
                'shift_id' => 1, 'cat_id' => 1, 'cat_name' => 'X',
                'amount' => $amount, 'currency' => $currency,
            ],
        ]);

        $controller = Mockery::mock(CashierBotController::class)->makePartial();
        $controller->shouldAllowMockingProtectedMethods();
        $controller->shouldReceive('send')->andReturn(new Response('OK'));

        $reflect = new \ReflectionMethod(CashierBotController::class, 'hExpDesc');
        $reflect->setAccessible(true);
        $reflect->invoke($controller, $session, 1, 'sample description');

        return $session->fresh()->data ?? [];
    }

    /** @test */
    public function gate_disabled_by_default_no_approval_required(): void
    {
        config(['services.cashier_bot.expense_approval_enabled' => false]);

        foreach ([['EUR', 210], ['USD', 500], ['UZS', 5_000_000], ['RUB', 100_000]] as [$cur, $amt]) {
            $data = $this->callHExpDesc($cur, $amt);
            $this->assertFalse(
                $data['needs_approval'] ?? null,
                "{$amt} {$cur} must NOT require approval when gate disabled"
            );
        }
    }

    /** @test */
    public function gate_enabled_uses_per_currency_thresholds(): void
    {
        config([
            'services.cashier_bot.expense_approval_enabled'         => true,
            'services.cashier_bot.expense_approval_threshold_uzs'   => 500_000,
            'services.cashier_bot.expense_approval_threshold_usd'   => 40,
            'services.cashier_bot.expense_approval_threshold_eur'   => 35,
            'services.cashier_bot.expense_approval_threshold_rub'   => 4_000,
        ]);

        // Above threshold → approval required.
        foreach ([['UZS', 600_000], ['USD', 50], ['EUR', 210], ['RUB', 5_000]] as [$cur, $amt]) {
            $data = $this->callHExpDesc($cur, $amt);
            $this->assertTrue(
                $data['needs_approval'] ?? null,
                "{$amt} {$cur} must require approval (above threshold)"
            );
        }

        // At/below threshold → no approval.
        foreach ([['UZS', 500_000], ['USD', 40], ['EUR', 35], ['RUB', 4_000]] as [$cur, $amt]) {
            $data = $this->callHExpDesc($cur, $amt);
            $this->assertFalse(
                $data['needs_approval'] ?? null,
                "{$amt} {$cur} must NOT require approval (at threshold boundary, > comparison)"
            );
        }
    }
}
