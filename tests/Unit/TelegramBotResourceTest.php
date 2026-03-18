<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\TelegramBotResource;
use App\Filament\Resources\TelegramBotResource\Pages\ListTelegramBots;
use App\Filament\Resources\TelegramBotResource\Pages\ViewTelegramBot;
use App\Filament\Resources\TelegramBotResource\RelationManagers\AccessLogsRelationManager;
use App\Models\TelegramBot;
use PHPUnit\Framework\TestCase;

class TelegramBotResourceTest extends TestCase
{
    /** @test */
    public function resource_binds_to_telegram_bot_model(): void
    {
        $this->assertSame(TelegramBot::class, TelegramBotResource::getModel());
    }

    /** @test */
    public function resource_cannot_create(): void
    {
        $this->assertFalse(TelegramBotResource::canCreate());
    }

    /** @test */
    public function resource_has_list_and_view_pages_only(): void
    {
        $pages = TelegramBotResource::getPages();

        $this->assertArrayHasKey('index', $pages);
        $this->assertArrayHasKey('view', $pages);
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }

    /** @test */
    public function resource_has_access_logs_relation(): void
    {
        $relations = TelegramBotResource::getRelations();

        $this->assertContains(AccessLogsRelationManager::class, $relations);
    }

    /** @test */
    public function view_page_source_does_not_expose_tokens(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(ViewTelegramBot::class))->getFileName()
        );

        // Must not display decrypted tokens
        $this->assertStringNotContainsString('token_encrypted', $source);
        $this->assertStringNotContainsString('webhook_secret_encrypted', $source);
        $this->assertStringNotContainsString('getActiveToken', $source);
        $this->assertStringNotContainsString('Crypt::decrypt', $source);
        $this->assertStringNotContainsString('decryptString', $source);

        // Actions should use resolver + transport, not raw HTTP
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringNotContainsString('api.telegram.org', $source);
    }

    /** @test */
    public function resource_source_does_not_expose_tokens(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(TelegramBotResource::class))->getFileName()
        );

        $this->assertStringNotContainsString('token_encrypted', $source);
        $this->assertStringNotContainsString('getActiveToken', $source);
        $this->assertStringNotContainsString('Crypt::', $source);

        // Secret presence checks use ->exists() and !== null, not decryption
        $this->assertStringContainsString('activeSecret()->exists()', $source);
        $this->assertStringContainsString('webhook_secret_encrypted !== null', $source);
    }

    /** @test */
    public function view_page_actions_use_resolver_and_transport(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(ViewTelegramBot::class))->getFileName()
        );

        $this->assertStringContainsString('BotResolverInterface', $source);
        $this->assertStringContainsString('TelegramTransportInterface', $source);
        $this->assertStringContainsString('->resolve(', $source);
        $this->assertStringContainsString('->getMe(', $source);
        $this->assertStringContainsString('->getWebhookInfo(', $source);
    }
}
