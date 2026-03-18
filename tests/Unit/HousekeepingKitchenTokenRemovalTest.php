<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\HousekeepingBotController;
use App\Http\Controllers\KitchenBotController;
use PHPUnit\Framework\TestCase;

class HousekeepingKitchenTokenRemovalTest extends TestCase
{
    /** @test */
    public function housekeeping_has_no_token_property(): void
    {
        $props = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            (new \ReflectionClass(HousekeepingBotController::class))->getProperties(),
        );

        $this->assertNotContains('botToken', $props);
        $this->assertContains('botResolver', $props);
        $this->assertContains('transport', $props);
    }

    /** @test */
    public function housekeeping_source_has_no_config_token_reads(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(HousekeepingBotController::class))->getFileName()
        );

        $this->assertStringNotContainsString('$this->botToken', $source);
        $this->assertStringNotContainsString("config('services.housekeeping_bot.token'", $source);
        $this->assertStringNotContainsString("config('services.owner_alert_bot.token'", $source);
    }

    /** @test */
    public function housekeeping_file_download_uses_resolved_bot_not_stored_token(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(HousekeepingBotController::class))->getFileName()
        );

        // File download URLs should use $bot->token (from resolver), not $this->botToken
        $this->assertStringContainsString('$bot->token', $source);
        $this->assertStringNotContainsString('$this->botToken', $source);

        // getFile calls should use transport, not raw Http
        $this->assertStringContainsString("->call(\$bot, 'getFile'", $source);
    }

    /** @test */
    public function kitchen_has_no_token_property(): void
    {
        $props = array_map(
            fn (\ReflectionProperty $p) => $p->getName(),
            (new \ReflectionClass(KitchenBotController::class))->getProperties(),
        );

        $this->assertNotContains('botToken', $props);
        $this->assertContains('botResolver', $props);
        $this->assertContains('transport', $props);
    }

    /** @test */
    public function kitchen_source_has_no_token_references(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(KitchenBotController::class))->getFileName()
        );

        $this->assertStringNotContainsString('$this->botToken', $source);
        $this->assertStringNotContainsString("config('services.kitchen_bot.token'", $source);
        $this->assertStringNotContainsString('api.telegram.org', $source);
        $this->assertStringNotContainsString('Http::timeout', $source);
        $this->assertStringNotContainsString('Http::post', $source);
    }

    /** @test */
    public function kitchen_uses_correct_slug(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(KitchenBotController::class))->getFileName()
        );

        $this->assertStringContainsString("resolve('kitchen')", $source);
    }

    /** @test */
    public function housekeeping_uses_correct_slug(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(HousekeepingBotController::class))->getFileName()
        );

        $this->assertStringContainsString("resolve('housekeeping')", $source);
    }
}
