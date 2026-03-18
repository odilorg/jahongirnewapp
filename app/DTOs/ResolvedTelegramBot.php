<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\BotEnvironment;
use App\Enums\BotStatus;

/**
 * Immutable value object returned by BotResolver.
 *
 * Consumers receive this instead of touching TelegramBot/TelegramBotSecret
 * models directly. This DTO carries only what a consumer needs to operate:
 * the decrypted token, the bot identity, and operational metadata.
 *
 * ## Security contract
 *
 * - Token is decrypted plaintext. It must NOT be logged, serialized to
 *   queue payloads, cached, or stored anywhere outside runtime memory.
 * - webhook_secret is optional (not all bots use webhook verification).
 * - This object is NOT JsonSerializable and has no toArray(). Accidental
 *   serialization would leak the token.
 */
final readonly class ResolvedTelegramBot
{
    public function __construct(
        public int $botId,
        public string $slug,
        public string $name,
        public ?string $botUsername,
        public BotStatus $status,
        public BotEnvironment $environment,
        public string $token,
        public ?string $webhookSecret = null,
        public int $secretVersion = 1,
        public ?string $source = 'database',
    ) {}

    /**
     * Whether this bot was resolved from the legacy config fallback
     * rather than the telegram_bots database table.
     */
    public function isLegacy(): bool
    {
        return $this->source === 'legacy_config';
    }
}
