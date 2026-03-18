<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Contracts\Telegram\TelegramTransportInterface;
use App\DTOs\ResolvedTelegramBot;
use App\DTOs\TelegramApiResult;
use App\Exceptions\Telegram\TelegramApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP transport for the Telegram Bot API.
 *
 * ## Timeout policy
 *
 * - Connect timeout: 5s (fail fast on unreachable hosts)
 * - Response timeout: 15s (Telegram can be slow on media uploads)
 * - No automatic retries at this layer. Callers decide retry strategy
 *   based on TelegramApiResult signals (isRateLimited, isPermanentError).
 *
 * ## Security
 *
 * - Bot token is used ONLY to construct the API URL, which is passed
 *   directly to Http::post(). The URL is never logged, stored, or
 *   included in exception messages.
 * - All Log:: calls use only: bot slug, API method, HTTP status,
 *   Telegram error description. Never the token or full URL.
 * - TelegramApiException carries only the slug and sanitized error info.
 */
final class TelegramTransport implements TelegramTransportInterface
{
    private const BASE_URL = 'https://api.telegram.org/bot';
    private const CONNECT_TIMEOUT = 5;
    private const RESPONSE_TIMEOUT = 15;

    public function call(ResolvedTelegramBot $bot, string $method, array $params = []): TelegramApiResult
    {
        $url = self::BASE_URL . $bot->token . '/' . $method;

        try {
            $response = $this->httpClient()->post($url, $params);
        } catch (ConnectionException $e) {
            // Network-level failure (timeout, DNS, TLS, connection refused).
            // Log with slug only — never the URL (contains token).
            Log::error('TelegramTransport: network failure', [
                'bot_slug' => $bot->slug,
                'method' => $method,
                'error' => $e->getMessage(),
            ]);

            throw new TelegramApiException(
                slug: $bot->slug,
                method: $method,
                httpStatus: 0,
                apiError: 'Network error: ' . $e->getMessage(),
                previous: $e,
            );
        }

        $body = $response->json() ?? [];
        $httpStatus = $response->status();

        $result = new TelegramApiResult(
            ok: (bool) ($body['ok'] ?? false),
            result: $body['result'] ?? $body,
            httpStatus: $httpStatus,
            description: $body['description'] ?? null,
            errorCode: isset($body['error_code']) ? (int) $body['error_code'] : null,
        );

        // Log non-2xx responses at appropriate severity.
        // Successful calls are NOT logged (avoid noise — rule 6).
        if (! $result->succeeded()) {
            $logContext = [
                'bot_slug' => $bot->slug,
                'method' => $method,
                'http_status' => $httpStatus,
                'description' => $result->description,
            ];

            if ($result->isRateLimited()) {
                Log::warning('TelegramTransport: rate limited', [
                    ...$logContext,
                    'retry_after' => $result->retryAfterSeconds(),
                ]);
            } elseif ($result->isPermanentError()) {
                Log::warning('TelegramTransport: permanent error', $logContext);
            } else {
                Log::error('TelegramTransport: API error', $logContext);
            }
        }

        return $result;
    }

    public function getMe(ResolvedTelegramBot $bot): TelegramApiResult
    {
        return $this->call($bot, 'getMe');
    }

    public function sendMessage(
        ResolvedTelegramBot $bot,
        int|string $chatId,
        string $text,
        array $extra = [],
    ): TelegramApiResult {
        return $this->call($bot, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            ...$extra,
        ]);
    }

    public function setWebhook(
        ResolvedTelegramBot $bot,
        string $url,
        ?string $secretToken = null,
        array $extra = [],
    ): TelegramApiResult {
        $params = ['url' => $url, ...$extra];

        if ($secretToken !== null) {
            $params['secret_token'] = $secretToken;
        }

        return $this->call($bot, 'setWebhook', $params);
    }

    public function deleteWebhook(ResolvedTelegramBot $bot, bool $dropPendingUpdates = false): TelegramApiResult
    {
        return $this->call($bot, 'deleteWebhook', [
            'drop_pending_updates' => $dropPendingUpdates,
        ]);
    }

    public function getWebhookInfo(ResolvedTelegramBot $bot): TelegramApiResult
    {
        return $this->call($bot, 'getWebhookInfo');
    }

    private function httpClient(): PendingRequest
    {
        return Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::RESPONSE_TIMEOUT);
    }
}
