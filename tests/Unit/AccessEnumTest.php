<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\AccessAction;
use App\Enums\AccessResult;
use PHPUnit\Framework\TestCase;

class AccessEnumTest extends TestCase
{
    // ──────────────────────────────────────────────
    // AccessAction
    // ──────────────────────────────────────────────

    /** @test */
    public function access_action_values(): void
    {
        $this->assertSame('token_read', AccessAction::TokenRead->value);
        $this->assertSame('webhook_secret_read', AccessAction::WebhookSecretRead->value);
        $this->assertSame('message_sent', AccessAction::MessageSent->value);
        $this->assertSame('webhook_set', AccessAction::WebhookSet->value);
        $this->assertSame('webhook_received', AccessAction::WebhookReceived->value);
        $this->assertSame('token_rotated', AccessAction::TokenRotated->value);
        $this->assertSame('token_revealed', AccessAction::TokenRevealed->value);
        $this->assertSame('bot_created', AccessAction::BotCreated->value);
        $this->assertSame('bot_updated', AccessAction::BotUpdated->value);
        $this->assertSame('bot_disabled', AccessAction::BotDisabled->value);
        $this->assertSame('bot_revoked', AccessAction::BotRevoked->value);
        $this->assertSame('error', AccessAction::Error->value);
    }

    /** @test */
    public function access_action_labels(): void
    {
        $this->assertSame('Token Read', AccessAction::TokenRead->label());
        $this->assertSame('Error', AccessAction::Error->label());
        $this->assertSame('Token Rotated', AccessAction::TokenRotated->label());
    }

    /** @test */
    public function access_action_is_secret_access(): void
    {
        $this->assertTrue(AccessAction::TokenRead->isSecretAccess());
        $this->assertTrue(AccessAction::WebhookSecretRead->isSecretAccess());
        $this->assertTrue(AccessAction::TokenRevealed->isSecretAccess());

        $this->assertFalse(AccessAction::MessageSent->isSecretAccess());
        $this->assertFalse(AccessAction::WebhookSet->isSecretAccess());
        $this->assertFalse(AccessAction::Error->isSecretAccess());
        $this->assertFalse(AccessAction::BotCreated->isSecretAccess());
    }

    /** @test */
    public function access_action_from_string(): void
    {
        $this->assertSame(AccessAction::TokenRead, AccessAction::from('token_read'));
        $this->assertNull(AccessAction::tryFrom('nonexistent'));
    }

    // ──────────────────────────────────────────────
    // AccessResult
    // ──────────────────────────────────────────────

    /** @test */
    public function access_result_values(): void
    {
        $this->assertSame('success', AccessResult::Success->value);
        $this->assertSame('denied', AccessResult::Denied->value);
        $this->assertSame('not_found', AccessResult::NotFound->value);
        $this->assertSame('error', AccessResult::Error->value);
    }

    /** @test */
    public function access_result_labels(): void
    {
        $this->assertSame('Success', AccessResult::Success->label());
        $this->assertSame('Denied', AccessResult::Denied->label());
        $this->assertSame('Not Found', AccessResult::NotFound->label());
        $this->assertSame('Error', AccessResult::Error->label());
    }

    /** @test */
    public function access_result_is_failure(): void
    {
        $this->assertFalse(AccessResult::Success->isFailure());
        $this->assertTrue(AccessResult::Denied->isFailure());
        $this->assertTrue(AccessResult::NotFound->isFailure());
        $this->assertTrue(AccessResult::Error->isFailure());
    }

    /** @test */
    public function access_result_from_string(): void
    {
        $this->assertSame(AccessResult::Success, AccessResult::from('success'));
        $this->assertNull(AccessResult::tryFrom('nonexistent'));
    }
}
