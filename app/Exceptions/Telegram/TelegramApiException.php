<?php

declare(strict_types=1);

namespace App\Exceptions\Telegram;

use RuntimeException;

/**
 * Thrown when the Telegram Bot API returns an error or is unreachable.
 *
 * Separate from resolver/domain exceptions (BotNotFoundException, etc.)
 * so callers can distinguish "bot resolved fine but API call failed"
 * from "bot could not be resolved at all".
 *
 * ## Security
 *
 * Exception messages and context are sanitized. The bot token NEVER
 * appears in getMessage(), getContext(), or any log output produced
 * from this exception. Only the bot slug is included for identification.
 */
final class TelegramApiException extends RuntimeException
{
    /**
     * @param string       $slug       Bot slug (for identification, never the token)
     * @param string       $method     Telegram API method called (e.g. 'sendMessage')
     * @param int          $httpStatus HTTP status code from Telegram (0 if network error)
     * @param string       $apiError   Telegram error description or network error message
     * @param array<string, mixed> $context Additional safe context (no secrets)
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $method,
        public readonly int $httpStatus,
        public readonly string $apiError,
        public readonly array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            "Telegram API error for [{$slug}] {$method}: HTTP {$httpStatus} — {$apiError}",
            $httpStatus,
            $previous,
        );
    }

    /**
     * Safe log context — never includes tokens or secrets.
     *
     * @return array<string, mixed>
     */
    public function safeContext(): array
    {
        return [
            'bot_slug' => $this->slug,
            'method' => $this->method,
            'http_status' => $this->httpStatus,
            'api_error' => $this->apiError,
            ...$this->context,
        ];
    }
}
