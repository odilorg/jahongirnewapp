<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BotEnvironment;
use App\Enums\BotStatus;
use App\Enums\SecretStatus;
use App\Models\TelegramBot;
use App\Models\TelegramBotSecret;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Seeds all 8 Telegram bots from config/services.php into the database.
 *
 * Idempotent: skips bots whose slug already exists. Safe to run multiple times.
 * Reads plaintext tokens from config (env vars), encrypts them, and stores
 * as TelegramBotSecret rows with status=active, version=1.
 *
 * Usage:
 *   php artisan db:seed --class=TelegramBotSeeder
 *
 * After running:
 *   - All 8 bots appear in the Filament ops console
 *   - BotResolver resolves from database instead of LegacyConfigBotAdapter
 *   - .env token values are now the source for the initial encrypted secrets
 */
class TelegramBotSeeder extends Seeder
{
    /**
     * Slug → config mapping matching LegacyConfigBotAdapter::SLUG_MAP exactly.
     */
    private const BOTS = [
        [
            'slug' => 'owner-alert',
            'name' => 'Owner Alert Bot',
            'description' => 'Sends critical alerts (bookings, payments, errors) to the hotel owner.',
            'token_config' => 'services.owner_alert_bot.token',
            'webhook_secret_config' => 'services.owner_alert_bot.webhook_secret',
        ],
        [
            'slug' => 'driver-guide',
            'name' => 'Driver & Guide Bot',
            'description' => 'Driver/guide registration, tour briefs, availability calendar, partner bookings.',
            'token_config' => 'services.driver_guide_bot.token',
            'webhook_secret_config' => 'services.driver_guide_bot.webhook_secret',
        ],
        [
            'slug' => 'pos',
            'name' => 'POS Bot',
            'description' => 'Cashier point-of-sale: shifts, transactions, reports.',
            'token_config' => 'services.telegram_pos_bot.token',
            'webhook_secret_config' => 'services.telegram_pos_bot.secret_token',
        ],
        [
            'slug' => 'booking',
            'name' => 'Booking Bot',
            'description' => 'Staff booking queries, availability checks, calendar-based booking.',
            'token_config' => 'services.telegram_booking_bot.token',
            'webhook_secret_config' => 'services.telegram_booking_bot.secret_token',
        ],
        [
            'slug' => 'cashier',
            'name' => 'Cashier Bot',
            'description' => 'Admin cashier: payment logging, expense approval, shift management.',
            'token_config' => 'services.cashier_bot.token',
            'webhook_secret_config' => 'services.cashier_bot.webhook_secret',
        ],
        [
            'slug' => 'housekeeping',
            'name' => 'Housekeeping Bot',
            'description' => 'Room cleaning status, issue reporting, lost & found, stock alerts.',
            'token_config' => 'services.housekeeping_bot.token',
            'webhook_secret_config' => 'services.housekeeping_bot.webhook_secret',
        ],
        [
            'slug' => 'kitchen',
            'name' => 'Kitchen Bot',
            'description' => 'Breakfast counter, meal counts, tomorrow forecast.',
            'token_config' => 'services.kitchen_bot.token',
            'webhook_secret_config' => 'services.kitchen_bot.webhook_secret',
        ],
        [
            'slug' => 'main',
            'name' => 'Main Telegram Bot',
            'description' => 'General-purpose bot for scheduled messages and notifications.',
            'token_config' => 'services.telegram.bot_token',
            'webhook_secret_config' => 'services.telegram.webhook_secret',
        ],
    ];

    public function run(): void
    {
        $environment = BotEnvironment::fromAppEnvironment((string) app()->environment());
        $created = 0;
        $skipped = 0;

        foreach (self::BOTS as $botDef) {
            $slug = $botDef['slug'];

            // Idempotent: skip if already exists
            if (TelegramBot::withTrashed()->where('slug', $slug)->exists()) {
                $this->command?->info("  Skip: [{$slug}] already exists");
                $skipped++;
                continue;
            }

            $token = (string) config($botDef['token_config'], '');
            if ($token === '') {
                $this->command?->warn("  Skip: [{$slug}] — no token in config ({$botDef['token_config']})");
                $skipped++;
                continue;
            }

            // Create bot
            $bot = TelegramBot::create([
                'slug' => $slug,
                'name' => $botDef['name'],
                'description' => $botDef['description'],
                'status' => BotStatus::Active,
                'environment' => $environment,
            ]);

            // Create encrypted secret (version 1, active)
            $secret = new TelegramBotSecret([
                'telegram_bot_id' => $bot->id,
                'version' => 1,
                'status' => SecretStatus::Active,
                'activated_at' => now(),
            ]);
            $secret->token_encrypted = Crypt::encryptString($token);

            // Webhook secret (optional)
            $webhookSecret = (string) config($botDef['webhook_secret_config'] ?? '', '');
            if ($webhookSecret !== '') {
                $secret->webhook_secret_encrypted = Crypt::encryptString($webhookSecret);
            }

            $secret->save();

            $this->command?->info("  Created: [{$slug}] — {$botDef['name']} (secret v1)");
            $created++;
        }

        $this->command?->info("Done: {$created} created, {$skipped} skipped.");
        Log::info("TelegramBotSeeder: {$created} bots created, {$skipped} skipped", [
            'environment' => $environment->value,
        ]);
    }
}
