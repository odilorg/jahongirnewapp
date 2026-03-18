<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Enums\SecretStatus;
use PHPUnit\Framework\TestCase;

class TelegramBotEnumTest extends TestCase
{
    // ──────────────────────────────────────────────
    // BotStatus
    // ──────────────────────────────────────────────

    /** @test */
    public function bot_status_values(): void
    {
        $this->assertSame('active', BotStatus::Active->value);
        $this->assertSame('disabled', BotStatus::Disabled->value);
        $this->assertSame('revoked', BotStatus::Revoked->value);
    }

    /** @test */
    public function bot_status_is_usable(): void
    {
        $this->assertTrue(BotStatus::Active->isUsable());
        $this->assertFalse(BotStatus::Disabled->isUsable());
        $this->assertFalse(BotStatus::Revoked->isUsable());
    }

    /** @test */
    public function bot_status_labels(): void
    {
        $this->assertSame('Active', BotStatus::Active->label());
        $this->assertSame('Disabled', BotStatus::Disabled->label());
        $this->assertSame('Revoked', BotStatus::Revoked->label());
    }

    /** @test */
    public function bot_status_colors(): void
    {
        $this->assertSame('success', BotStatus::Active->color());
        $this->assertSame('warning', BotStatus::Disabled->color());
        $this->assertSame('danger', BotStatus::Revoked->color());
    }

    /** @test */
    public function bot_status_from_string(): void
    {
        $this->assertSame(BotStatus::Active, BotStatus::from('active'));
        $this->assertSame(BotStatus::Disabled, BotStatus::from('disabled'));
        $this->assertSame(BotStatus::Revoked, BotStatus::from('revoked'));
    }

    /** @test */
    public function bot_status_try_from_invalid_returns_null(): void
    {
        $this->assertNull(BotStatus::tryFrom('invalid'));
    }

    // ──────────────────────────────────────────────
    // BotEnvironment
    // ──────────────────────────────────────────────

    /** @test */
    public function bot_environment_values(): void
    {
        $this->assertSame('production', BotEnvironment::Production->value);
        $this->assertSame('staging', BotEnvironment::Staging->value);
        $this->assertSame('development', BotEnvironment::Development->value);
    }

    /** @test */
    public function bot_environment_labels(): void
    {
        $this->assertSame('Production', BotEnvironment::Production->label());
        $this->assertSame('Staging', BotEnvironment::Staging->label());
        $this->assertSame('Development', BotEnvironment::Development->label());
    }

    /** @test */
    public function bot_environment_colors(): void
    {
        $this->assertSame('danger', BotEnvironment::Production->color());
        $this->assertSame('warning', BotEnvironment::Staging->color());
        $this->assertSame('gray', BotEnvironment::Development->color());
    }

    // ──────────────────────────────────────────────
    // SecretStatus
    // ──────────────────────────────────────────────

    /** @test */
    public function secret_status_values(): void
    {
        $this->assertSame('active', SecretStatus::Active->value);
        $this->assertSame('pending', SecretStatus::Pending->value);
        $this->assertSame('revoked', SecretStatus::Revoked->value);
    }

    /** @test */
    public function secret_status_is_usable(): void
    {
        $this->assertTrue(SecretStatus::Active->isUsable());
        $this->assertFalse(SecretStatus::Pending->isUsable());
        $this->assertFalse(SecretStatus::Revoked->isUsable());
    }

    /** @test */
    public function secret_status_labels(): void
    {
        $this->assertSame('Active', SecretStatus::Active->label());
        $this->assertSame('Pending', SecretStatus::Pending->label());
        $this->assertSame('Revoked', SecretStatus::Revoked->label());
    }

    /** @test */
    public function secret_status_colors(): void
    {
        $this->assertSame('success', SecretStatus::Active->color());
        $this->assertSame('warning', SecretStatus::Pending->color());
        $this->assertSame('danger', SecretStatus::Revoked->color());
    }
}
