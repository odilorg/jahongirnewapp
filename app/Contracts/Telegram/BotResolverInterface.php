<?php

declare(strict_types=1);

namespace App\Contracts\Telegram;

use App\Exceptions\Telegram\BotDisabledException;
use App\Exceptions\Telegram\BotEnvironmentMismatchException;
use App\Exceptions\Telegram\BotNotFoundException;
use App\Models\TelegramBot;

interface BotResolverInterface
{
    /**
     * Resolve a bot by slug. Returns null if not found.
     */
    public function find(string $slug): ?TelegramBot;

    /**
     * Resolve a bot by slug or throw.
     *
     * @throws BotNotFoundException if slug does not exist
     * @throws BotDisabledException if bot is not active
     * @throws BotEnvironmentMismatchException if bot environment does not match app environment
     */
    public function resolveOrFail(string $slug): TelegramBot;
}
