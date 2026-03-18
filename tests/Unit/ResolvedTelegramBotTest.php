<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\DTOs\ResolvedTelegramBot;
use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use PHPUnit\Framework\TestCase;

class ResolvedTelegramBotTest extends TestCase
{
    /** @test */
    public function it_constructs_with_all_fields(): void
    {
        $dto = new ResolvedTelegramBot(
            botId: 42,
            slug: 'owner-alert',
            name: 'Owner Alert Bot',
            botUsername: 'owner_alert_bot',
            status: BotStatus::Active,
            environment: BotEnvironment::Production,
            token: 'fake-token-123',
            webhookSecret: 'webhook-secret-456',
            secretVersion: 3,
            source: 'database',
        );

        $this->assertSame(42, $dto->botId);
        $this->assertSame('owner-alert', $dto->slug);
        $this->assertSame('Owner Alert Bot', $dto->name);
        $this->assertSame('owner_alert_bot', $dto->botUsername);
        $this->assertSame(BotStatus::Active, $dto->status);
        $this->assertSame(BotEnvironment::Production, $dto->environment);
        $this->assertSame('fake-token-123', $dto->token);
        $this->assertSame('webhook-secret-456', $dto->webhookSecret);
        $this->assertSame(3, $dto->secretVersion);
        $this->assertSame('database', $dto->source);
    }

    /** @test */
    public function it_has_sensible_defaults(): void
    {
        $dto = new ResolvedTelegramBot(
            botId: 1,
            slug: 'test',
            name: 'Test Bot',
            botUsername: null,
            status: BotStatus::Active,
            environment: BotEnvironment::Development,
            token: 'tok',
        );

        $this->assertNull($dto->webhookSecret);
        $this->assertSame(1, $dto->secretVersion);
        $this->assertSame('database', $dto->source);
    }

    /** @test */
    public function is_legacy_returns_true_for_legacy_config(): void
    {
        $dto = new ResolvedTelegramBot(
            botId: 0,
            slug: 'owner-alert',
            name: 'Owner Alert Bot',
            botUsername: null,
            status: BotStatus::Active,
            environment: BotEnvironment::Production,
            token: 'tok',
            source: 'legacy_config',
        );

        $this->assertTrue($dto->isLegacy());
    }

    /** @test */
    public function is_legacy_returns_false_for_database(): void
    {
        $dto = new ResolvedTelegramBot(
            botId: 42,
            slug: 'owner-alert',
            name: 'Owner Alert Bot',
            botUsername: null,
            status: BotStatus::Active,
            environment: BotEnvironment::Production,
            token: 'tok',
            source: 'database',
        );

        $this->assertFalse($dto->isLegacy());
    }

    /** @test */
    public function dto_is_readonly(): void
    {
        $dto = new ResolvedTelegramBot(
            botId: 1,
            slug: 'test',
            name: 'Test',
            botUsername: null,
            status: BotStatus::Active,
            environment: BotEnvironment::Development,
            token: 'tok',
        );

        $reflection = new \ReflectionClass($dto);
        $this->assertTrue($reflection->isReadOnly());
    }

    /** @test */
    public function dto_is_not_json_serializable(): void
    {
        $this->assertFalse(
            (new \ReflectionClass(ResolvedTelegramBot::class))
                ->implementsInterface(\JsonSerializable::class)
        );
    }
}
