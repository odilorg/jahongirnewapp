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
        $this->assertSame(0, $dto->secretVersion);
        $this->assertSame('database', $dto->source);
    }

    /** @test */
    public function bot_id_is_nullable_for_legacy(): void
    {
        $dto = new ResolvedTelegramBot(
            botId: null,
            slug: 'owner-alert',
            name: 'Owner Alert Bot',
            botUsername: null,
            status: BotStatus::Active,
            environment: BotEnvironment::Production,
            token: 'tok',
            source: 'legacy_config',
        );

        $this->assertNull($dto->botId);
        $this->assertTrue($dto->isLegacy());
    }

    /** @test */
    public function is_legacy_returns_true_for_legacy_config(): void
    {
        $dto = new ResolvedTelegramBot(
            botId: null,
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
        $reflection = new \ReflectionClass(ResolvedTelegramBot::class);
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

    /** @test */
    public function debug_info_redacts_token_and_webhook_secret(): void
    {
        $dto = new ResolvedTelegramBot(
            botId: 42,
            slug: 'test',
            name: 'Test Bot',
            botUsername: null,
            status: BotStatus::Active,
            environment: BotEnvironment::Development,
            token: 'REAL-SECRET-TOKEN-123',
            webhookSecret: 'REAL-WEBHOOK-SECRET-456',
        );

        $debug = $dto->__debugInfo();

        $this->assertSame('********', $debug['token']);
        $this->assertSame('********', $debug['webhookSecret']);
        $this->assertSame(42, $debug['botId']);
        $this->assertSame('test', $debug['slug']);
    }

    /** @test */
    public function debug_info_shows_null_webhook_secret_as_null(): void
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

        $debug = $dto->__debugInfo();

        $this->assertNull($debug['webhookSecret']);
    }

    /** @test */
    public function serialize_throws_logic_exception(): void
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

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must not be serialized');

        serialize($dto);
    }

    /** @test */
    public function var_dump_does_not_contain_real_token(): void
    {
        $dto = new ResolvedTelegramBot(
            botId: 1,
            slug: 'test',
            name: 'Test',
            botUsername: null,
            status: BotStatus::Active,
            environment: BotEnvironment::Development,
            token: 'SUPER-SECRET-TOKEN-XYZ',
            webhookSecret: 'SUPER-SECRET-WH-ABC',
        );

        ob_start();
        var_dump($dto);
        $output = ob_get_clean();

        $this->assertStringNotContainsString('SUPER-SECRET-TOKEN-XYZ', $output);
        $this->assertStringNotContainsString('SUPER-SECRET-WH-ABC', $output);
        $this->assertStringContainsString('********', $output);
    }
}
