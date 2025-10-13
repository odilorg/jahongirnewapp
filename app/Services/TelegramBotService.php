<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\BotConfiguration;

class TelegramBotService
{
    protected string $botToken;
    protected string $apiUrl = 'https://api.telegram.org/bot';

    public function __construct()
    {
        $this->botToken = config("services.telegram.bot_token", env("TELEGRAM_BOT_TOKEN"));
    }

    public function sendMessage(int $chatId, string $text, array $options = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'text' => $text,
            // 'parse_mode' => 'Markdown', // Disabled to prevent markdown parsing errors
        ], $options);

        try {
            $response = Http::timeout(30)->post($this->apiUrl . $this->botToken . '/sendMessage', $params);
            
            if (!$response->successful()) {
                throw new \Exception('Telegram API error: ' . $response->body());
            }

            return $response->json();
            
        } catch (\Exception $e) {
            Log::error('Telegram Send Message Error', [
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function setWebhook(string $url): array
    {
        try {
            $response = Http::post($this->apiUrl . $this->botToken . '/setWebhook', [
                'url' => $url,
            ]);

            return $response->json();
            
        } catch (\Exception $e) {
            Log::error('Telegram Set Webhook Error', [
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function getWebhookInfo(): array
    {
        try {
            $response = Http::get($this->apiUrl . $this->botToken . '/getWebhookInfo');
            return $response->json();
        } catch (\Exception $e) {
            Log::error('Telegram Get Webhook Info Error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function deleteWebhook(): array
    {
        try {
            $response = Http::post($this->apiUrl . $this->botToken . '/deleteWebhook');
            return $response->json();
        } catch (\Exception $e) {
            Log::error('Telegram Delete Webhook Error', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function answerCallbackQuery(string $callbackQueryId, array $options = []): array
    {
        $params = array_merge([
            'callback_query_id' => $callbackQueryId,
        ], $options);

        try {
            $response = Http::timeout(30)->post($this->apiUrl . $this->botToken . '/answerCallbackQuery', $params);

            if (!$response->successful()) {
                throw new \Exception('Telegram API error: ' . $response->body());
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Telegram Answer Callback Query Error', [
                'callback_query_id' => $callbackQueryId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function editMessageText(int $chatId, int $messageId, string $text, array $options = []): array
    {
        $params = array_merge([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ], $options);

        try {
            $response = Http::timeout(30)->post($this->apiUrl . $this->botToken . '/editMessageText', $params);

            if (!$response->successful()) {
                throw new \Exception('Telegram API error: ' . $response->body());
            }

            return $response->json();

        } catch (\Exception $e) {
            Log::error('Telegram Edit Message Text Error', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
