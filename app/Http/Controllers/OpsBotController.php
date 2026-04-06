<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\BotOperatorAuth;
use App\Services\OperatorBookingFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook handler for @JahongirOpsBot — the operator-only manual booking bot.
 *
 * Authentication is based on telegram_user_id (from.id), NOT chat_id.
 * Every update is authenticated via BotOperatorAuth before any routing occurs.
 * Unrecognized or inactive users receive a denial message and no state is created.
 *
 * Telegram requires a 200 response within ~30 s or it will retry the update.
 * All processing is synchronous and fast (no queued jobs needed for this flow).
 */
class OpsBotController extends Controller
{
    public function __construct(
        private readonly OperatorBookingFlow $flow,
        private readonly BotOperatorAuth     $auth,
    ) {}

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

        // ── Authenticate by telegram_user_id (from.id) — default deny ────────
        $operator = $this->auth->fromUpdate($update);

        if (! $operator) {
            $userId = $this->auth->extractUserId($update);
            Log::warning('OpsBotController: unauthorized access attempt', [
                'telegram_user_id' => $userId,
                'chat_id'          => $chatId,
                'callback'         => $callbackData,
            ]);
            // Acknowledge callback to dismiss the spinner even on denial
            if ($callbackId) {
                $this->answerCallback($callbackId);
            }
            $this->sendMessage($chatId, "🚫 You are not authorized to use this bot.");
            return response()->json(['ok' => true]);
        }

        Log::info('OpsBotController: update', [
            'telegram_user_id' => $operator->telegram_user_id,
            'role'             => $operator->role,
            'chat_id'          => $chatId,
            'text'             => $text,
            'callback'         => $callbackData,
        ]);

        // Acknowledge callback queries immediately (removes the loading spinner)
        if ($callbackId) {
            $this->answerCallback($callbackId);
        }

        $response = $this->flow->handle($chatId, $text, $callbackData, $operator);

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
