<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Telegram\BotAuditLoggerInterface;
use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Exceptions\Telegram\BotDisabledException;
use App\Exceptions\Telegram\BotNotFoundException;
use App\Models\TelegramBot;
use App\Models\TelegramBotSecret;
use App\Services\Telegram\BotResolver;
use App\Services\Telegram\BotSecretProvider;
use App\Services\Telegram\FallbackBotResolver;
use App\Services\Telegram\LegacyConfigBotAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @group database
 * @group vps
 */
class FallbackBotResolverTest extends TestCase
{
    use RefreshDatabase;

    private FallbackBotResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new FallbackBotResolver(
            databaseResolver: new BotResolver(
                secretProvider: new BotSecretProvider(),
                auditLogger: $this->app->make(BotAuditLoggerInterface::class),
            ),
            legacyAdapter: new LegacyConfigBotAdapter(),
        );
    }

    /** @test */
    public function prefers_database_when_bot_exists(): void
    {
        // Bot in database AND in legacy config
        $bot = TelegramBot::factory()->create([
            'slug' => 'owner-alert',
            'status' => BotStatus::Active,
            'environment' => BotEnvironment::Development,
        ]);
        TelegramBotSecret::factory()
            ->withToken('db-token')
            ->active()
            ->version(1)
            ->create(['telegram_bot_id' => $bot->id]);

        config(['services.owner_alert_bot.token' => 'legacy-token']);

        $dto = $this->resolver->resolve('owner-alert');

        $this->assertSame('db-token', $dto->token);
        $this->assertSame('database', $dto->source);
        $this->assertFalse($dto->isLegacy());
    }

    /** @test */
    public function falls_back_to_legacy_when_not_in_database(): void
    {
        config(['services.owner_alert_bot.token' => 'legacy-fallback-token']);

        $dto = $this->resolver->resolve('owner-alert');

        $this->assertSame('legacy-fallback-token', $dto->token);
        $this->assertTrue($dto->isLegacy());
    }

    /** @test */
    public function throws_when_neither_database_nor_legacy_has_slug(): void
    {
        $this->expectException(BotNotFoundException::class);
        $this->expectExceptionMessage('totally-unknown');

        $this->resolver->resolve('totally-unknown');
    }

    /** @test */
    public function does_not_fallback_when_bot_exists_but_disabled(): void
    {
        // Bot in database as disabled — should throw BotDisabledException,
        // NOT fall through to legacy config.
        TelegramBot::factory()->create([
            'slug' => 'owner-alert',
            'status' => BotStatus::Disabled,
            'environment' => BotEnvironment::Development,
        ]);
        config(['services.owner_alert_bot.token' => 'legacy-token']);

        $this->expectException(BotDisabledException::class);

        $this->resolver->resolve('owner-alert');
    }

    /** @test */
    public function try_resolve_returns_dto_from_database(): void
    {
        $bot = TelegramBot::factory()->create([
            'slug' => 'try-db',
            'status' => BotStatus::Active,
            'environment' => BotEnvironment::Development,
        ]);
        TelegramBotSecret::factory()
            ->withToken('tok')
            ->active()
            ->version(1)
            ->create(['telegram_bot_id' => $bot->id]);

        $dto = $this->resolver->tryResolve('try-db');

        $this->assertNotNull($dto);
        $this->assertSame('database', $dto->source);
    }

    /** @test */
    public function try_resolve_falls_back_to_legacy(): void
    {
        config(['services.kitchen_bot.token' => 'kitchen-tok']);

        $dto = $this->resolver->tryResolve('kitchen');

        $this->assertNotNull($dto);
        $this->assertTrue($dto->isLegacy());
    }

    /** @test */
    public function try_resolve_returns_null_when_both_fail(): void
    {
        $this->assertNull($this->resolver->tryResolve('nonexistent'));
    }

    /** @test */
    public function try_resolve_returns_null_for_disabled_bot(): void
    {
        TelegramBot::factory()->create([
            'slug' => 'disabled-fb',
            'status' => BotStatus::Disabled,
            'environment' => BotEnvironment::Development,
        ]);

        $this->assertNull($this->resolver->tryResolve('disabled-fb'));
    }
}
