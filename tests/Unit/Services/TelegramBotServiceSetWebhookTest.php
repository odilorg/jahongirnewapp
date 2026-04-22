<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\DTOs\ResolvedTelegramBot;
use App\DTOs\TelegramApiResult;
use App\Services\TelegramBotService;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

/**
 * Phase 10.8 — Finding B5 regression test.
 *
 * Contract: if a booking-bot webhook secret_token is configured,
 * TelegramBotService::setWebhook MUST forward it to
 * TelegramTransport::setWebhook. If it isn't forwarded, Telegram will
 * never send the X-Telegram-Bot-Api-Secret-Token header on updates and
 * the verification middleware (migration-mode) silently passes
 * requests through unenforced.
 */
final class TelegramBotServiceSetWebhookTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_set_webhook_forwards_configured_secret_token(): void
    {
        Config::set('services.telegram_booking_bot.secret_token', 'super-secret-abc123');

        $bot = new ResolvedTelegramBot(
            botId: null,
            slug: 'booking',
            name: 'Booking Bot',
            botUsername: 'j_booking_hotel_bot',
            status: \App\Enums\BotStatus::Active,
            environment: \App\Enums\BotEnvironment::Production,
            token: 'TOKEN-FAKE',
            source: 'legacy_config',
        );

        $resolver = Mockery::mock(BotResolverInterface::class);
        $resolver->shouldReceive('resolve')->with('booking')->once()->andReturn($bot);

        $transport = Mockery::mock(TelegramTransportInterface::class);
        $transport->shouldReceive('setWebhook')
            ->once()
            // $url, $secretToken — secret MUST be third argument.
            ->with($bot, 'https://example.test/hook', 'super-secret-abc123')
            ->andReturn(new TelegramApiResult(
                ok: true,
                result: ['url' => 'https://example.test/hook'],
                httpStatus: 200,
            ));

        $service = new TelegramBotService($resolver, $transport);
        $out = $service->setWebhook('https://example.test/hook');

        $this->assertTrue($out['ok']);
    }

    public function test_set_webhook_without_configured_secret_calls_transport_without_token(): void
    {
        Config::set('services.telegram_booking_bot.secret_token', null);

        $bot = new ResolvedTelegramBot(
            botId: null,
            slug: 'booking',
            name: 'Booking Bot',
            botUsername: 'j_booking_hotel_bot',
            status: \App\Enums\BotStatus::Active,
            environment: \App\Enums\BotEnvironment::Production,
            token: 'TOKEN-FAKE',
            source: 'legacy_config',
        );

        $resolver = Mockery::mock(BotResolverInterface::class);
        $resolver->shouldReceive('resolve')->with('booking')->once()->andReturn($bot);

        $transport = Mockery::mock(TelegramTransportInterface::class);
        // When there's no secret, we call the 2-arg form (url only) —
        // NOT pass an empty string, which Telegram would treat as
        // an invalid secret and reject.
        $transport->shouldReceive('setWebhook')
            ->once()
            ->withArgs(function ($actualBot, $actualUrl, ...$rest) use ($bot) {
                return $actualBot === $bot
                    && $actualUrl === 'https://example.test/hook'
                    && count($rest) === 0;
            })
            ->andReturn(new TelegramApiResult(
                ok: true,
                result: ['url' => 'https://example.test/hook'],
                httpStatus: 200,
            ));

        $service = new TelegramBotService($resolver, $transport);
        $out = $service->setWebhook('https://example.test/hook');

        $this->assertTrue($out['ok']);
    }
}
