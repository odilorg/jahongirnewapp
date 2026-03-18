<?php

declare(strict_types=1);

namespace App\Contracts\Telegram;

use App\DTOs\ResolvedTelegramBot;
use App\DTOs\TelegramApiResult;
use App\Exceptions\Telegram\TelegramApiException;

/**
 * Low-level Telegram Bot API transport.
 *
 * Accepts a ResolvedTelegramBot (never a raw token string) and makes
 * HTTP calls to the Telegram Bot API. Returns sanitized result DTOs.
 *
 * ## Timeout / retry policy
 *
 * - Connect timeout: 5 seconds
 * - Response timeout: 15 seconds
 * - No automatic retries at the transport level. Callers (jobs, services)
 *   decide retry policy based on TelegramApiResult::isRateLimited() and
 *   TelegramApiResult::isPermanentError().
 *
 * ## Security
 *
 * - Token never appears in logs, exceptions, or result objects.
 * - Full Telegram API URLs (which embed the token) are never logged.
 * - TelegramApiException::safeContext() provides log-safe error details.
 */
interface TelegramTransportInterface
{
    /**
     * Call any Telegram Bot API method.
     *
     * @param ResolvedTelegramBot $bot    Resolved bot with decrypted token
     * @param string              $method Telegram API method (e.g. 'sendMessage')
     * @param array<string, mixed> $params Method parameters
     *
     * @throws TelegramApiException on network failure (timeout, DNS, etc.)
     */
    public function call(ResolvedTelegramBot $bot, string $method, array $params = []): TelegramApiResult;

    /**
     * Verify bot credentials and retrieve bot identity.
     */
    public function getMe(ResolvedTelegramBot $bot): TelegramApiResult;

    /**
     * Send a text message.
     *
     * @param array<string, mixed> $extra Additional sendMessage parameters (parse_mode, etc.)
     */
    public function sendMessage(ResolvedTelegramBot $bot, int|string $chatId, string $text, array $extra = []): TelegramApiResult;

    /**
     * Register a webhook URL with Telegram.
     *
     * @param string      $url         HTTPS webhook URL
     * @param string|null $secretToken Secret token for X-Telegram-Bot-Api-Secret-Token header
     * @param array<string, mixed> $extra Additional setWebhook parameters
     */
    public function setWebhook(ResolvedTelegramBot $bot, string $url, ?string $secretToken = null, array $extra = []): TelegramApiResult;

    /**
     * Remove the current webhook.
     */
    public function deleteWebhook(ResolvedTelegramBot $bot, bool $dropPendingUpdates = false): TelegramApiResult;

    /**
     * Get current webhook status.
     */
    public function getWebhookInfo(ResolvedTelegramBot $bot): TelegramApiResult;
}
