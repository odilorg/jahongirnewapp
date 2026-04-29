<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CashierBot;

use App\Http\Controllers\OwnerBotController;
use App\Services\CashierBot\CashierBotCallbackRouter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use Tests\TestCase;

/**
 * Owner-bot delegation contract.
 *
 * `approve_expense_<id>` and `reject_expense_<id>` callbacks must be
 * routed to OwnerBotController::handleExpenseAction with byte-identical
 * arguments (chat_id, message_id, callback_id, action, expense_id).
 *
 * This delegation is intentionally OUTSIDE the cashier idempotency claim:
 * the owner bot owns its own approval lifecycle.
 */
final class CashierBotCallbackRouterOwnerDelegationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_approve_expense_callback_delegates_to_owner_bot(): void
    {
        $mock = Mockery::mock(OwnerBotController::class);
        $mock->shouldReceive('handleExpenseAction')
             ->once()
             ->with(11_111, 222, 'cb-approve-1', 'approve', 42)
             ->andReturn(response('OK'));
        $this->app->instance(OwnerBotController::class, $mock);

        $controller = $this->app->make(SpyCashierBotController::class);
        $router = new CashierBotCallbackRouter();

        $router->dispatch([
            'id'      => 'cb-approve-1',
            'data'    => 'approve_expense_42',
            'message' => ['chat' => ['id' => 11_111], 'message_id' => 222],
        ], $controller);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_reject_expense_callback_delegates_to_owner_bot(): void
    {
        $mock = Mockery::mock(OwnerBotController::class);
        $mock->shouldReceive('handleExpenseAction')
             ->once()
             ->with(11_222, 333, 'cb-reject-1', 'reject', 99)
             ->andReturn(response('OK'));
        $this->app->instance(OwnerBotController::class, $mock);

        $controller = $this->app->make(SpyCashierBotController::class);
        $router = new CashierBotCallbackRouter();

        $router->dispatch([
            'id'      => 'cb-reject-1',
            'data'    => 'reject_expense_99',
            'message' => ['chat' => ['id' => 11_222], 'message_id' => 333],
        ], $controller);

        $this->addToAssertionCount(Mockery::getContainer()->mockery_getExpectationCount());
    }

    public function test_owner_delegation_bypasses_cashier_idempotency_claim(): void
    {
        $mock = Mockery::mock(OwnerBotController::class);
        $mock->shouldReceive('handleExpenseAction')->once()->andReturn(response('OK'));
        $this->app->instance(OwnerBotController::class, $mock);

        $controller = $this->app->make(SpyCashierBotController::class);
        $router = new CashierBotCallbackRouter();

        $router->dispatch([
            'id'      => 'cb-bypass-1',
            'data'    => 'approve_expense_7',
            'message' => ['chat' => ['id' => 11_333], 'message_id' => 1],
        ], $controller);

        $this->assertSame(
            0,
            \DB::table('telegram_processed_callbacks')
                ->where('callback_query_id', 'cb-bypass-1')->count(),
            'Owner-bot delegation must not write to telegram_processed_callbacks'
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
