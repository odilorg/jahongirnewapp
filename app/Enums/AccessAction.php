<?php

declare(strict_types=1);

namespace App\Enums;

enum AccessAction: string
{
    case TokenRead = 'token_read';
    case WebhookSecretRead = 'webhook_secret_read';
    case MessageSent = 'message_sent';
    case WebhookSet = 'webhook_set';
    case WebhookReceived = 'webhook_received';
    case TokenRotated = 'token_rotated';
    case TokenRevealed = 'token_revealed';
    case BotCreated = 'bot_created';
    case BotUpdated = 'bot_updated';
    case BotDisabled = 'bot_disabled';
    case BotRevoked = 'bot_revoked';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::TokenRead => 'Token Read',
            self::WebhookSecretRead => 'Webhook Secret Read',
            self::MessageSent => 'Message Sent',
            self::WebhookSet => 'Webhook Set',
            self::WebhookReceived => 'Webhook Received',
            self::TokenRotated => 'Token Rotated',
            self::TokenRevealed => 'Token Revealed',
            self::BotCreated => 'Bot Created',
            self::BotUpdated => 'Bot Updated',
            self::BotDisabled => 'Bot Disabled',
            self::BotRevoked => 'Bot Revoked',
            self::Error => 'Error',
        };
    }

    /**
     * Privileged secret operations warranting security dashboard visibility
     * and anomaly alerting. Excludes routine token_read (high-volume operational
     * event logged by the resolution layer, not a privileged access).
     */
    public function isPrivilegedSecretAccess(): bool
    {
        return in_array($this, [
            self::TokenRevealed,
            self::WebhookSecretRead,
            self::TokenRotated,
        ], true);
    }
}
