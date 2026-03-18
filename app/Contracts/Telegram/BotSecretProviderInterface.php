<?php

declare(strict_types=1);

namespace App\Contracts\Telegram;

use App\Exceptions\Telegram\BotSecretUnavailableException;
use App\Models\TelegramBot;

interface BotSecretProviderInterface
{
    /**
     * Get the active (decrypted) bot token.
     *
     * @throws BotSecretUnavailableException if no active secret exists
     */
    public function getActiveToken(TelegramBot $bot): string;

    /**
     * Get the active (decrypted) webhook secret, or null if not set.
     *
     * @throws BotSecretUnavailableException if no active secret exists
     */
    public function getActiveWebhookSecret(TelegramBot $bot): ?string;

    /**
     * Get the version number of the currently active secret.
     *
     * @throws BotSecretUnavailableException if no active secret exists
     */
    public function getActiveSecretVersion(TelegramBot $bot): int;
}
