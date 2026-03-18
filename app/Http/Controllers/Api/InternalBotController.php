<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Telegram\BotResolverInterface;
use App\Contracts\Telegram\TelegramTransportInterface;
use App\Exceptions\Telegram\TelegramApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal proxy API for cross-app Telegram bot operations.
 *
 * Token never leaves this server. Other apps send requests here,
 * this app resolves the bot and forwards to Telegram.
 *
 * All endpoints require X-Service-Key authentication via middleware.
 */
class InternalBotController extends Controller
{
    public function __construct(
        private readonly BotResolverInterface $resolver,
        private readonly TelegramTransportInterface $transport,
    ) {}

    /**
     * POST /api/internal/bots/{slug}/send-message
     * Body: { "chat_id": 123, "text": "Hello", "parse_mode": "HTML" }
     */
    public function sendMessage(Request $request, string $slug): JsonResponse
    {
        $request->validate([
            'chat_id' => 'required',
            'text' => 'required|string|max:4096',
            'parse_mode' => 'nullable|string|in:HTML,Markdown,MarkdownV2',
        ]);

        return $this->proxyCall($slug, 'sendMessage', $request->only([
            'chat_id', 'text', 'parse_mode', 'reply_markup',
            'disable_notification', 'protect_content',
        ]));
    }

    /**
     * POST /api/internal/bots/{slug}/send-photo
     * Body: { "chat_id": 123, "photo": "file_id_or_url", "caption": "..." }
     */
    public function sendPhoto(Request $request, string $slug): JsonResponse
    {
        $request->validate([
            'chat_id' => 'required',
            'photo' => 'required|string',
        ]);

        return $this->proxyCall($slug, 'sendPhoto', $request->only([
            'chat_id', 'photo', 'caption', 'parse_mode', 'reply_markup',
        ]));
    }

    /**
     * GET /api/internal/bots/{slug}/get-me
     */
    public function getMe(string $slug): JsonResponse
    {
        return $this->proxyCall($slug, 'getMe');
    }

    /**
     * GET /api/internal/bots/{slug}/webhook-info
     */
    public function webhookInfo(string $slug): JsonResponse
    {
        return $this->proxyCall($slug, 'getWebhookInfo');
    }

    /**
     * POST /api/internal/bots/{slug}/set-webhook
     * Body: { "url": "https://...", "secret_token": "..." }
     */
    public function setWebhook(Request $request, string $slug): JsonResponse
    {
        $request->validate([
            'url' => 'required|url',
        ]);

        return $this->proxyCall($slug, 'setWebhook', array_filter($request->only([
            'url', 'secret_token', 'max_connections', 'allowed_updates',
        ])));
    }

    /**
     * POST /api/internal/bots/{slug}/delete-webhook
     */
    public function deleteWebhook(Request $request, string $slug): JsonResponse
    {
        return $this->proxyCall($slug, 'deleteWebhook', $request->only([
            'drop_pending_updates',
        ]));
    }

    // ──────────────────────────────────────────────

    private function proxyCall(string $slug, string $method, array $params = []): JsonResponse
    {
        try {
            $bot = $this->resolver->resolve($slug);
            $result = $this->transport->call($bot, $method, $params);

            return response()->json([
                'ok' => $result->ok,
                'result' => $result->result,
                'description' => $result->description,
            ], $result->httpStatus >= 200 && $result->httpStatus < 500 ? 200 : 502);
        } catch (TelegramApiException $e) {
            return response()->json([
                'ok' => false,
                'error' => 'telegram_api_error',
                'description' => $e->apiError,
            ], 502);
        } catch (\Throwable $e) {
            return response()->json([
                'ok' => false,
                'error' => 'resolution_error',
                'description' => $e->getMessage(),
            ], 422);
        }
    }
}
