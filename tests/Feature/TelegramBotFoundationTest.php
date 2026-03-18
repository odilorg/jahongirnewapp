<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Enums\SecretStatus;
use App\Models\TelegramBot;
use App\Models\TelegramBotSecret;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

/**
 * Database-dependent tests for TelegramBot and TelegramBotSecret models.
 *
 * These tests require RefreshDatabase, which runs all 208 migrations.
 * Pre-existing migration issues (virtualAs in create_drivers_table) prevent
 * running on SQLite or a fresh local PG. Run on VPS where migrations are
 * already applied, or after fixing the drivers migration.
 *
 * Local: php artisan test --filter=TelegramBotEnumTest --filter=TelegramBotExceptionTest
 * VPS:   php artisan test --filter=TelegramBotFoundationTest
 *
 * @group database
 * @group vps
 */
class TelegramBotFoundationTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────
    // Enum Casting
    // ──────────────────────────────────────────────

    /** @test */
    public function bot_status_casts_to_enum(): void
    {
        $bot = TelegramBot::factory()->create(['status' => 'active']);

        $this->assertInstanceOf(BotStatus::class, $bot->status);
        $this->assertSame(BotStatus::Active, $bot->status);
    }

    /** @test */
    public function bot_environment_casts_to_enum(): void
    {
        $bot = TelegramBot::factory()->create(['environment' => 'staging']);

        $this->assertInstanceOf(BotEnvironment::class, $bot->environment);
        $this->assertSame(BotEnvironment::Staging, $bot->environment);
    }

    /** @test */
    public function secret_status_casts_to_enum(): void
    {
        $bot = TelegramBot::factory()->create();
        $secret = TelegramBotSecret::factory()
            ->for($bot)
            ->active()
            ->create();

        $this->assertInstanceOf(SecretStatus::class, $secret->status);
        $this->assertSame(SecretStatus::Active, $secret->status);
    }

    // ──────────────────────────────────────────────
    // Relationships
    // ──────────────────────────────────────────────

    /** @test */
    public function bot_has_many_secrets(): void
    {
        $bot = TelegramBot::factory()->create();
        TelegramBotSecret::factory()->for($bot)->version(1)->create();
        TelegramBotSecret::factory()->for($bot)->version(2)->create();

        $this->assertCount(2, $bot->secrets);
        $this->assertInstanceOf(TelegramBotSecret::class, $bot->secrets->first());
    }

    /** @test */
    public function bot_has_one_active_secret(): void
    {
        $bot = TelegramBot::factory()->create();

        TelegramBotSecret::factory()->for($bot)->version(1)->revoked()->create();
        $activeSecret = TelegramBotSecret::factory()->for($bot)->version(2)->active()->create();
        TelegramBotSecret::factory()->for($bot)->version(3)->create(); // pending

        $resolved = $bot->activeSecret;

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->is($activeSecret));
        $this->assertSame(SecretStatus::Active, $resolved->status);
    }

    /** @test */
    public function active_secret_returns_null_when_none_active(): void
    {
        $bot = TelegramBot::factory()->create();
        TelegramBotSecret::factory()->for($bot)->version(1)->revoked()->create();

        $this->assertNull($bot->activeSecret);
    }

    /** @test */
    public function secret_belongs_to_bot(): void
    {
        $bot = TelegramBot::factory()->create();
        $secret = TelegramBotSecret::factory()->for($bot)->create();

        $this->assertTrue($secret->bot->is($bot));
    }

    /** @test */
    public function bot_belongs_to_creator(): void
    {
        $user = User::factory()->create();
        $bot = TelegramBot::factory()->create(['created_by' => $user->id]);

        $this->assertTrue($bot->creator->is($user));
    }

    /** @test */
    public function deleting_bot_cascades_to_secrets(): void
    {
        $bot = TelegramBot::factory()->create();
        TelegramBotSecret::factory()->for($bot)->version(1)->create();
        TelegramBotSecret::factory()->for($bot)->version(2)->create();

        $botId = $bot->id;
        $bot->forceDelete();

        $this->assertDatabaseMissing('telegram_bot_secrets', ['telegram_bot_id' => $botId]);
    }

    // ──────────────────────────────────────────────
    // Soft Deletes
    // ──────────────────────────────────────────────

    /** @test */
    public function soft_delete_hides_bot_from_default_queries(): void
    {
        $bot = TelegramBot::factory()->create(['slug' => 'test-soft-delete']);

        $bot->delete();

        $this->assertNull(TelegramBot::where('slug', 'test-soft-delete')->first());
        $this->assertNotNull(TelegramBot::withTrashed()->where('slug', 'test-soft-delete')->first());
    }

    /** @test */
    public function soft_deleted_bot_can_be_restored(): void
    {
        $bot = TelegramBot::factory()->create();
        $bot->delete();
        $bot->restore();

        $this->assertNull($bot->fresh()->deleted_at);
    }

    /** @test */
    public function soft_deleted_bot_retains_secrets(): void
    {
        $bot = TelegramBot::factory()->create();
        $secret = TelegramBotSecret::factory()->for($bot)->version(1)->active()->create();

        $bot->delete();

        // Secrets are still in DB (bot is soft-deleted, not force-deleted)
        $this->assertDatabaseHas('telegram_bot_secrets', ['id' => $secret->id]);
    }

    // ──────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────

    /** @test */
    public function active_scope_filters_correctly(): void
    {
        TelegramBot::factory()->active()->create();
        TelegramBot::factory()->disabled()->create();
        TelegramBot::factory()->revoked()->create();

        $active = TelegramBot::active()->get();

        $this->assertCount(1, $active);
        $this->assertSame(BotStatus::Active, $active->first()->status);
    }

    /** @test */
    public function for_environment_scope_filters_correctly(): void
    {
        TelegramBot::factory()->forEnvironment(BotEnvironment::Production)->create();
        TelegramBot::factory()->forEnvironment(BotEnvironment::Staging)->create();

        $prod = TelegramBot::forEnvironment(BotEnvironment::Production)->get();

        $this->assertCount(1, $prod);
        $this->assertSame(BotEnvironment::Production, $prod->first()->environment);
    }

    /** @test */
    public function by_slug_scope_finds_bot(): void
    {
        TelegramBot::factory()->create(['slug' => 'owner_alert']);
        TelegramBot::factory()->create(['slug' => 'cashier']);

        $bot = TelegramBot::bySlug('owner_alert')->first();

        $this->assertNotNull($bot);
        $this->assertSame('owner_alert', $bot->slug);
    }

    // ──────────────────────────────────────────────
    // Domain Methods
    // ──────────────────────────────────────────────

    /** @test */
    public function is_usable_returns_true_only_for_active(): void
    {
        $active = TelegramBot::factory()->active()->create();
        $disabled = TelegramBot::factory()->disabled()->create();
        $revoked = TelegramBot::factory()->revoked()->create();

        $this->assertTrue($active->isUsable());
        $this->assertFalse($disabled->isUsable());
        $this->assertFalse($revoked->isUsable());
    }

    /** @test */
    public function mark_used_updates_timestamp(): void
    {
        $bot = TelegramBot::factory()->create(['last_used_at' => null]);

        $bot->markUsed();

        $this->assertNotNull($bot->fresh()->last_used_at);
    }

    /** @test */
    public function mark_error_records_details(): void
    {
        $bot = TelegramBot::factory()->create();

        $bot->markError('429', 'Rate limited');

        $fresh = $bot->fresh();
        $this->assertSame('429', $fresh->last_error_code);
        $this->assertSame('Rate limited', $fresh->last_error_summary);
        $this->assertNotNull($fresh->last_error_at);
    }

    /** @test */
    public function secret_mark_revoked_updates_status_and_timestamp(): void
    {
        $secret = TelegramBotSecret::factory()->active()->create();

        $secret->markRevoked();

        $fresh = $secret->fresh();
        $this->assertSame(SecretStatus::Revoked, $fresh->status);
        $this->assertNotNull($fresh->revoked_at);
    }

    /** @test */
    public function secret_activate_updates_status_and_timestamp(): void
    {
        $secret = TelegramBotSecret::factory()->create(); // pending by default

        $secret->activate();

        $fresh = $secret->fresh();
        $this->assertSame(SecretStatus::Active, $fresh->status);
        $this->assertNotNull($fresh->activated_at);
    }

    // ──────────────────────────────────────────────
    // Secret Serialization Safety
    // ──────────────────────────────────────────────

    /** @test */
    public function encrypted_columns_are_hidden_from_serialization(): void
    {
        $secret = TelegramBotSecret::factory()->active()->create();

        $array = $secret->toArray();
        $json = $secret->toJson();

        $this->assertArrayNotHasKey('token_encrypted', $array);
        $this->assertArrayNotHasKey('webhook_secret_encrypted', $array);
        $this->assertStringNotContainsString('token_encrypted', $json);
        $this->assertStringNotContainsString('webhook_secret_encrypted', $json);
    }

    /** @test */
    public function encrypted_token_is_stored_as_ciphertext(): void
    {
        $plainToken = 'test-bot-token-12345:AAHdqTcvCH1vGW';

        $bot = TelegramBot::factory()->create();
        $secret = TelegramBotSecret::factory()
            ->for($bot)
            ->withToken($plainToken)
            ->active()
            ->create();

        // Raw DB value must NOT be the plaintext
        $raw = $secret->getRawOriginal('token_encrypted');
        $this->assertNotSame($plainToken, $raw);

        // But can be decrypted back (this is what BotSecretProvider will do)
        $this->assertSame($plainToken, Crypt::decryptString($raw));
    }

    // ──────────────────────────────────────────────
    // Slug Uniqueness
    // ──────────────────────────────────────────────

    /** @test */
    public function slug_must_be_unique(): void
    {
        TelegramBot::factory()->create(['slug' => 'unique-slug']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        TelegramBot::factory()->create(['slug' => 'unique-slug']);
    }

    /** @test */
    public function version_must_be_unique_per_bot(): void
    {
        $bot = TelegramBot::factory()->create();
        TelegramBotSecret::factory()->for($bot)->version(1)->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        TelegramBotSecret::factory()->for($bot)->version(1)->create();
    }

    // ──────────────────────────────────────────────
    // Metadata JSON
    // ──────────────────────────────────────────────

    /** @test */
    public function metadata_is_cast_as_array(): void
    {
        $bot = TelegramBot::factory()->create([
            'metadata' => [
                'owner_chat_id' => '38738713',
                'webhook_url' => 'https://example.com/webhook',
            ],
        ]);

        $fresh = $bot->fresh();
        $this->assertIsArray($fresh->metadata);
        $this->assertSame('38738713', $fresh->metadata['owner_chat_id']);
    }

    /** @test */
    public function metadata_can_be_null(): void
    {
        $bot = TelegramBot::factory()->create(['metadata' => null]);

        $this->assertNull($bot->fresh()->metadata);
    }

    // ──────────────────────────────────────────────
    // Enum Behavior
    // ──────────────────────────────────────────────

    /** @test */
    public function bot_status_is_usable_method(): void
    {
        $this->assertTrue(BotStatus::Active->isUsable());
        $this->assertFalse(BotStatus::Disabled->isUsable());
        $this->assertFalse(BotStatus::Revoked->isUsable());
    }

    /** @test */
    public function secret_status_is_usable_method(): void
    {
        $this->assertTrue(SecretStatus::Active->isUsable());
        $this->assertFalse(SecretStatus::Pending->isUsable());
        $this->assertFalse(SecretStatus::Revoked->isUsable());
    }

    /** @test */
    public function bot_status_label_and_color(): void
    {
        $this->assertSame('Active', BotStatus::Active->label());
        $this->assertSame('success', BotStatus::Active->color());
        $this->assertSame('Disabled', BotStatus::Disabled->label());
        $this->assertSame('warning', BotStatus::Disabled->color());
        $this->assertSame('Revoked', BotStatus::Revoked->label());
        $this->assertSame('danger', BotStatus::Revoked->color());
    }

    /** @test */
    public function bot_environment_label_and_color(): void
    {
        $this->assertSame('Production', BotEnvironment::Production->label());
        $this->assertSame('danger', BotEnvironment::Production->color());
        $this->assertSame('Staging', BotEnvironment::Staging->label());
        $this->assertSame('warning', BotEnvironment::Staging->color());
        $this->assertSame('Development', BotEnvironment::Development->label());
        $this->assertSame('gray', BotEnvironment::Development->color());
    }
}
