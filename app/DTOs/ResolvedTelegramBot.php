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
    /**
     * @param int|null    $botId         Database PK, or null for legacy config bots
     * @param string      $slug          Canonical bot identifier
     * @param string      $name          Human-readable display name
     * @param string|null $botUsername    Telegram @username (null for legacy)
     * @param BotStatus   $status        Always Active for legacy bots
     * @param BotEnvironment $environment Inferred from app env for legacy bots
     * @param string      $token         Decrypted plaintext — NEVER log, serialize, or cache
     * @param string|null $webhookSecret Decrypted webhook verification secret
     * @param int         $secretVersion Secret row version (0 for legacy)
     * @param string      $source        'database' or 'legacy_config'
     */
    public function __construct(
        public ?int $botId,
        public string $slug,
        public string $name,
        public ?string $botUsername,
        public BotStatus $status,
        public BotEnvironment $environment,
        public string $token,
        public ?string $webhookSecret = null,
        public int $secretVersion = 0,
        public string $source = 'database',
    ) {}

    /**
     * Whether this bot was resolved from the legacy config fallback
     * rather than the telegram_bots database table.
     */
    public function isLegacy(): bool
    {
        return $this->source === 'legacy_config';
    }

    /**
     * Redact secrets from debug output (var_dump, dd, dump, print_r).
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'botId' => $this->botId,
            'slug' => $this->slug,
            'name' => $this->name,
            'botUsername' => $this->botUsername,
            'status' => $this->status,
            'environment' => $this->environment,
            'token' => '********',
            'webhookSecret' => $this->webhookSecret !== null ? '********' : null,
            'secretVersion' => $this->secretVersion,
            'source' => $this->source,
        ];
    }

    /**
     * Prevent serialization — token must not survive beyond runtime memory.
     *
     * @return never
     * @throws \LogicException
     */
    public function __serialize(): array
    {
        throw new \LogicException(
            'ResolvedTelegramBot must not be serialized — it contains decrypted secrets. '
            . 'Do not pass this DTO to queues, cache, or session storage.'
        );
    }

    /**
     * @return never
     * @throws \LogicException
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException(
            'ResolvedTelegramBot must not be unserialized — it contains decrypted secrets.'
        );
    }
}
