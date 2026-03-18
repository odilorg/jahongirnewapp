<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\Telegram\BotSecretProviderInterface;
use App\Exceptions\Telegram\BotSecretUnavailableException;
use App\Models\TelegramBot;
use App\Models\TelegramBotSecret;
use App\Services\Telegram\BotSecretProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @group database
 * @group vps
 */
class BotSecretProviderTest extends TestCase
{
    use RefreshDatabase;

    private BotSecretProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new BotSecretProvider();
    }

    /** @test */
    public function interface_is_bound_in_container(): void
    {
        $resolved = $this->app->make(BotSecretProviderInterface::class);

        $this->assertInstanceOf(BotSecretProvider::class, $resolved);
    }

    /** @test */
    public function get_active_token_decrypts_successfully(): void
    {
        $bot = TelegramBot::factory()->create();
        TelegramBotSecret::factory()
            ->withToken('123456:ABC-DEF')
            ->active()
            ->version(1)
            ->create(['telegram_bot_id' => $bot->id]);

        $token = $this->provider->getActiveToken($bot);

        $this->assertSame('123456:ABC-DEF', $token);
    }

    /** @test */
    public function get_active_webhook_secret_decrypts_successfully(): void
    {
        $bot = TelegramBot::factory()->create();
        TelegramBotSecret::factory()
            ->withToken('tok')
            ->withWebhookSecret('wh-secret-xyz')
            ->active()
            ->version(1)
            ->create(['telegram_bot_id' => $bot->id]);

        $webhookSecret = $this->provider->getActiveWebhookSecret($bot);

        $this->assertSame('wh-secret-xyz', $webhookSecret);
    }

    /** @test */
    public function get_active_webhook_secret_returns_null_when_not_set(): void
    {
        $bot = TelegramBot::factory()->create();
        TelegramBotSecret::factory()
            ->withToken('tok')
            ->active()
            ->version(1)
            ->create(['telegram_bot_id' => $bot->id]);

        $this->assertNull($this->provider->getActiveWebhookSecret($bot));
    }

    /** @test */
    public function get_active_secret_version_returns_version(): void
    {
        $bot = TelegramBot::factory()->create();
        TelegramBotSecret::factory()
            ->withToken('tok')
            ->active()
            ->version(7)
            ->create(['telegram_bot_id' => $bot->id]);

        $this->assertSame(7, $this->provider->getActiveSecretVersion($bot));
    }

    /** @test */
    public function throws_when_no_active_secret_exists(): void
    {
        $bot = TelegramBot::factory()->create();

        // Create a revoked secret — should not be found
        TelegramBotSecret::factory()
            ->withToken('tok')
            ->revoked()
            ->version(1)
            ->create(['telegram_bot_id' => $bot->id]);

        $this->expectException(BotSecretUnavailableException::class);
        $this->expectExceptionMessage($bot->slug);

        $this->provider->getActiveToken($bot);
    }

    /** @test */
    public function throws_when_bot_has_no_secrets_at_all(): void
    {
        $bot = TelegramBot::factory()->create();

        $this->expectException(BotSecretUnavailableException::class);

        $this->provider->getActiveToken($bot);
    }

    /** @test */
    public function picks_highest_version_when_multiple_active(): void
    {
        $bot = TelegramBot::factory()->create();

        TelegramBotSecret::factory()
            ->withToken('old-token')
            ->active()
            ->version(1)
            ->create(['telegram_bot_id' => $bot->id]);

        TelegramBotSecret::factory()
            ->withToken('new-token')
            ->active()
            ->version(2)
            ->create(['telegram_bot_id' => $bot->id]);

        // activeSecret() uses latestOfMany('version')
        $token = $this->provider->getActiveToken($bot);

        $this->assertSame('new-token', $token);
    }

    /** @test */
    public function ignores_pending_secrets(): void
    {
        $bot = TelegramBot::factory()->create();

        TelegramBotSecret::factory()
            ->withToken('pending-token')
            ->version(1)
            ->create([
                'telegram_bot_id' => $bot->id,
                // Default factory status is 'pending'
            ]);

        $this->expectException(BotSecretUnavailableException::class);

        $this->provider->getActiveToken($bot);
    }
}
