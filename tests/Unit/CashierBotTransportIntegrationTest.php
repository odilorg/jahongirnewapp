<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\DTOs\ResolvedTelegramBot;
use App\DTOs\TelegramApiResult;
use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Http\Controllers\CashierBotController;
use App\Http\Controllers\OwnerBotController;
use App\Models\CashExpense;
use Mockery;
use Tests\TestCase;

/**
 * Runtime integration tests proving CashierBotController and OwnerBotController
 * actually exercise BotResolver + TelegramTransport through real code paths.
 *
 * Uses direct method invocation (not HTTP) to avoid database migration issues.
 * The send() / aCb() / sendApprovalRequest() methods are the actual code paths
 * that were migrated from raw Http::post() to resolver+transport.
 */
class CashierBotTransportIntegrationTest extends TestCase
{
    private function makeFakeBot(string $slug): ResolvedTelegramBot
    {
        return new ResolvedTelegramBot(
            botId: 1,
            slug: $slug,
            name: ucfirst($slug) . ' Bot',
            botUsername: null,
            status: BotStatus::Active,
            environment: BotEnvironment::Development,
            token: 'fake-token-never-used',
        );
    }

    private function successResult(): TelegramApiResult
    {
        return new TelegramApiResult(ok: true, result: ['message_id' => 1], httpStatus: 200);
    }

    // ──────────────────────────────────────────────
    // CashierBotController: send() resolves 'cashier' + calls transport
    // ──────────────────────────────────────────────

    /** @test */
    public function cashier_send_resolves_cashier_slug_and_calls_transport_send_message(): void
    {
        $cashierBot = $this->makeFakeBot('cashier');

        $resolver = Mockery::mock(BotResolverInterface::class);
        $resolver->shouldReceive('resolve')
            ->with('cashier')
            ->once()
            ->andReturn($cashierBot);

        $transport = Mockery::mock(TelegramTransportInterface::class);
        $transport->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (ResolvedTelegramBot $bot, $chatId, $text, $extra) use ($cashierBot) {
                return $bot === $cashierBot
                    && $chatId === 99999
                    && $text === 'Hello from test'
                    && $extra['parse_mode'] === 'HTML';
            })
            ->andReturn($this->successResult());

        $this->app->instance(BotResolverInterface::class, $resolver);
        $this->app->instance(TelegramTransportInterface::class, $transport);

        // Instantiate controller via container (injects our mocks)
        $controller = $this->app->make(CashierBotController::class);

        // Call the protected send() method directly
        $ref = new \ReflectionMethod($controller, 'send');
        $ref->invoke($controller, 99999, 'Hello from test');

        // Mockery assertions are verified automatically on tearDown
    }

    /** @test */
    public function cashier_acb_resolves_cashier_slug_and_calls_transport_answer_callback(): void
    {
        $cashierBot = $this->makeFakeBot('cashier');

        $resolver = Mockery::mock(BotResolverInterface::class);
        $resolver->shouldReceive('resolve')
            ->with('cashier')
            ->once()
            ->andReturn($cashierBot);

        $transport = Mockery::mock(TelegramTransportInterface::class);
        $transport->shouldReceive('call')
            ->once()
            ->withArgs(function (ResolvedTelegramBot $bot, string $method, array $params) use ($cashierBot) {
                return $bot === $cashierBot
                    && $method === 'answerCallbackQuery'
                    && $params['callback_query_id'] === 'cb-123';
            })
            ->andReturn($this->successResult());

        $this->app->instance(BotResolverInterface::class, $resolver);
        $this->app->instance(TelegramTransportInterface::class, $transport);

        $controller = $this->app->make(CashierBotController::class);

        $ref = new \ReflectionMethod($controller, 'aCb');
        $ref->invoke($controller, 'cb-123');
    }

    // ──────────────────────────────────────────────
    // OwnerBotController: sendApprovalRequest resolves 'owner-alert'
    // ──────────────────────────────────────────────

    /** @test */
    public function owner_send_approval_resolves_owner_alert_and_calls_transport(): void
    {
        $ownerBot = $this->makeFakeBot('owner-alert');

        $resolver = Mockery::mock(BotResolverInterface::class);
        $resolver->shouldReceive('resolve')
            ->with('owner-alert')
            ->once()
            ->andReturn($ownerBot);

        $transport = Mockery::mock(TelegramTransportInterface::class);
        $transport->shouldReceive('sendMessage')
            ->once()
            ->withArgs(function (ResolvedTelegramBot $bot, $chatId, $text, $extra) use ($ownerBot) {
                return $bot === $ownerBot
                    && $chatId === '12345'
                    && str_contains($text, 'Расход на одобрение')
                    && $extra['parse_mode'] === 'HTML'
                    && str_contains($extra['reply_markup'], 'approve_expense_');
            })
            ->andReturn($this->successResult());

        $this->app->instance(BotResolverInterface::class, $resolver);
        $this->app->instance(TelegramTransportInterface::class, $transport);

        config(['services.owner_alert_bot.owner_chat_id' => '12345']);

        // Create a minimal expense mock (avoids database)
        $expense = Mockery::mock(CashExpense::class)->makePartial();
        $expense->id = 42;
        $expense->amount = 50000;
        $expense->currency = 'UZS';
        $expense->description = 'Test expense';
        $expense->occurred_at = now();
        $expense->expense_category_id = 1;

        // Mock the relationships
        $expense->shouldReceive('load')->andReturnSelf();
        $expense->shouldReceive('getAttribute')->with('creator')->andReturn(
            (object) ['name' => 'Test Cashier']
        );
        $expense->shouldReceive('getAttribute')->with('category')->andReturn(
            (object) ['name' => 'Office Supplies']
        );

        $controller = $this->app->make(OwnerBotController::class);
        $controller->sendApprovalRequest($expense);
    }

    /** @test */
    public function owner_answer_callback_resolves_owner_alert_and_calls_transport(): void
    {
        $ownerBot = $this->makeFakeBot('owner-alert');

        $resolver = Mockery::mock(BotResolverInterface::class);
        $resolver->shouldReceive('resolve')
            ->with('owner-alert')
            ->once()
            ->andReturn($ownerBot);

        $transport = Mockery::mock(TelegramTransportInterface::class);
        $transport->shouldReceive('call')
            ->once()
            ->withArgs(function (ResolvedTelegramBot $bot, string $method, array $params) use ($ownerBot) {
                return $bot === $ownerBot
                    && $method === 'answerCallbackQuery'
                    && $params['callback_query_id'] === 'owner-cb-456'
                    && $params['show_alert'] === true;
            })
            ->andReturn($this->successResult());

        $this->app->instance(BotResolverInterface::class, $resolver);
        $this->app->instance(TelegramTransportInterface::class, $transport);

        $controller = $this->app->make(OwnerBotController::class);

        $ref = new \ReflectionMethod($controller, 'answerCallback');
        $ref->invoke($controller, 'owner-cb-456', 'Test response');
    }
}
