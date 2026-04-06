<?php

namespace App\Console\Commands;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use Illuminate\Console\Command;

/**
 * Register the operator booking bot's command menu with Telegram.
 *
 * Run after any change to the bot's available commands so the
 * "/" autocomplete list stays accurate.
 *
 * Usage:
 *   php artisan telegram:booking:set-commands
 */
class SetBookingBotCommands extends Command
{
    protected $signature   = 'telegram:booking:set-commands';
    protected $description = 'Register the operator booking bot\'s commands with Telegram (updates the "/" menu)';

    /** Commands that every operator sees. */
    private const COMMANDS = [
        ['command' => 'newbooking', 'description' => 'Create a new manual booking'],
        ['command' => 'bookings',   'description' => 'Browse and manage upcoming bookings'],
        ['command' => 'staff',      'description' => 'Manage drivers and guides'],
        ['command' => 'cancel',     'description' => 'Cancel the current flow'],
        ['command' => 'help',       'description' => 'Show available commands'],
    ];

    public function handle(BotResolverInterface $resolver, TelegramTransportInterface $transport): int
    {
        try {
            $bot    = $resolver->resolve('booking');
            $result = $transport->call($bot, 'setMyCommands', [
                'commands' => self::COMMANDS,
            ]);

            if ($result->succeeded()) {
                $this->info('✅ Booking bot commands registered successfully.');
                foreach (self::COMMANDS as $cmd) {
                    $this->line("   /{$cmd['command']} — {$cmd['description']}");
                }
                return Command::SUCCESS;
            }

            $this->error('❌ Telegram returned non-ok: ' . ($result->description ?? 'no description'));
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $this->error('❌ Exception: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
