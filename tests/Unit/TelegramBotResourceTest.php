<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Filament\Resources\TelegramBotResource;
use App\Filament\Resources\TelegramBotResource\Pages\CreateTelegramBot;
use App\Filament\Resources\TelegramBotResource\Pages\EditTelegramBot;
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
    public function resource_has_all_crud_pages(): void
    {
        $pages = TelegramBotResource::getPages();

        $this->assertArrayHasKey('index', $pages);
        $this->assertArrayHasKey('create', $pages);
        $this->assertArrayHasKey('view', $pages);
        $this->assertArrayHasKey('edit', $pages);
    }

    /** @test */
    public function resource_has_access_logs_relation(): void
    {
        $relations = TelegramBotResource::getRelations();

        $this->assertContains(AccessLogsRelationManager::class, $relations);
    }

    /** @test */
    public function view_page_source_does_not_reveal_tokens(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(ViewTelegramBot::class))->getFileName()
        );

        // Must not decrypt or reveal existing token values
        $this->assertStringNotContainsString('getActiveToken', $source);
        $this->assertStringNotContainsString('Crypt::decrypt', $source);
        $this->assertStringNotContainsString('decryptString', $source);

        // Crypt::encryptString is allowed (rotate writes new token)
        // token_encrypted is allowed (rotate sets the encrypted column)

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

    // ──────────────────────────────────────────────
    // Authorization
    // ──────────────────────────────────────────────

    /** @test */
    public function resource_has_super_admin_authorization(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(TelegramBotResource::class))->getFileName()
        );

        $this->assertStringContainsString('canViewAny', $source);
        $this->assertStringContainsString("hasRole('super_admin')", $source);
    }

    /** @test */
    public function view_page_actions_have_visibility_authorization(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(ViewTelegramBot::class))->getFileName()
        );

        $this->assertStringContainsString('->visible(', $source);
        $this->assertStringContainsString("hasRole('super_admin')", $source);
    }

    // ──────────────────────────────────────────────
    // Test Connection mutation documentation
    // ──────────────────────────────────────────────

    /** @test */
    public function test_connection_documents_username_update_side_effect(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(ViewTelegramBot::class))->getFileName()
        );

        $this->assertStringContainsString('username has changed', $source);
        $this->assertStringContainsString('updated in the database', $source);
        $this->assertStringContainsString('bot_username !== $username', $source);
    }

    // ──────────────────────────────────────────────
    // Secret lifecycle actions
    // ──────────────────────────────────────────────

    /** @test */
    public function view_page_has_rotate_token_action(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(ViewTelegramBot::class))->getFileName()
        );

        $this->assertStringContainsString("'rotateToken'", $source);
        $this->assertStringContainsString('Crypt::encryptString', $source);
        $this->assertStringContainsString('markRevoked', $source);
        // Token input uses password field — never shown in plaintext
        $this->assertStringContainsString('->password()', $source);
    }

    /** @test */
    public function view_page_has_status_lifecycle_actions(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(ViewTelegramBot::class))->getFileName()
        );

        $this->assertStringContainsString("'disableBot'", $source);
        $this->assertStringContainsString("'enableBot'", $source);
        $this->assertStringContainsString("'revokeBot'", $source);
        $this->assertStringContainsString('BotStatus::Disabled', $source);
        $this->assertStringContainsString('BotStatus::Active', $source);
        $this->assertStringContainsString('BotStatus::Revoked', $source);
    }

    /** @test */
    public function rotate_token_does_not_reveal_existing_token(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(ViewTelegramBot::class))->getFileName()
        );

        // Must not have any getActiveToken / decryptString for revealing
        $this->assertStringNotContainsString('getActiveToken', $source);
        $this->assertStringNotContainsString('decryptString', $source);
        // Token input is password-type with optional reveal toggle
        $this->assertStringContainsString('->revealable()', $source);
    }

    /** @test */
    public function revoke_action_warns_about_irreversibility(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(ViewTelegramBot::class))->getFileName()
        );

        $this->assertStringContainsString('CANNOT be undone', $source);
        $this->assertStringContainsString('Permanently Revoke', $source);
    }

    // ──────────────────────────────────────────────
    // Create / Edit
    // ──────────────────────────────────────────────

    /** @test */
    public function create_page_encrypts_token_after_save(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(CreateTelegramBot::class))->getFileName()
        );

        $this->assertStringContainsString('Crypt::encryptString', $source);
        $this->assertStringContainsString('token_encrypted', $source);
        $this->assertStringContainsString('afterCreate', $source);
        // Token is removed from form data before model create
        $this->assertStringContainsString("unset(\$data['initial_token']", $source);
    }

    /** @test */
    public function create_form_uses_password_fields_for_secrets(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(TelegramBotResource::class))->getFileName()
        );

        $this->assertStringContainsString("'initial_token'", $source);
        $this->assertStringContainsString("'initial_webhook_secret'", $source);
        $this->assertStringContainsString('->password()', $source);
    }

    /** @test */
    public function slug_is_disabled_on_edit(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(TelegramBotResource::class))->getFileName()
        );

        // Slug field is disabled when record exists (edit mode)
        $this->assertStringContainsString('->disabled(fn (?TelegramBot $record): bool => $record !== null)', $source);
    }

    /** @test */
    public function token_section_only_visible_on_create(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(TelegramBotResource::class))->getFileName()
        );

        // Token section hidden on edit
        $this->assertStringContainsString('->visible(fn (?TelegramBot $record): bool => $record === null)', $source);
    }

    /** @test */
    public function edit_page_records_updated_by(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(EditTelegramBot::class))->getFileName()
        );

        $this->assertStringContainsString('updated_by', $source);
        $this->assertStringContainsString('auth()->id()', $source);
    }
}
