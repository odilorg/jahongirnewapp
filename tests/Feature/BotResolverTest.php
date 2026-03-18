<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Telegram\BotAuditLoggerInterface;
use App\Contracts\Telegram\BotResolverInterface;
use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Exceptions\Telegram\BotDisabledException;
use App\Exceptions\Telegram\BotEnvironmentMismatchException;
use App\Exceptions\Telegram\BotNotFoundException;
use App\Exceptions\Telegram\BotSecretUnavailableException;
use App\Models\TelegramBot;
use App\Models\TelegramBotSecret;
use App\Services\Telegram\BotResolver;
use App\Services\Telegram\BotSecretProvider;
use App\Services\Telegram\FallbackBotResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @group database
 * @group vps
 */
class BotResolverTest extends TestCase
{
    use RefreshDatabase;

    private BotResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new BotResolver(
            secretProvider: new BotSecretProvider(),
            auditLogger: $this->app->make(BotAuditLoggerInterface::class),
        );
    }

    // ──────────────────────────────────────────────
    // Happy path
    // ──────────────────────────────────────────────

    /** @test */
    public function resolves_active_bot_with_active_secret(): void
    {
        $bot = $this->createUsableBot('test-bot');

        $dto = $this->resolver->resolve('test-bot');

        $this->assertSame($bot->id, $dto->botId);
        $this->assertSame('test-bot', $dto->slug);
        $this->assertSame('test-token-value', $dto->token);
        $this->assertSame('database', $dto->source);
        $this->assertFalse($dto->isLegacy());
    }

    /** @test */
    public function resolves_webhook_secret_when_present(): void
    {
        $this->createUsableBot('wh-bot', webhookSecret: 'my-wh-secret');

        $dto = $this->resolver->resolve('wh-bot');

        $this->assertSame('my-wh-secret', $dto->webhookSecret);
    }

    /** @test */
    public function resolves_null_webhook_secret_when_absent(): void
    {
        $this->createUsableBot('no-wh-bot');

        $dto = $this->resolver->resolve('no-wh-bot');

        $this->assertNull($dto->webhookSecret);
    }

    /** @test */
    public function resolve_updates_last_used_at(): void
    {
        $bot = $this->createUsableBot('used-bot');
        $this->assertNull($bot->last_used_at);

        $this->resolver->resolve('used-bot');

        $bot->refresh();
        $this->assertNotNull($bot->last_used_at);
    }

    /** @test */
    public function resolve_carries_secret_version(): void
    {
        $this->createUsableBot('versioned-bot', secretVersion: 5);

        $dto = $this->resolver->resolve('versioned-bot');

        $this->assertSame(5, $dto->secretVersion);
    }

    // ──────────────────────────────────────────────
    // Enforcement: existence
    // ──────────────────────────────────────────────

    /** @test */
    public function throws_bot_not_found_for_unknown_slug(): void
    {
        $this->expectException(BotNotFoundException::class);
        $this->expectExceptionMessage('nonexistent');

        $this->resolver->resolve('nonexistent');
    }

    // ──────────────────────────────────────────────
    // Enforcement: status
    // ──────────────────────────────────────────────

    /** @test */
    public function throws_bot_disabled_for_disabled_bot(): void
    {
        TelegramBot::factory()->create([
            'slug' => 'disabled-bot',
            'status' => BotStatus::Disabled,
            'environment' => BotEnvironment::Development,
        ]);

        $this->expectException(BotDisabledException::class);
        $this->expectExceptionMessage('disabled-bot');

        $this->resolver->resolve('disabled-bot');
    }

    /** @test */
    public function throws_bot_disabled_for_revoked_bot(): void
    {
        TelegramBot::factory()->create([
            'slug' => 'revoked-bot',
            'status' => BotStatus::Revoked,
            'environment' => BotEnvironment::Development,
        ]);

        $this->expectException(BotDisabledException::class);

        $this->resolver->resolve('revoked-bot');
    }

    // ──────────────────────────────────────────────
    // Enforcement: environment
    // ──────────────────────────────────────────────

    /** @test */
    public function throws_environment_mismatch(): void
    {
        // App is 'testing' which maps to Development.
        // Create a bot for Production — should fail.
        $bot = TelegramBot::factory()->create([
            'slug' => 'prod-bot',
            'status' => BotStatus::Active,
            'environment' => BotEnvironment::Production,
        ]);

        TelegramBotSecret::factory()
            ->withToken('tok')
            ->active()
            ->version(1)
            ->create(['telegram_bot_id' => $bot->id]);

        $this->expectException(BotEnvironmentMismatchException::class);
        $this->expectExceptionMessage('prod-bot');

        $this->resolver->resolve('prod-bot');
    }

    // ──────────────────────────────────────────────
    // Enforcement: secret availability
    // ──────────────────────────────────────────────

    /** @test */
    public function throws_secret_unavailable_when_no_active_secret(): void
    {
        TelegramBot::factory()->create([
            'slug' => 'no-secret-bot',
            'status' => BotStatus::Active,
            'environment' => BotEnvironment::Development,
        ]);

        $this->expectException(BotSecretUnavailableException::class);

        $this->resolver->resolve('no-secret-bot');
    }

    // ──────────────────────────────────────────────
    // tryResolve
    // ──────────────────────────────────────────────

    /** @test */
    public function try_resolve_returns_dto_on_success(): void
    {
        $this->createUsableBot('try-bot');

        $dto = $this->resolver->tryResolve('try-bot');

        $this->assertNotNull($dto);
        $this->assertSame('try-bot', $dto->slug);
    }

    /** @test */
    public function try_resolve_returns_null_on_failure(): void
    {
        $this->assertNull($this->resolver->tryResolve('nonexistent'));
    }

    /** @test */
    public function try_resolve_returns_null_for_disabled_bot(): void
    {
        TelegramBot::factory()->create([
            'slug' => 'disabled-try',
            'status' => BotStatus::Disabled,
            'environment' => BotEnvironment::Development,
        ]);

        $this->assertNull($this->resolver->tryResolve('disabled-try'));
    }

    // ──────────────────────────────────────────────
    // Container binding (FallbackBotResolver)
    // ──────────────────────────────────────────────

    /** @test */
    public function interface_resolves_to_fallback_resolver(): void
    {
        $resolved = $this->app->make(BotResolverInterface::class);

        $this->assertInstanceOf(FallbackBotResolver::class, $resolved);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function createUsableBot(
        string $slug,
        string $token = 'test-token-value',
        ?string $webhookSecret = null,
        int $secretVersion = 1,
    ): TelegramBot {
        $bot = TelegramBot::factory()->create([
            'slug' => $slug,
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'status' => BotStatus::Active,
            'environment' => BotEnvironment::Development, // 'testing' maps to Development
        ]);

        $factory = TelegramBotSecret::factory()
            ->withToken($token)
            ->active()
            ->version($secretVersion);

        if ($webhookSecret !== null) {
            $factory = $factory->withWebhookSecret($webhookSecret);
        }

        $factory->create(['telegram_bot_id' => $bot->id]);

        return $bot;
    }
}
