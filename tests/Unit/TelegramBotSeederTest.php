<?php

declare(strict_types=1);

namespace Tests\Unit;

use Database\Seeders\TelegramBotSeeder;
use PHPUnit\Framework\TestCase;

class TelegramBotSeederTest extends TestCase
{
    /** @test */
    public function seeder_defines_all_eight_bots(): void
    {
        $reflection = new \ReflectionClass(TelegramBotSeeder::class);
        $bots = $reflection->getConstant('BOTS');

        $this->assertCount(8, $bots);

        $slugs = array_column($bots, 'slug');
        $this->assertEqualsCanonicalizing(
            ['owner-alert', 'driver-guide', 'pos', 'booking', 'cashier', 'housekeeping', 'kitchen', 'main'],
            $slugs,
        );
    }

    /** @test */
    public function seeder_source_does_not_contain_hardcoded_tokens(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(TelegramBotSeeder::class))->getFileName()
        );

        $this->assertStringNotContainsString('BOT_TOKEN', $source);
        $this->assertStringNotContainsString('api.telegram.org', $source);
        // Tokens come from config() at runtime, not hardcoded
        $this->assertStringContainsString("config(\$botDef['token_config']", $source);
    }

    /** @test */
    public function seeder_encrypts_tokens(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(TelegramBotSeeder::class))->getFileName()
        );

        $this->assertStringContainsString('Crypt::encryptString', $source);
        $this->assertStringContainsString('token_encrypted', $source);
        $this->assertStringContainsString('webhook_secret_encrypted', $source);
    }

    /** @test */
    public function seeder_checks_for_existing_bots(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(TelegramBotSeeder::class))->getFileName()
        );

        // Must use withTrashed to catch soft-deleted bots too
        $this->assertStringContainsString('withTrashed()', $source);
        $this->assertStringContainsString('already exists', $source);
    }

    /** @test */
    public function each_bot_has_required_fields(): void
    {
        $reflection = new \ReflectionClass(TelegramBotSeeder::class);
        $bots = $reflection->getConstant('BOTS');

        foreach ($bots as $bot) {
            $this->assertArrayHasKey('slug', $bot, "Bot missing slug");
            $this->assertArrayHasKey('name', $bot, "Bot {$bot['slug']} missing name");
            $this->assertArrayHasKey('token_config', $bot, "Bot {$bot['slug']} missing token_config");
            $this->assertNotEmpty($bot['slug']);
            $this->assertNotEmpty($bot['name']);
            $this->assertNotEmpty($bot['token_config']);
        }
    }
}
