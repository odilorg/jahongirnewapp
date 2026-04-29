<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CashierBot;

use App\Http\Controllers\CashierBotController;
use App\Models\TelegramPosSession;
use App\Models\User;
use App\Services\CashierBot\CashierBotCallbackRouter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Idempotency-lifecycle contract for the 5 financial confirm_* callbacks.
 *
 * Lifecycle states in `telegram_processed_callbacks`:
 *   processing → succeeded   (handler completes)
 *   processing → failed      (handler errors; user may retry)
 *
 * Router behavior under repeated callback_ids:
 *   - First call:   row inserted with status=processing → returns 'claimed' →
 *                   handler runs.
 *   - Second call (while still processing): user sees "in progress" message;
 *                   handler does NOT run again.
 *   - Second call (after success): user sees "already processed" message;
 *                   handler does NOT run again.
 *   - Second call (after failure): the failed row is deleted and a fresh
 *                   claim is allowed; handler runs again.
 */
final class CashierBotCallbackRouterIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    private CashierBotCallbackRouter $router;
    private SpyCashierBotController $controller;
    private int $chatId = 901_000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new CashierBotCallbackRouter();
        $this->controller = $this->app->make(SpyCashierBotController::class);

        $user = User::factory()->create();
        TelegramPosSession::create([
            'chat_id' => $this->chatId,
            'user_id' => $user->id,
            'state'   => 'payment_confirm',
            'data'    => ['shift_id' => 1],
        ]);
    }

    public function test_first_confirm_payment_routes_to_handler(): void
    {
        $this->dispatch('cb-1', 'confirm_payment');

        $this->assertContains('confirmPayment', $this->controller->called);
        $this->assertSame(
            'processing',
            DB::table('telegram_processed_callbacks')
                ->where('callback_query_id', 'cb-1')->value('status')
        );
    }

    public function test_duplicate_confirm_while_processing_does_not_dispatch_handler(): void
    {
        $this->dispatch('cb-2', 'confirm_payment');
        $this->controller->called = []; // reset spy

        $this->dispatch('cb-2', 'confirm_payment'); // duplicate

        $this->assertNotContains('confirmPayment', $this->controller->called, 'Duplicate must not re-run handler');
        $this->assertContains('send', $this->controller->called, 'User must receive the in-progress message');
    }

    public function test_duplicate_confirm_after_success_does_not_dispatch_handler(): void
    {
        $this->dispatch('cb-3', 'confirm_payment');
        DB::table('telegram_processed_callbacks')
            ->where('callback_query_id', 'cb-3')
            ->update(['status' => 'succeeded', 'completed_at' => now()]);

        $this->controller->called = [];
        $this->dispatch('cb-3', 'confirm_payment');

        $this->assertNotContains('confirmPayment', $this->controller->called);
        $this->assertContains('send', $this->controller->called, 'User must receive the already-processed message');
    }

    public function test_retry_after_failed_status_allows_fresh_claim(): void
    {
        $this->dispatch('cb-4', 'confirm_payment');
        DB::table('telegram_processed_callbacks')
            ->where('callback_query_id', 'cb-4')
            ->update(['status' => 'failed', 'error' => 'simulated', 'completed_at' => now()]);

        $this->controller->called = [];
        $this->dispatch('cb-4', 'confirm_payment'); // retry

        $this->assertContains('confirmPayment', $this->controller->called, 'Retry after failure must run handler');
        $this->assertSame(
            'processing',
            DB::table('telegram_processed_callbacks')
                ->where('callback_query_id', 'cb-4')->value('status'),
            'Failed row must be replaced with a fresh processing row'
        );
    }

    public function test_non_financial_callback_does_not_create_idempotency_row(): void
    {
        $this->dispatch('cb-5', 'menu'); // not in IDEMPOTENT_ACTIONS

        $this->assertSame(
            0,
            DB::table('telegram_processed_callbacks')
                ->where('callback_query_id', 'cb-5')->count(),
            'Non-financial callbacks must not create idempotency rows'
        );
        $this->assertContains('showMainMenu', $this->controller->called);
    }

    private function dispatch(string $callbackId, string $action): void
    {
        $this->router->dispatch([
            'id'      => $callbackId,
            'data'    => $action,
            'message' => ['chat' => ['id' => $this->chatId], 'message_id' => 1],
        ], $this->controller);
    }
}
