<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\OperatorBookingFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook handler for @JahongirOpsBot — the operator-only manual booking bot.
 *
 * Only the owner chat ID (TELEGRAM_OWNER_CHAT_ID) can trigger the booking flow.
 * All other senders receive a silent 200 OK with no response.
 *
 * Telegram requires a 200 response within ~30 s or it will retry the update.
 * All processing is synchronous and fast (no queued jobs needed for this flow).
 */
class OpsBotController extends Controller
{
    private string $ownerChatId;

    public function __construct(
        private readonly OperatorBookingFlow $flow,
    ) {
        $this->ownerChatId = config('services.ops_bot.owner_chat_id', '38738713');
    }

    public function webhook(Request $request): JsonResponse
    {
        $update = $request->all();

        $chatId        = (string) (data_get($update, 'message.chat.id')
                      ?? data_get($update, 'callback_query.message.chat.id') ?? '');
        $text          = data_get($update, 'message.text');
        $callbackQuery = data_get($update, 'callback_query');
        $callbackData  = $callbackQuery ? data_get($callbackQuery, 'data') : null;
        $callbackId    = $callbackQuery ? data_get($callbackQuery, 'id') : null;

        if (! $chatId) {
            return response()->json(['ok' => true]);
        }

        // Silently ignore anyone who is not the owner
        if ($chatId !== $this->ownerChatId) {
            Log::warning('OpsBotController: message from non-owner ignored', ['chat_id' => $chatId]);
            return response()->json(['ok' => true]);
        }

        Log::info('OpsBotController: update', ['chat_id' => $chatId, 'text' => $text, 'callback' => $callbackData]);

        // Acknowledge callback queries immediately (removes the loading spinner)
        if ($callbackId) {
            $this->answerCallback($callbackId);
        }

        $response = $this->flow->handle($chatId, $text, $callbackData);

        if (isset($response['reply_markup'])) {
            $this->sendMessage($chatId, $response['text'], $response['reply_markup']['inline_keyboard']);
        } else {
            $this->sendMessage($chatId, $response['text']);
        }

        return response()->json(['ok' => true]);
    }

    // ── Telegram API helpers ─────────────────────────────────────────────────

    private function sendMessage(string $chatId, string $text, ?array $inlineKeyboard = null): void
    {
        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'HTML',
        ];

        if ($inlineKeyboard) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }

        $this->call('sendMessage', $payload);
    }

    private function answerCallback(string $callbackQueryId): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackQueryId]);
    }

    private function call(string $method, array $payload): void
    {
        $token = config('services.ops_bot.token');
        $url   = "https://api.telegram.org/bot{$token}/{$method}";

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            Log::error("OpsBotController: Telegram API call failed [{$method}]", ['error' => $e->getMessage()]);
        }
    }
}
