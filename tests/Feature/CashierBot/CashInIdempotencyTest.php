<?php

declare(strict_types=1);

namespace Tests\Feature\CashierBot;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\DTOs\ResolvedTelegramBot;
use App\DTOs\TelegramApiResult;
use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Http\Controllers\CashierBotController;
use App\Models\CashierShift;
use App\Models\CashTransaction;
use App\Models\TelegramPosSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression for the latent cash-in double-record bug.
 *
 * Before this fix, 'confirm_cash_in' was missing from
 * CashierBotController::IDEMPOTENT_ACTIONS and its dispatcher arm didn't
 * plumb $callbackId. A Telegram retry (network blip, double-tap, ack lost)
 * would re-run CashTransaction::create → two deposit rows from one user
 * action. Prod had 0 deposit rows at fix-time, so the bug never fired in
 * the wild — but the gap was real.
 *
 * These tests pin the fix:
 *  1. IDEMPOTENT_ACTIONS must contain every money-writing confirm_*.
 *  2. Double-delivering the same callback_query_id records exactly one
 *     deposit and exactly one processed-callback row.
 */
final class CashInIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Beds24BookingService constructor requires this.
        config(['services.beds24.api_v2_refresh_token' => 'test-token']);

        $this->stubTelegramInfra();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_const_includes_every_money_writing_confirm_action(): void
    {
        $ref = new ReflectionClass(CashierBotController::class);
        $actions = $ref->getConstant('IDEMPOTENT_ACTIONS');

        // If a new money-writing confirm_* is added, update this list and the
        // dispatcher's claim path together — that's the point of pinning it.
        $this->assertEqualsCanonicalizing(
            [
                'confirm_payment',
                'confirm_expense',
                'confirm_exchange',
                'confirm_close',
                'confirm_cash_in',
            ],
            $actions,
            'IDEMPOTENT_ACTIONS drifted — a money-writing confirm callback may have lost its retry guard.'
        );
    }

    public function test_repeated_confirm_cash_in_callback_records_only_one_deposit(): void
    {
        [, $shift, $session] = $this->arrangeCashInSession(amount: 100, currency: 'USD');

        $callbackId = 'cb-test-cashin-retry-1';
        $chatId = 555_100;
        $session->update(['chat_id' => $chatId]);

        $update = [
            'callback_query' => [
                'id'      => $callbackId,
                'data'    => 'confirm_cash_in',
                'from'    => ['id' => $chatId, 'username' => 'admin'],
                'message' => ['chat' => ['id' => $chatId], 'message_id' => 1],
            ],
        ];

        /** @var CashierBotController $controller */
        $controller = $this->app->make(CashierBotController::class);

        // First delivery — records the deposit.
        $controller->processUpdate($update);

        $this->assertSame(
            1,
            CashTransaction::where('category', 'deposit')->where('cashier_shift_id', $shift->id)->count(),
            'first callback should record exactly one deposit'
        );
        $this->assertSame(
            1,
            DB::table('telegram_processed_callbacks')->where('callback_query_id', $callbackId)->count()
        );
        $this->assertSame(
            'succeeded',
            DB::table('telegram_processed_callbacks')->where('callback_query_id', $callbackId)->value('status')
        );

        // Second delivery (Telegram retry) — MUST NOT create a second deposit.
        $controller->processUpdate($update);

        $this->assertSame(
            1,
            CashTransaction::where('category', 'deposit')->where('cashier_shift_id', $shift->id)->count(),
            'retry must not create a duplicate deposit'
        );
        $this->assertSame(
            1,
            DB::table('telegram_processed_callbacks')->where('callback_query_id', $callbackId)->count(),
            'retry must not create a second processed-callback row'
        );
    }

    /**
     * Build an open shift, an admin user, and a session sitting at the
     * confirm_cash_in step — exactly where the cash-in flow leaves the
     * session right before the user taps confirm.
     *
     * @return array{0: User, 1: CashierShift, 2: TelegramPosSession}
     */
    private function arrangeCashInSession(float $amount, string $currency): array
    {
        Role::findOrCreate('admin');

        $user = User::factory()->create();
        $user->assignRole('admin');

        $shift = CashierShift::factory()->create([
            'user_id'   => $user->id,
            'status'    => 'open',
            'opened_at' => now(),
        ]);

        $session = TelegramPosSession::create([
            'chat_id' => 0, // overwritten per-test
            'user_id' => $user->id,
            'state'   => 'confirm_cash_in',
            'data'    => [
                'shift_id' => $shift->id,
                'amount'   => $amount,
                'currency' => $currency,
            ],
        ]);

        return [$user, $shift, $session];
    }

    /**
     * Swap out the real Telegram transport + bot resolver so the controller
     * can talk to "Telegram" without making any HTTP calls.
     */
    private function stubTelegramInfra(): void
    {
        $bot = new ResolvedTelegramBot(
            botId: 1,
            slug: 'cashier',
            name: 'Cashier Bot',
            botUsername: null,
            status: BotStatus::Active,
            environment: BotEnvironment::Development,
            token: 'fake-token-never-used',
        );

        $resolver = Mockery::mock(BotResolverInterface::class);
        $resolver->shouldReceive('resolve')->andReturn($bot);

        $transport = Mockery::mock(TelegramTransportInterface::class);
        $transport->shouldReceive('sendMessage')->andReturn(
            new TelegramApiResult(ok: true, result: ['message_id' => 1], httpStatus: 200)
        );
        $transport->shouldReceive('call')->andReturn(
            new TelegramApiResult(ok: true, result: [], httpStatus: 200)
        );

        $this->app->instance(BotResolverInterface::class, $resolver);
        $this->app->instance(TelegramTransportInterface::class, $transport);
    }
}
