<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\CashierBotController;
use App\Http\Controllers\OwnerBotController;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that CashierBotController and OwnerBotController no longer
 * hold bot tokens or construct raw Telegram API URLs.
 */
class CashierBotTokenRemovalTest extends TestCase
{
    // ──────────────────────────────────────────────
    // CashierBotController
    // ──────────────────────────────────────────────

    /** @test */
    public function cashier_controller_has_no_token_property(): void
    {
        $props = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            (new \ReflectionClass(CashierBotController::class))->getProperties(),
        );

        $this->assertNotContains('botToken', $props);
        $this->assertNotContains('apiBase', $props);
    }

    /** @test */
    public function cashier_controller_source_has_no_token_references(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(CashierBotController::class))->getFileName()
        );

        $this->assertStringNotContainsString('$this->botToken', $source);
        $this->assertStringNotContainsString('$this->apiBase', $source);
        $this->assertStringNotContainsString('api.telegram.org', $source);
        $this->assertStringNotContainsString("Http::timeout", $source);
        $this->assertStringNotContainsString("Http::post", $source);
    }

    /** @test */
    public function cashier_controller_injects_resolver_and_transport(): void
    {
        $props = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            (new \ReflectionClass(CashierBotController::class))->getProperties(),
        );

        $this->assertContains('botResolver', $props);
        $this->assertContains('transport', $props);
    }

    // ──────────────────────────────────────────────
    // OwnerBotController
    // ──────────────────────────────────────────────

    /** @test */
    public function owner_controller_has_no_token_property(): void
    {
        $props = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            (new \ReflectionClass(OwnerBotController::class))->getProperties(),
        );

        $this->assertNotContains('botToken', $props);
        $this->assertNotContains('apiBase', $props);
    }

    /** @test */
    public function owner_controller_source_has_no_token_references(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(OwnerBotController::class))->getFileName()
        );

        $this->assertStringNotContainsString('$this->botToken', $source);
        $this->assertStringNotContainsString('$this->apiBase', $source);
        $this->assertStringNotContainsString('api.telegram.org', $source);
        $this->assertStringNotContainsString("Http::timeout", $source);
        $this->assertStringNotContainsString("Http::post", $source);
        $this->assertStringNotContainsString('CASHIER_BOT_TOKEN', $source);
        $this->assertStringNotContainsString('OWNER_ALERT_BOT_TOKEN', $source);
    }

    /** @test */
    public function owner_controller_injects_resolver_and_transport(): void
    {
        $props = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            (new \ReflectionClass(OwnerBotController::class))->getProperties(),
        );

        $this->assertContains('botResolver', $props);
        $this->assertContains('transport', $props);
    }

    // ──────────────────────────────────────────────
    // Cross-controller: no direct config token access
    // ──────────────────────────────────────────────

    /** @test */
    public function neither_controller_reads_token_from_config(): void
    {
        $cashierSource = file_get_contents(
            (new \ReflectionClass(CashierBotController::class))->getFileName()
        );
        $ownerSource = file_get_contents(
            (new \ReflectionClass(OwnerBotController::class))->getFileName()
        );

        // Neither should read bot tokens from config
        $this->assertStringNotContainsString("config('services.cashier_bot.token'", $cashierSource);
        $this->assertStringNotContainsString("config('services.owner_alert_bot.token'", $cashierSource);
        $this->assertStringNotContainsString("config('services.cashier_bot.token'", $ownerSource);
        $this->assertStringNotContainsString("config('services.owner_alert_bot.token'", $ownerSource);
    }
}
