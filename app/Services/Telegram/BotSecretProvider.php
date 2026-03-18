<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\BotSecretProviderInterface;
use App\Exceptions\Telegram\BotSecretUnavailableException;
use App\Models\TelegramBot;
use App\Models\TelegramBotSecret;
use Illuminate\Support\Facades\Crypt;

/**
 * Decrypts and provides bot secrets from the telegram_bot_secrets table.
 *
 * This is the ONLY class that should call Crypt::decryptString() on
 * bot token/webhook_secret ciphertext. All other code must go through
 * BotSecretProviderInterface or the higher-level BotResolverInterface.
 *
 * ## Security
 *
 * - Never caches decrypted values (stateless per call).
 * - Never logs decrypted values.
 * - Returns plaintext only to the immediate caller.
 */
final class BotSecretProvider implements BotSecretProviderInterface
{
    public function getActiveToken(TelegramBot $bot): string
    {
        $secret = $this->loadActiveSecret($bot);

        return Crypt::decryptString($secret->token_encrypted);
    }

    public function getActiveWebhookSecret(TelegramBot $bot): ?string
    {
        $secret = $this->loadActiveSecret($bot);

        if ($secret->webhook_secret_encrypted === null) {
            return null;
        }

        return Crypt::decryptString($secret->webhook_secret_encrypted);
    }

    public function getActiveSecretVersion(TelegramBot $bot): int
    {
        return $this->loadActiveSecret($bot)->version;
    }

    /**
     * Load the active secret for the given bot.
     *
     * Uses the activeSecret relationship (latestOfMany('version') scoped
     * to status=active). If no active secret exists, throws.
     *
     * @throws BotSecretUnavailableException
     */
    private function loadActiveSecret(TelegramBot $bot): TelegramBotSecret
    {
        // Force a fresh load — don't trust cached relationship state.
        // This is called at most once per resolution (the resolver builds
        // the DTO and hands off the plaintext, so there's no repeated load).
        $secret = $bot->activeSecret()->first();

        if ($secret === null) {
            throw new BotSecretUnavailableException($bot->slug);
        }

        return $secret;
    }
}
