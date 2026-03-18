<?php

declare(strict_types=1);

namespace App\Contracts\Telegram;

use App\DTOs\ResolvedTelegramBot;
use App\Exceptions\Telegram\BotDisabledException;
use App\Exceptions\Telegram\BotEnvironmentMismatchException;
use App\Exceptions\Telegram\BotNotFoundException;
use App\Exceptions\Telegram\BotSecretUnavailableException;

interface BotResolverInterface
{
    /**
     * Resolve a bot by slug, returning a fully hydrated DTO with decrypted token.
     *
     * Enforcement order:
     * 1. Bot exists (or throw BotNotFoundException)
     * 2. Bot is usable — status is active (or throw BotDisabledException)
     * 3. Bot matches current app environment (or throw BotEnvironmentMismatchException)
     * 4. Active secret exists (or throw BotSecretUnavailableException)
     *
     * @throws BotNotFoundException
     * @throws BotDisabledException
     * @throws BotEnvironmentMismatchException
     * @throws BotSecretUnavailableException
     */
    public function resolve(string $slug): ResolvedTelegramBot;

    /**
     * Resolve without throwing — returns null on any failure.
     * Useful for non-critical paths (e.g., optional notifications).
     */
    public function tryResolve(string $slug): ?ResolvedTelegramBot;
}
