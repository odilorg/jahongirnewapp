<?php

declare(strict_types=1);

namespace Tests\Feature\BookingBot;

use App\Jobs\ProcessBookingMessage;
use App\Models\User;
use App\Services\BookingIntentParser;
use App\Services\StaffAuthorizationService;
use App\Services\TelegramBotService;
use App\Services\TelegramKeyboardService;
use App\Support\BookingBot\HelpContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Feature tests for the /help command route.
 *
 * Contract:
 *  - Authorized user + "help" or "/help" → HelpContent body + back
 *    button, parser NEVER invoked.
 *  - Authorized user + greeting (hi, menu, /start) → welcome card
 *    with main menu, NOT the help body.
 *  - Unauthorized user + "/help" → phone-share prompt, help body
 *    NEVER leaked pre-auth.
 */
final class HelpCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_authorized_user_help_command_returns_help_content_with_back_button(): void
    {
        $staff = User::factory()->create(['name' => 'Op']);
        $auth = Mockery::mock(StaffAuthorizationService::class);
        $auth->shouldReceive('verifyTelegramUser')->once()->andReturn($staff);
        $this->app->instance(StaffAuthorizationService::class, $auth);

        // Parser must NOT be called for /help.
        $parser = Mockery::mock(BookingIntentParser::class);
        $parser->shouldNotReceive('parse');
        $this->app->instance(BookingIntentParser::class, $parser);

        $captured = null;
        $capturedOpts = null;
        $telegram = Mockery::mock(TelegramBotService::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function (int|string $chatId, string $text, array $opts = []) use (&$captured, &$capturedOpts): array {
                $captured = $text;
                $capturedOpts = $opts;
                return ['ok' => true];
            });
        $this->app->instance(TelegramBotService::class, $telegram);

        $backButton = ['inline_keyboard' => [[['text' => '« Back', 'callback_data' => 'main_menu']]]];
        $keyboard = Mockery::mock(TelegramKeyboardService::class);
        $keyboard->shouldReceive('getBackButton')->once()->andReturn($backButton);
        $keyboard->shouldReceive('formatForApi')->once()->with($backButton)->andReturn($backButton);
        $keyboard->shouldReceive('getMainMenu')->zeroOrMoreTimes();
        $this->app->instance(TelegramKeyboardService::class, $keyboard);

        $update = [
            'update_id' => 1,
            'message'   => [
                'message_id' => 1,
                'chat'       => ['id' => 999],
                'from'       => ['id' => 1, 'username' => 'op'],
                'text'       => '/help',
                'date'       => time(),
            ],
        ];

        (new ProcessBookingMessage($update))->handle(
            $this->app->make(StaffAuthorizationService::class),
            $this->app->make(BookingIntentParser::class),
            $this->app->make(TelegramBotService::class),
            $this->app->make(TelegramKeyboardService::class),
        );

        $this->assertSame(HelpContent::render(), $captured);
        $this->assertArrayHasKey('reply_markup', $capturedOpts ?? []);
        $this->assertSame($backButton, $capturedOpts['reply_markup']);
    }

    public function test_plain_help_keyword_also_routes_to_help(): void
    {
        $staff = User::factory()->create();
        $auth = Mockery::mock(StaffAuthorizationService::class);
        $auth->shouldReceive('verifyTelegramUser')->once()->andReturn($staff);
        $this->app->instance(StaffAuthorizationService::class, $auth);

        $parser = Mockery::mock(BookingIntentParser::class);
        $parser->shouldNotReceive('parse');
        $this->app->instance(BookingIntentParser::class, $parser);

        $captured = null;
        $telegram = Mockery::mock(TelegramBotService::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function ($_, $text) use (&$captured) {
                $captured = $text;
                return ['ok' => true];
            });
        $this->app->instance(TelegramBotService::class, $telegram);

        $keyboard = Mockery::mock(TelegramKeyboardService::class);
        $keyboard->shouldReceive('getBackButton')->andReturn([]);
        $keyboard->shouldReceive('formatForApi')->andReturn([]);
        $this->app->instance(TelegramKeyboardService::class, $keyboard);

        $update = [
            'update_id' => 2,
            'message' => [
                'message_id' => 2,
                'chat' => ['id' => 999],
                'from' => ['id' => 1],
                'text' => '  Help  ', // padded + cased — must still route
                'date' => time(),
            ],
        ];

        (new ProcessBookingMessage($update))->handle(
            $this->app->make(StaffAuthorizationService::class),
            $this->app->make(BookingIntentParser::class),
            $this->app->make(TelegramBotService::class),
            $this->app->make(TelegramKeyboardService::class),
        );

        $this->assertSame(HelpContent::render(), $captured);
    }

    public function test_greeting_returns_welcome_not_help_body(): void
    {
        $staff = User::factory()->create();
        $auth = Mockery::mock(StaffAuthorizationService::class);
        $auth->shouldReceive('verifyTelegramUser')->once()->andReturn($staff);
        $this->app->instance(StaffAuthorizationService::class, $auth);

        $parser = Mockery::mock(BookingIntentParser::class);
        $parser->shouldNotReceive('parse');
        $this->app->instance(BookingIntentParser::class, $parser);

        $captured = null;
        $telegram = Mockery::mock(TelegramBotService::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function ($_, $text) use (&$captured) {
                $captured = $text;
                return ['ok' => true];
            });
        $this->app->instance(TelegramBotService::class, $telegram);

        $keyboard = Mockery::mock(TelegramKeyboardService::class);
        $keyboard->shouldReceive('getMainMenu')->once()->andReturn(['inline_keyboard' => []]);
        $keyboard->shouldReceive('formatForApi')->once()->andReturn(['inline_keyboard' => []]);
        $keyboard->shouldReceive('getBackButton')->zeroOrMoreTimes();
        $this->app->instance(TelegramKeyboardService::class, $keyboard);

        $update = [
            'update_id' => 3,
            'message' => [
                'message_id' => 3,
                'chat' => ['id' => 999],
                'from' => ['id' => 1],
                'text' => 'hi',
                'date' => time(),
            ],
        ];

        (new ProcessBookingMessage($update))->handle(
            $this->app->make(StaffAuthorizationService::class),
            $this->app->make(BookingIntentParser::class),
            $this->app->make(TelegramBotService::class),
            $this->app->make(TelegramKeyboardService::class),
        );

        $this->assertIsString($captured);
        $this->assertStringNotContainsString('Common mistakes', $captured ?? '');
        $this->assertStringContainsString('Booking Bot', $captured ?? '');
        $this->assertStringContainsString('/help', $captured ?? '');
    }

    public function test_unauthorized_user_help_does_not_leak_help_content(): void
    {
        // No user created → verifyTelegramUser returns null
        $realAuth = $this->app->make(StaffAuthorizationService::class);
        $this->app->instance(StaffAuthorizationService::class, $realAuth);

        $parser = Mockery::mock(BookingIntentParser::class);
        $parser->shouldNotReceive('parse');
        $this->app->instance(BookingIntentParser::class, $parser);

        $captured = null;
        $telegram = Mockery::mock(TelegramBotService::class);
        $telegram->shouldReceive('sendMessage')
            ->once()
            ->andReturnUsing(function ($_, $text) use (&$captured) {
                $captured = $text;
                return ['ok' => true];
            });
        $this->app->instance(TelegramBotService::class, $telegram);

        $keyboard = Mockery::mock(TelegramKeyboardService::class);
        $keyboard->shouldReceive('getMainMenu')->zeroOrMoreTimes();
        $keyboard->shouldReceive('getBackButton')->zeroOrMoreTimes();
        $keyboard->shouldReceive('formatForApi')->zeroOrMoreTimes();
        $this->app->instance(TelegramKeyboardService::class, $keyboard);

        $update = [
            'update_id' => 4,
            'message' => [
                'message_id' => 4,
                'chat' => ['id' => 999],
                'from' => ['id' => 9999, 'username' => 'stranger'],
                'text' => '/help',
                'date' => time(),
            ],
        ];

        (new ProcessBookingMessage($update))->handle(
            $this->app->make(StaffAuthorizationService::class),
            $this->app->make(BookingIntentParser::class),
            $this->app->make(TelegramBotService::class),
            $this->app->make(TelegramKeyboardService::class),
        );

        $this->assertIsString($captured);
        // Should be the phone-share prompt, NOT help content.
        $this->assertStringContainsString('Authorization Required', $captured);
        $this->assertStringNotContainsString('Common mistakes', $captured);
        $this->assertStringNotContainsString('Create booking', $captured);
    }
}
