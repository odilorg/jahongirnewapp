<?php

declare(strict_types=1);

namespace Tests\Feature\CashierBot;

use App\Actions\CashierBot\Handlers\HandleAuthAction;
use App\Models\TelegramPosSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parity pin for HandleAuthAction — extracted from
 * CashierBotController::handleAuth.
 */
final class HandleAuthActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_phone_returns_not_found_reply(): void
    {
        $result = app(HandleAuthAction::class)->execute(500_100, ['phone_number' => '+998900000001']);

        $this->assertFalse($result['ok']);
        $this->assertSame('Номер не найден. Обратитесь к руководству.', $result['reply']['text']);
        $this->assertNull(TelegramPosSession::where('chat_id', 500_100)->first());
    }

    public function test_matching_phone_creates_session_and_welcomes_user(): void
    {
        $user = User::factory()->create(['name' => 'Alice', 'phone_number' => '+998901234567']);

        $result = app(HandleAuthAction::class)->execute(500_101, ['phone_number' => '+998901234567']);

        $this->assertTrue($result['ok']);
        $this->assertSame('Добро пожаловать, Alice!', $result['reply']['text']);
        $this->assertSame(['remove_keyboard' => true], $result['reply']['kb']);
        $this->assertSame('reply', $result['reply']['type']);

        $session = TelegramPosSession::where('chat_id', 500_101)->first();
        $this->assertNotNull($session);
        $this->assertSame($user->id, $session->user_id);
        $this->assertSame('main_menu', $session->state);
        $this->assertNull($session->data);
    }

    public function test_last_nine_digits_match_ignores_country_code_mismatch(): void
    {
        // User stored without country-code prefix — typical for staff
        // entered locally before the bot rollout.
        $user = User::factory()->create(['name' => 'Bob', 'phone_number' => '901234567']);

        // Telegram contact carries the international prefix.
        $result = app(HandleAuthAction::class)->execute(500_102, ['phone_number' => '+998901234567']);

        $this->assertTrue($result['ok']);
        $this->assertSame($user->id, $result['session']->user_id);
    }

    public function test_repeat_auth_upserts_existing_session(): void
    {
        $user = User::factory()->create(['phone_number' => '+998977777777']);
        TelegramPosSession::create([
            'chat_id' => 500_103,
            'user_id' => $user->id,
            'state'   => 'shift_count_uzs',
            'data'    => ['shift_id' => 99],
        ]);

        $result = app(HandleAuthAction::class)->execute(500_103, ['phone_number' => '+998977777777']);

        $this->assertTrue($result['ok']);
        $session = TelegramPosSession::where('chat_id', 500_103)->first();
        // Auth resets state to main_menu and wipes any in-flight data.
        $this->assertSame('main_menu', $session->state);
        $this->assertNull($session->data);
        $this->assertSame(1, TelegramPosSession::where('chat_id', 500_103)->count());
    }

    public function test_malformed_or_empty_phone_number_is_rejected(): void
    {
        $result = app(HandleAuthAction::class)->execute(500_104, ['phone_number' => '']);

        $this->assertFalse($result['ok']);
        $this->assertSame('Номер не найден. Обратитесь к руководству.', $result['reply']['text']);
    }
}
