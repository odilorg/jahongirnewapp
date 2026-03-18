<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Exceptions\Telegram\BotDisabledException;
use App\Exceptions\Telegram\BotEnvironmentMismatchException;
use App\Exceptions\Telegram\BotNotFoundException;
use App\Exceptions\Telegram\BotSecretUnavailableException;
use PHPUnit\Framework\TestCase;

class TelegramBotExceptionTest extends TestCase
{
    /** @test */
    public function bot_not_found_exception_contains_slug(): void
    {
        $e = new BotNotFoundException('owner_alert');

        $this->assertSame('owner_alert', $e->slug);
        $this->assertStringContainsString('owner_alert', $e->getMessage());
        $this->assertStringContainsString('not found', $e->getMessage());
    }

    /** @test */
    public function bot_disabled_exception_contains_slug_and_status(): void
    {
        $e = new BotDisabledException('cashier', BotStatus::Revoked);

        $this->assertSame('cashier', $e->slug);
        $this->assertSame(BotStatus::Revoked, $e->status);
        $this->assertStringContainsString('cashier', $e->getMessage());
        $this->assertStringContainsString('revoked', $e->getMessage());
    }

    /** @test */
    public function bot_environment_mismatch_exception_contains_details(): void
    {
        $e = new BotEnvironmentMismatchException(
            'pos',
            BotEnvironment::Staging,
            'production'
        );

        $this->assertSame('pos', $e->slug);
        $this->assertSame(BotEnvironment::Staging, $e->botEnvironment);
        $this->assertSame('production', $e->appEnvironment);
        $this->assertStringContainsString('staging', $e->getMessage());
        $this->assertStringContainsString('production', $e->getMessage());
    }

    /** @test */
    public function bot_secret_unavailable_exception_contains_slug(): void
    {
        $e = new BotSecretUnavailableException('kitchen');

        $this->assertSame('kitchen', $e->slug);
        $this->assertStringContainsString('kitchen', $e->getMessage());
        $this->assertStringContainsString('No active secret', $e->getMessage());
    }

    /** @test */
    public function all_exceptions_extend_runtime_exception(): void
    {
        $this->assertInstanceOf(\RuntimeException::class, new BotNotFoundException('x'));
        $this->assertInstanceOf(\RuntimeException::class, new BotDisabledException('x', BotStatus::Disabled));
        $this->assertInstanceOf(\RuntimeException::class, new BotEnvironmentMismatchException('x', BotEnvironment::Production, 'testing'));
        $this->assertInstanceOf(\RuntimeException::class, new BotSecretUnavailableException('x'));
    }
}
