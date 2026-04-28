<?php

declare(strict_types=1);

namespace Tests\Feature\CashierBot;

use App\Http\Controllers\CashierBotController;
use App\Models\TelegramPosSession;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Reachability contract for handleState's safety rail.
 *
 * The orphan-reset default arm must NOT fire for any state that handleState
 * still routes explicitly. This test drives each live state with valid input
 * and asserts the session does NOT collapse to `main_menu` with cleared `data`.
 *
 * If a future refactor accidentally drops a state from the match() expression,
 * its handler input would silently hit the default arm and discard in-flight
 * payment / shift / expense data. This test catches that.
 */
final class LiveStatesNotResetTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Every state that has a dedicated arm in CashierBotController::handleState().
     * If you add a new state, add it here too.
     */
    public static function liveStatesProvider(): array
    {
        return [
            'payment_room'                => ['payment_room', '12345', ['shift_id' => 1]],
            'payment_fx_amount'           => ['payment_fx_amount', '500000', ['fx_presented_amount' => 500_000, 'currency' => 'UZS', 'fx_presentation' => ['x' => 1]]],
            'payment_fx_override_reason'  => ['payment_fx_override_reason', 'клиент принёс точную сумму', ['shift_id' => 1]],
            'expense_amount'              => ['expense_amount', '50000 UZS', ['category_id' => 1]],
            'expense_desc'                => ['expense_desc', 'кофе для офиса', ['amount' => 50_000, 'currency' => 'UZS']],
            'cash_in_amount'              => ['cash_in_amount', '1000000 UZS', ['shift_id' => 1]],
            'exchange_in_amount'          => ['exchange_in_amount', '100', ['in_currency' => 'USD']],
            'exchange_out_amount'         => ['exchange_out_amount', '1280000', ['in_currency' => 'USD', 'in_amount' => 100, 'out_currency' => 'UZS']],
            'shift_count_uzs'             => ['shift_count_uzs', '500000', ['shift_id' => 1]],
            'shift_count_usd'             => ['shift_count_usd', '100', ['shift_id' => 1, 'count_uzs' => 500_000]],
            'shift_count_eur'             => ['shift_count_eur', '50', ['shift_id' => 1, 'count_uzs' => 500_000, 'count_usd' => 100]],
        ];
    }

    /**
     * @dataProvider liveStatesProvider
     */
    public function test_live_state_does_not_trigger_safety_rail(string $state, string $input, array $data): void
    {
        $controller = $this->app->make(TestableLiveStatesController::class);

        $user = User::factory()->create();
        $session = TelegramPosSession::create([
            'chat_id' => 700_000 + crc32($state) % 100_000,
            'user_id' => $user->id,
            'state'   => $state,
            'data'    => $data,
        ]);

        $controller->callHandleState($session, $session->chat_id, $input);

        $fresh = $session->fresh();

        $this->assertNotSame(
            'main_menu',
            $fresh->state,
            "Live state `{$state}` was reset to main_menu by the orphan-reset safety rail. "
            . 'This means handleState() no longer has an explicit arm for it. '
            . 'If you intentionally removed the state, also remove this provider entry.'
        );

        // Also: data must NOT be wiped to null (orphan reset signature).
        $this->assertNotNull(
            $fresh->data,
            "Live state `{$state}` had its data cleared by the safety rail."
        );

        $this->assertEmpty(
            array_filter(
                $controller->sentMessages,
                fn (string $m) => $m === 'Сессия устарела, начните заново.'
            ),
            "Live state `{$state}` triggered the orphan-reset message."
        );
    }
}

/**
 * Test seam — silences I/O. Stubs all the downstream business calls that the
 * live-state handlers might make, so we can isolate the routing decision in
 * handleState() without exercising shift/payment/expense services.
 */
class TestableLiveStatesController extends CashierBotController
{
    public array $sentMessages = [];

    protected function send(int $chatId, string $text, mixed $kb = null, string $type = 'reply'): void
    {
        $this->sentMessages[] = $text;
    }

    protected function showMainMenu(int $chatId, $session): mixed
    {
        return response('OK');
    }

    public function callHandleState($s, int $chatId, string $text): mixed
    {
        return $this->handleState($s, $chatId, $text);
    }
}
