<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\DTOs\ResolvedTelegramBot;
use App\DTOs\TelegramApiResult;
use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Http\Controllers\HousekeepingBotController;
use App\Http\Controllers\KitchenBotController;
use Mockery;
use Tests\TestCase;

class HousekeepingKitchenTransportIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Beds24BookingService requires this config to not crash on construction
        config(['services.beds24.api_v2_refresh_token' => 'test-token']);
    }

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

    /** @test */
    public function housekeeping_send_resolves_slug_and_calls_transport(): void
    {
        $bot = $this->makeFakeBot('housekeeping');

        $resolver = Mockery::mock(BotResolverInterface::class);
        $resolver->shouldReceive('resolve')->with('housekeeping')->once()->andReturn($bot);

        $transport = Mockery::mock(TelegramTransportInterface::class);
        $transport->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($b, $chatId, $text, $extra) => $b === $bot && $chatId === 55555 && $text === 'Test HK' && $extra['parse_mode'] === 'HTML')
            ->andReturn($this->successResult());

        $this->app->instance(BotResolverInterface::class, $resolver);
        $this->app->instance(TelegramTransportInterface::class, $transport);

        $controller = $this->app->make(HousekeepingBotController::class);
        $ref = new \ReflectionMethod($controller, 'send');
        $ref->invoke($controller, 55555, 'Test HK');
    }

    /** @test */
    public function kitchen_send_resolves_slug_and_calls_transport(): void
    {
        $bot = $this->makeFakeBot('kitchen');

        $resolver = Mockery::mock(BotResolverInterface::class);
        $resolver->shouldReceive('resolve')->with('kitchen')->once()->andReturn($bot);

        $transport = Mockery::mock(TelegramTransportInterface::class);
        $transport->shouldReceive('sendMessage')
            ->once()
            ->withArgs(fn ($b, $chatId, $text, $extra) => $b === $bot && $chatId === 66666 && $text === 'Test Kitchen')
            ->andReturn($this->successResult());

        $this->app->instance(BotResolverInterface::class, $resolver);
        $this->app->instance(TelegramTransportInterface::class, $transport);

        $controller = $this->app->make(KitchenBotController::class);
        $ref = new \ReflectionMethod($controller, 'send');
        $ref->invoke($controller, 66666, 'Test Kitchen');
    }

    /** @test */
    public function kitchen_edit_message_resolves_and_calls_transport(): void
    {
        $bot = $this->makeFakeBot('kitchen');

        $resolver = Mockery::mock(BotResolverInterface::class);
        $resolver->shouldReceive('resolve')->with('kitchen')->once()->andReturn($bot);

        $transport = Mockery::mock(TelegramTransportInterface::class);
        $transport->shouldReceive('call')
            ->once()
            ->withArgs(fn ($b, $method, $params) => $b === $bot && $method === 'editMessageText' && $params['message_id'] === 42)
            ->andReturn($this->successResult());

        $this->app->instance(BotResolverInterface::class, $resolver);
        $this->app->instance(TelegramTransportInterface::class, $transport);

        $controller = $this->app->make(KitchenBotController::class);
        $ref = new \ReflectionMethod($controller, 'editMessage');
        $ref->invoke($controller, 66666, 42, 'Updated text');
    }

    /** @test */
    public function housekeeping_acb_resolves_and_calls_transport(): void
    {
        $bot = $this->makeFakeBot('housekeeping');

        $resolver = Mockery::mock(BotResolverInterface::class);
        $resolver->shouldReceive('resolve')->with('housekeeping')->once()->andReturn($bot);

        $transport = Mockery::mock(TelegramTransportInterface::class);
        $transport->shouldReceive('call')
            ->once()
            ->withArgs(fn ($b, $method, $params) => $b === $bot && $method === 'answerCallbackQuery' && $params['callback_query_id'] === 'hk-cb-1')
            ->andReturn($this->successResult());

        $this->app->instance(BotResolverInterface::class, $resolver);
        $this->app->instance(TelegramTransportInterface::class, $transport);

        $controller = $this->app->make(HousekeepingBotController::class);
        $ref = new \ReflectionMethod($controller, 'aCb');
        $ref->invoke($controller, 'hk-cb-1');
    }
}
