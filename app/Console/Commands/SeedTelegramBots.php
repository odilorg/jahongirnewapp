<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\TelegramBotSeeder;
use Illuminate\Console\Command;

/**
 * Convenience command to seed Telegram bots from config.
 *
 * Usage: php artisan telegram:seed-bots
 *
 * Same as: php artisan db:seed --class=TelegramBotSeeder
 * but shorter and easier to remember.
 */
class SeedTelegramBots extends Command
{
    protected $signature = 'telegram:seed-bots';

    protected $description = 'Seed Telegram bots from config/services.php into the database (idempotent)';

    public function handle(): int
    {
        $this->info('Seeding Telegram bots from config...');

        $seeder = new TelegramBotSeeder();
        $seeder->setCommand($this);
        $seeder->run();

        return Command::SUCCESS;
    }
}
