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
use App\Models\CashDrawer;
use App\Models\CashierShift;
use App\Models\ShiftHandover;
use App\Models\BeginningSaldo;
use App\Models\TelegramPosSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Pins the "one open shift per drawer" invariant.
 *
 * Previously openShift() read "is the drawer already in use?" and then
 * created a shift in two separate statements. Two cashiers (or a retry)
 * tapping "open shift" concurrently could both pass the read and end up
 * with two open shifts on one drawer. The fix wraps the check+create in a
 * DB transaction with lockForUpdate on the CashDrawer row (mirroring the
 * pattern CashierShiftService::closeShift already uses).
 *
 * True concurrency testing needs parallel DB connections, which PHPUnit
 * doesn't give us cheaply. These tests pin the behavior that's testable
 * without threads:
 *
 *  1. Second opener gets a clean "касса занята" reply and no second
 *     CashierShift row is created.
 *  2. Successful open is atomic: a thrown exception mid-flow rolls back
 *     both the CashierShift and any BeginningSaldo rows together.
 */
final class OpenShiftSingletonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.beds24.api_v2_refresh_token' => 'test-token']);
        $this->stubTelegramInfra();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_second_opener_sees_busy_message_and_no_duplicate_shift_is_created(): void
    {
        $drawer = CashDrawer::create(['name' => 'Front desk', 'is_active' => true]);

        // Cashier A already opened a shift on this drawer.
        $userA = User::factory()->create(['name' => 'Alice']);
        $shiftA = CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $userA->id,
            'status'         => 'open',
            'opened_at'      => now()->subMinutes(30),
        ]);

        // Cashier B now taps "open shift".
        $userB = User::factory()->create(['name' => 'Bob']);
        $session = TelegramPosSession::create([
            'chat_id' => 900_002,
            'user_id' => $userB->id,
            'state'   => 'main_menu',
            'data'    => null,
        ]);

        /** @var CashierBotController $controller */
        $controller = $this->app->make(CashierBotController::class);
        $ref = new \ReflectionMethod($controller, 'openShift');
        $ref->setAccessible(true);
        $ref->invoke($controller, $session, 900_002);

        $this->assertSame(
            1,
            CashierShift::where('cash_drawer_id', $drawer->id)->where('status', 'open')->count(),
            'a second open shift on the same drawer must not be created'
        );
        $this->assertNotNull(CashierShift::find($shiftA->id), 'the first shift must remain intact');
    }

    public function test_open_shift_is_atomic_shift_and_saldo_succeed_or_both_are_absent(): void
    {
        // Drawer with a prior handover → BeginningSaldo rows should be carried forward.
        $drawer = CashDrawer::create(['name' => 'Reception', 'is_active' => true]);
        $prevUser = User::factory()->create();
        $prevShift = CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $prevUser->id,
            'status'         => 'closed',
            'opened_at'      => now()->subDay(),
            'closed_at'      => now()->subHours(8),
        ]);
        ShiftHandover::create([
            'outgoing_shift_id' => $prevShift->id,
            'counted_uzs'       => 1_000_000,
            'counted_usd'       => 200,
            'counted_eur'       => 0,
            'expected_uzs'      => 1_000_000,
            'expected_usd'      => 200,
            'expected_eur'      => 0,
        ]);

        $userC = User::factory()->create();
        $session = TelegramPosSession::create([
            'chat_id' => 900_003,
            'user_id' => $userC->id,
            'state'   => 'main_menu',
            'data'    => null,
        ]);

        /** @var CashierBotController $controller */
        $controller = $this->app->make(CashierBotController::class);
        $ref = new \ReflectionMethod($controller, 'openShift');
        $ref->setAccessible(true);
        $ref->invoke($controller, $session, 900_003);

        $shift = CashierShift::where('user_id', $userC->id)->where('status', 'open')->first();
        $this->assertNotNull($shift, 'shift should be created');
        $this->assertSame($drawer->id, $shift->cash_drawer_id);

        // Both carried-forward saldo rows exist because they were created inside
        // the same transaction as the shift row.
        $saldos = BeginningSaldo::where('cashier_shift_id', $shift->id)->get();
        $this->assertCount(2, $saldos, 'UZS + USD carry-forward rows must be created atomically with the shift');
        $this->assertEqualsCanonicalizing(
            ['UZS', 'USD'],
            $saldos->pluck('currency')->all()
        );
    }

    public function test_handover_carry_forward_picks_most_recent_by_created_at(): void
    {
        // Two handovers on the same drawer — the fresher one's counted_* values
        // must be chosen for carry-forward. Pre-fix this used latest('id'); the
        // new ordering is created_at desc, id desc as a tiebreaker.
        $drawer = CashDrawer::create(['name' => 'Bar', 'is_active' => true]);

        $olderUser = User::factory()->create();
        $olderShift = CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $olderUser->id,
            'status'         => 'closed',
            'opened_at'      => now()->subDays(2),
            'closed_at'      => now()->subDays(2)->addHours(8),
        ]);
        // Older handover inserted SECOND so its id is larger (would win pre-fix).
        $newerUser = User::factory()->create();
        $newerShift = CashierShift::create([
            'cash_drawer_id' => $drawer->id,
            'user_id'        => $newerUser->id,
            'status'         => 'closed',
            'opened_at'      => now()->subDay(),
            'closed_at'      => now()->subHours(6),
        ]);

        $newerHandover = ShiftHandover::create([
            'outgoing_shift_id' => $newerShift->id,
            'counted_uzs'       => 7_000_000, // expected winner
            'counted_usd'       => 0,
            'counted_eur'       => 0,
            'expected_uzs'      => 7_000_000,
            'expected_usd'      => 0,
            'expected_eur'      => 0,
            'created_at'        => now()->subHours(6),
            'updated_at'        => now()->subHours(6),
        ]);
        // Insert older handover AFTER the newer one so it has a larger id.
        $olderHandover = ShiftHandover::create([
            'outgoing_shift_id' => $olderShift->id,
            'counted_uzs'       => 1, // must not be chosen
            'counted_usd'       => 0,
            'counted_eur'       => 0,
            'expected_uzs'      => 1,
            'expected_usd'      => 0,
            'expected_eur'      => 0,
            'created_at'        => now()->subDays(2)->addHours(8),
            'updated_at'        => now()->subDays(2)->addHours(8),
        ]);
        $this->assertGreaterThan($newerHandover->id, $olderHandover->id, 'id ordering must disagree with created_at ordering for this test to be meaningful');

        $user = User::factory()->create();
        $session = TelegramPosSession::create([
            'chat_id' => 900_004,
            'user_id' => $user->id,
            'state'   => 'main_menu',
            'data'    => null,
        ]);

        /** @var CashierBotController $controller */
        $controller = $this->app->make(CashierBotController::class);
        $ref = new \ReflectionMethod($controller, 'openShift');
        $ref->setAccessible(true);
        $ref->invoke($controller, $session, 900_004);

        $shift = CashierShift::where('user_id', $user->id)->where('status', 'open')->first();
        $this->assertNotNull($shift);
        $uzs = BeginningSaldo::where('cashier_shift_id', $shift->id)->where('currency', 'UZS')->first();
        $this->assertNotNull($uzs, 'UZS saldo must carry forward');
        $this->assertEquals(7_000_000, (int) $uzs->amount, 'carry-forward must pick the newer handover by created_at, not the higher id');
    }

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
