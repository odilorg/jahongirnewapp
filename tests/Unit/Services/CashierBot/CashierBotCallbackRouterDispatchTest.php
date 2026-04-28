<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CashierBot;

use App\Http\Controllers\CashierBotController;
use App\Models\TelegramPosSession;
use App\Models\User;
use App\Services\CashierBot\CashierBotCallbackRouter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Golden-master parity test for CashierBotCallbackRouter::dispatch.
 *
 * Every supported callback_data prefix must hit the same handler method on
 * CashierBotController as before the A2 extraction. If a future change
 * silently drops or remaps a callback, this catches it.
 *
 * Each test invocation:
 *   - seeds an authenticated cashier session
 *   - records which @internal handler method the router calls (via a
 *     spying subclass)
 *   - asserts the recorded method name matches the expected dispatch target
 *
 * The parity table mirrors the match-arms in the router itself.
 * If a new callback prefix is added to the router, add a row here too.
 */
final class CashierBotCallbackRouterDispatchTest extends TestCase
{
    use DatabaseTransactions;

    public static function dispatchProvider(): array
    {
        return [
            'open_shift'          => ['open_shift',          'openShift'],
            'payment'             => ['payment',             'startPayment'],
            'expense'             => ['expense',             'startExpense'],
            'exchange'            => ['exchange',            'startExchange'],
            'cash_in'             => ['cash_in',             'startCashIn'],
            'confirm_cash_in'     => ['confirm_cash_in',     'confirmCashIn'],
            'close_shift'         => ['close_shift',         'startClose'],
            'menu'                => ['menu',                'showMainMenu'],
            'cancel'              => ['cancel',              'showMainMenu'],
            'guest_42'            => ['guest_42',            'selectGuest'],
            'cur_USD'             => ['cur_USD',             'selectCur'],
            'fx_confirm_amount'   => ['fx_confirm_amount',   'fxConfirmAmount'],
            'excur_USD'           => ['excur_USD',           'selectExCur'],
            'exout_UZS'           => ['exout_UZS',           'selectExOutCur'],
            'method_cash'         => ['method_cash',         'selectMethod'],
            'expcat_5'            => ['expcat_5',            'selectExpCat'],
            'confirm_payment'     => ['confirm_payment',     'confirmPayment'],
            'confirm_expense'     => ['confirm_expense',     'confirmExpense'],
            'confirm_exchange'    => ['confirm_exchange',    'confirmExchange'],
            'confirm_close'       => ['confirm_close',       'confirmClose'],
            'guide'               => ['guide',               'dispatchGuide'],
            'guide_payment'       => ['guide_payment',       'dispatchGuide'],
            // 'balance' and 'my_txns' delegate via dispatchReply — covered separately
        ];
    }

    /**
     * @dataProvider dispatchProvider
     */
    public function test_callback_routes_to_expected_handler(string $callbackData, string $expectedMethod): void
    {
        $controller = $this->makeSpyController();
        $this->seedSessionFor(900_001);

        $router = new CashierBotCallbackRouter();
        $router->dispatch([
            'id'      => "cb-{$callbackData}",
            'data'    => $callbackData,
            'message' => ['chat' => ['id' => 900_001], 'message_id' => 7],
        ], $controller);

        $this->assertContains(
            $expectedMethod,
            $controller->called,
            "Callback `{$callbackData}` did not route to `{$expectedMethod}`. Called: "
            . implode(', ', $controller->called)
        );
    }

    public function test_no_session_returns_ok_without_dispatching(): void
    {
        $controller = $this->makeSpyController();
        // No session for chat 900_999

        $router = new CashierBotCallbackRouter();
        $response = $router->dispatch([
            'id'      => 'cb-x',
            'data'    => 'open_shift',
            'message' => ['chat' => ['id' => 900_999]],
        ], $controller);

        $this->assertSame(200, $response->getStatusCode());
        // aCb is still called even when no session exists (Telegram needs the answer)
        $this->assertSame(['aCb'], $controller->called);
    }

    public function test_missing_chat_id_returns_ok_immediately(): void
    {
        $controller = $this->makeSpyController();

        $router = new CashierBotCallbackRouter();
        $response = $router->dispatch(['id' => 'cb-x', 'data' => 'open_shift', 'message' => []], $controller);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertEmpty($controller->called, 'Router must short-circuit before calling aCb when chat_id missing');
    }

    public function test_unknown_callback_data_falls_through_to_default(): void
    {
        $controller = $this->makeSpyController();
        $this->seedSessionFor(900_002);

        $router = new CashierBotCallbackRouter();
        $response = $router->dispatch([
            'id'      => 'cb-y',
            'data'    => 'totally_unknown_action',
            'message' => ['chat' => ['id' => 900_002]],
        ], $controller);

        $this->assertSame(200, $response->getStatusCode());
        // Only aCb is called; no handler matched
        $this->assertSame(['aCb'], $controller->called);
    }

    private function makeSpyController(): SpyCashierBotController
    {
        return $this->app->make(SpyCashierBotController::class);
    }

    private function seedSessionFor(int $chatId): TelegramPosSession
    {
        $user = User::factory()->create();
        return TelegramPosSession::create([
            'chat_id' => $chatId,
            'user_id' => $user->id,
            'state'   => 'main_menu',
            'data'    => null,
        ]);
    }
}

/**
 * Test seam — overrides every method the router can dispatch to and records
 * which one was called. Returns dummy responses to keep the dispatch path alive.
 */
final class SpyCashierBotController extends CashierBotController
{
    public array $called = [];

    public function aCb(string $id): void { $this->called[] = 'aCb'; }
    public function send(int $chatId, string $text, ?array $kb = null, string $type = 'reply'): void { $this->called[] = 'send'; }
    public function dispatchReply(int $chatId, array $reply): mixed { $this->called[] = 'dispatchReply'; return response('OK'); }
    public function dispatchGuide(int $chatId, ?string $topic): mixed { $this->called[] = 'dispatchGuide'; return response('OK'); }
    public function showMainMenu(int $chatId, $session): mixed { $this->called[] = 'showMainMenu'; return response('OK'); }
    public function openShift($s, int $chatId): mixed { $this->called[] = 'openShift'; return response('OK'); }
    public function startPayment($s, int $chatId): mixed { $this->called[] = 'startPayment'; return response('OK'); }
    public function startExpense($s, int $chatId): mixed { $this->called[] = 'startExpense'; return response('OK'); }
    public function startExchange($s, int $chatId): mixed { $this->called[] = 'startExchange'; return response('OK'); }
    public function startCashIn($s, int $chatId): mixed { $this->called[] = 'startCashIn'; return response('OK'); }
    public function confirmCashIn($s, int $chatId, string $callbackId = ''): mixed { $this->called[] = 'confirmCashIn'; return response('OK'); }
    public function startClose($s, int $chatId): mixed { $this->called[] = 'startClose'; return response('OK'); }
    public function selectGuest($s, int $chatId, string $data): mixed { $this->called[] = 'selectGuest'; return response('OK'); }
    public function selectCur($s, int $chatId, string $data): mixed { $this->called[] = 'selectCur'; return response('OK'); }
    public function fxConfirmAmount($s, int $chatId): mixed { $this->called[] = 'fxConfirmAmount'; return response('OK'); }
    public function selectExCur($s, int $chatId, string $data): mixed { $this->called[] = 'selectExCur'; return response('OK'); }
    public function selectExOutCur($s, int $chatId, string $data): mixed { $this->called[] = 'selectExOutCur'; return response('OK'); }
    public function selectMethod($s, int $chatId, string $data): mixed { $this->called[] = 'selectMethod'; return response('OK'); }
    public function selectExpCat($s, int $chatId, string $data): mixed { $this->called[] = 'selectExpCat'; return response('OK'); }
    public function confirmPayment($s, int $chatId, string $callbackId = ''): mixed { $this->called[] = 'confirmPayment'; return response('OK'); }
    public function confirmExpense($s, int $chatId, string $callbackId = ''): mixed { $this->called[] = 'confirmExpense'; return response('OK'); }
    public function confirmExchange($s, int $chatId, string $callbackId = ''): mixed { $this->called[] = 'confirmExchange'; return response('OK'); }
    public function confirmClose($s, int $chatId, string $callbackId = ''): mixed { $this->called[] = 'confirmClose'; return response('OK'); }
}
