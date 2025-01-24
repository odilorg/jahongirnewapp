<?php

namespace App\Observers;

use App\Models\AiInstruction;
use App\Services\WebhookService;

class AiInstructionObserver
{
    protected $webhookService;

    public function __construct(WebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    public function created(AiInstruction $aiInstruction)
    {
        // Prepare the data to send in the webhook
        $data = [
            'event' => 'new_ai_instruction',
            'data' => $aiInstruction->toArray(),
        ];

        // Send the webhook
        $this->webhookService->sendWebhook($data);
    }
}