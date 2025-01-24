<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class WebhookService
{
    protected $webhookUrl;

    public function __construct()
    {
        // Set your webhook URL here (you can also use .env for this)
        $this->webhookUrl = env('WEBHOOK_URL', 'https://jahongir-app.uz/webhook/ai-instructions');
    }

    public function sendWebhook($data)
    {
        $response = Http::post($this->webhookUrl, $data);

        // Log the response for debugging
        if ($response->successful()) {
            Log::info('Webhook sent successfully', ['response' => $response->json()]);
        } else {
            Log::error('Webhook failed', ['response' => $response->body()]);
        }

        return $response;
    }
}