<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\ResolvedTelegramBot;
use App\Enums\BotStatus;
use App\Exceptions\Telegram\BotNotFoundException;
use App\Exceptions\Telegram\BotSecretUnavailableException;
use App\Services\Telegram\LegacyConfigBotAdapter;
use Tests\TestCase;

class LegacyConfigBotAdapterTest extends TestCase
{
    private LegacyConfigBotAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new LegacyConfigBotAdapter();
    }

    /** @test */
    public function resolves_owner_alert_bot_from_config(): void
    {
        config(['services.owner_alert_bot.token' => 'fake-owner-token']);

        $dto = $this->adapter->resolve('owner-alert');

        $this->assertInstanceOf(ResolvedTelegramBot::class, $dto);
        $this->assertSame('owner-alert', $dto->slug);
        $this->assertSame('Owner Alert Bot', $dto->name);
        $this->assertSame('fake-owner-token', $dto->token);
        $this->assertSame(BotStatus::Active, $dto->status);
        $this->assertNull($dto->botId);
        $this->assertSame(0, $dto->secretVersion);
        $this->assertTrue($dto->isLegacy());
    }

    /** @test */
    public function resolves_pos_bot_with_webhook_secret(): void
    {
        config([
            'services.telegram_pos_bot.token' => 'pos-token',
            'services.telegram_pos_bot.secret_token' => 'pos-webhook-secret',
        ]);

        $dto = $this->adapter->resolve('pos');

        $this->assertSame('pos-token', $dto->token);
        $this->assertSame('pos-webhook-secret', $dto->webhookSecret);
    }

    /** @test */
    public function resolves_kitchen_bot_with_null_webhook_secret(): void
    {
        config(['services.kitchen_bot.token' => 'kitchen-tok']);

        $dto = $this->adapter->resolve('kitchen');

        $this->assertNull($dto->webhookSecret);
    }

    /** @test */
    public function throws_bot_not_found_for_unknown_slug(): void
    {
        $this->expectException(BotNotFoundException::class);
        $this->expectExceptionMessage('unknown-bot');

        $this->adapter->resolve('unknown-bot');
    }

    /** @test */
    public function throws_secret_unavailable_when_config_empty(): void
    {
        config(['services.owner_alert_bot.token' => '']);

        $this->expectException(BotSecretUnavailableException::class);

        $this->adapter->resolve('owner-alert');
    }

    /** @test */
    public function throws_secret_unavailable_when_config_null(): void
    {
        config(['services.owner_alert_bot.token' => null]);

        $this->expectException(BotSecretUnavailableException::class);

        $this->adapter->resolve('owner-alert');
    }

    /** @test */
    public function try_resolve_returns_null_on_failure(): void
    {
        $this->assertNull($this->adapter->tryResolve('nonexistent'));
    }

    /** @test */
    public function try_resolve_returns_dto_on_success(): void
    {
        config(['services.cashier_bot.token' => 'cashier-tok']);

        $dto = $this->adapter->tryResolve('cashier');

        $this->assertNotNull($dto);
        $this->assertSame('cashier', $dto->slug);
    }

    /** @test */
    public function known_slugs_returns_all_legacy_slugs(): void
    {
        $slugs = LegacyConfigBotAdapter::knownSlugs();

        $this->assertContains('owner-alert', $slugs);
        $this->assertContains('driver-guide', $slugs);
        $this->assertContains('pos', $slugs);
        $this->assertContains('booking', $slugs);
        $this->assertContains('cashier', $slugs);
        $this->assertContains('housekeeping', $slugs);
        $this->assertContains('kitchen', $slugs);
        $this->assertContains('main', $slugs);
        $this->assertCount(8, $slugs);
    }

    /** @test */
    public function all_eight_bots_resolve_when_configured(): void
    {
        config([
            'services.owner_alert_bot.token' => 'tok1',
            'services.driver_guide_bot.token' => 'tok2',
            'services.telegram_pos_bot.token' => 'tok3',
            'services.telegram_booking_bot.token' => 'tok4',
            'services.cashier_bot.token' => 'tok5',
            'services.housekeeping_bot.token' => 'tok6',
            'services.kitchen_bot.token' => 'tok7',
            'services.telegram.bot_token' => 'tok8',
        ]);

        foreach (LegacyConfigBotAdapter::knownSlugs() as $slug) {
            $dto = $this->adapter->resolve($slug);
            $this->assertTrue($dto->isLegacy());
            $this->assertNotEmpty($dto->token);
        }
    }

    /** @test */
    public function webhook_secret_is_null_when_config_empty(): void
    {
        config([
            'services.driver_guide_bot.token' => 'tok',
            'services.driver_guide_bot.webhook_secret' => '',
        ]);

        $dto = $this->adapter->resolve('driver-guide');

        $this->assertNull($dto->webhookSecret);
    }
}
