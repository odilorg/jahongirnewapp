<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Api\InternalBotController;
use App\Http\Middleware\AuthenticateServiceKey;
use App\Models\TelegramServiceKey;
use Tests\TestCase;

class InternalProxyApiTest extends TestCase
{
    /** @test */
    public function controller_does_not_expose_tokens(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(InternalBotController::class))->getFileName()
        );

        $this->assertStringNotContainsString('->token', $source);
        $this->assertStringNotContainsString('getActiveToken', $source);
        $this->assertStringNotContainsString('decryptString', $source);
        $this->assertStringNotContainsString('api.telegram.org', $source);
    }

    /** @test */
    public function controller_uses_resolver_and_transport(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(InternalBotController::class))->getFileName()
        );

        $this->assertStringContainsString('BotResolverInterface', $source);
        $this->assertStringContainsString('TelegramTransportInterface', $source);
        $this->assertStringContainsString('->resolve($slug)', $source);
    }

    /** @test */
    public function middleware_logs_without_exposing_full_key(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(AuthenticateServiceKey::class))->getFileName()
        );

        // Should log prefix, not full key
        $this->assertStringContainsString('key_prefix', $source);
        $this->assertStringContainsString("substr(\$keyValue, 0, 12)", $source);
        // Should not log the full key value
        $this->assertStringNotContainsString("'key' =>", $source);
    }

    /** @test */
    public function service_key_stores_hash_not_plaintext(): void
    {
        $key = TelegramServiceKey::generateKey();

        $this->assertStringStartsWith('tgsk_', $key['plaintext']);
        $this->assertSame(64, strlen($key['hash'])); // SHA-256
        $this->assertSame(12, strlen($key['prefix']));
        $this->assertNotEquals($key['plaintext'], $key['hash']);
    }

    /** @test */
    public function service_key_model_has_no_plaintext_storage(): void
    {
        $fillable = (new TelegramServiceKey())->getFillable();

        $this->assertNotContains('key', $fillable);
        $this->assertNotContains('plaintext', $fillable);
        $this->assertContains('key_hash', $fillable);
        $this->assertContains('key_prefix', $fillable);
    }

    /** @test */
    public function slug_allowlist_check(): void
    {
        $key = new TelegramServiceKey();
        $key->allowed_slugs = ['cashier', 'owner-alert'];
        $key->is_active = true;

        $this->assertTrue($key->canAccessSlug('cashier'));
        $this->assertTrue($key->canAccessSlug('owner-alert'));
        $this->assertFalse($key->canAccessSlug('kitchen'));
    }

    /** @test */
    public function null_allowlist_means_all_allowed(): void
    {
        $key = new TelegramServiceKey();
        $key->allowed_slugs = null;
        $key->allowed_actions = null;
        $key->is_active = true;

        $this->assertTrue($key->canAccessSlug('anything'));
        $this->assertTrue($key->canPerformAction('anything'));
    }

    /** @test */
    public function action_allowlist_check(): void
    {
        $key = new TelegramServiceKey();
        $key->allowed_actions = ['send-message', 'get-me'];

        $this->assertTrue($key->canPerformAction('send-message'));
        $this->assertTrue($key->canPerformAction('get-me'));
        $this->assertFalse($key->canPerformAction('set-webhook'));
    }

    /** @test */
    public function expired_key_is_invalid(): void
    {
        $key = new TelegramServiceKey();
        $key->is_active = true;
        $key->expires_at = \Carbon\Carbon::now()->subHour();

        $this->assertFalse($key->isValid());
    }

    /** @test */
    public function inactive_key_is_invalid(): void
    {
        $key = new TelegramServiceKey();
        $key->is_active = false;
        $key->expires_at = null;

        $this->assertFalse($key->isValid());
    }

    /** @test */
    public function active_non_expired_key_is_valid(): void
    {
        $key = new TelegramServiceKey();
        $key->is_active = true;
        $key->expires_at = null;

        $this->assertTrue($key->isValid());
    }
}
