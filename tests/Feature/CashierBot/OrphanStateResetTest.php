<?php

declare(strict_types=1);

namespace Tests\Feature\CashierBot;

use App\Http\Controllers\CashierBotController;
use App\Models\TelegramPosSession;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Defensive fallback: if a session arrives in an unrecognized state — e.g.
 * a state that was removed in a deploy, or any future drift — the bot must
 * reset to main_menu, clear stale `data`, and notify the user.
 */
final class OrphanStateResetTest extends TestCase
{
    use DatabaseTransactions;

    private TestableOrphanResetController $controller;
    private int $chatId = 999_001;
    private TelegramPosSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->controller = $this->app->make(TestableOrphanResetController::class);

        $user = User::factory()->create();
        $this->session = TelegramPosSession::create([
            'chat_id' => $this->chatId,
            'user_id' => $user->id,
            'state'   => 'payment_exchange_rate', // a now-removed legacy state
            'data'    => ['applied_rate' => 12800, 'fx_presentation' => ['stale' => true]],
        ]);
    }

    public function test_orphan_state_resets_to_main_menu_and_clears_data(): void
    {
        $this->controller->callHandleState($this->session, $this->chatId, 'whatever the user typed');

        $fresh = $this->session->fresh();
        $this->assertSame('main_menu', $fresh->state);
        $this->assertNull($fresh->data);

        $this->assertNotEmpty($this->controller->sentMessages, 'User must receive a reset message');
        $this->assertSame('Сессия устарела, начните заново.', $this->controller->sentMessages[0]);
    }

    public function test_orphan_state_reset_logs_old_state_for_observability(): void
    {
        Log::spy();

        $this->controller->callHandleState($this->session, $this->chatId, 'x');

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $msg, array $ctx): bool {
                return $msg === 'CashierBot: orphan session state reset'
                    && ($ctx['old_state'] ?? null) === 'payment_exchange_rate'
                    && ($ctx['chat_id']  ?? null) === 999_001
                    && in_array('applied_rate', $ctx['data_keys'] ?? [], true);
            })
            ->once();
    }

    public function test_unknown_arbitrary_state_also_resets(): void
    {
        // Forward-compatibility: any unrecognized state must reset.
        $this->session->update(['state' => 'totally_made_up_state', 'data' => ['x' => 1]]);

        $this->controller->callHandleState($this->session->fresh(), $this->chatId, 'hello');

        $this->assertSame('main_menu', $this->session->fresh()->state);
        $this->assertNull($this->session->fresh()->data);
    }
}

/**
 * Test seam: exposes handleState() and silences I/O side-effects.
 */
class TestableOrphanResetController extends CashierBotController
{
    public array $sentMessages = [];

    public function send(int $chatId, string $text, mixed $kb = null, string $type = 'reply'): void
    {
        $this->sentMessages[] = $text;
    }

    public function showMainMenu(int $chatId, $session): mixed
    {
        return null;
    }

    public function callHandleState($s, int $chatId, string $text): mixed
    {
        return $this->handleState($s, $chatId, $text);
    }
}
