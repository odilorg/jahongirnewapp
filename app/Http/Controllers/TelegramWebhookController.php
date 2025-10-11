<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Jobs\ProcessTelegramMessage;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        // Log incoming webhook
        Log::info('Telegram Webhook Received', ['data' => $request->all()]);

        try {
            $update = $request->all();

            // Validate basic structure
            if (empty($update) || !isset($update['update_id'])) {
                return response()->json(['error' => 'Invalid webhook data'], 400);
            }

            // Dispatch job for async processing
            ProcessTelegramMessage::dispatch($update);

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            Log::error('Telegram Webhook Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    public function setWebhook(Request $request): JsonResponse
    {
        $webhookUrl = $request->input('url', route('telegram.webhook'));
        
        try {
            $telegramService = app(\App\Services\TelegramBotService::class);
            $result = $telegramService->setWebhook($webhookUrl);

            return response()->json([
                'success' => true,
                'webhook_url' => $webhookUrl,
                'result' => $result
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getWebhookInfo(): JsonResponse
    {
        try {
            $telegramService = app(\App\Services\TelegramBotService::class);
            $info = $telegramService->getWebhookInfo();

            return response()->json($info);

        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
