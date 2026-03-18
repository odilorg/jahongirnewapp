<?php

namespace App\Jobs;

use App\Models\IncomingWebhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessTelegramUpdateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public string $botName,
        public int $incomingWebhookId,
    ) {
        $this->onQueue('telegram');
    }

    public function handle(): void
    {
        $webhook = IncomingWebhook::find($this->incomingWebhookId);
        if (!$webhook) {
            Log::warning("ProcessTelegramUpdateJob: webhook #{$this->incomingWebhookId} not found");
            return;
        }

        // Idempotency: skip if already processed
        if ($webhook->status === IncomingWebhook::STATUS_PROCESSED) {
            return;
        }

        $webhook->markProcessing();
        $payload = $webhook->payload;

        try {
            $controller = match ($this->botName) {
                'cashier'      => app(\App\Http\Controllers\CashierBotController::class),
                'housekeeping' => app(\App\Http\Controllers\HousekeepingBotController::class),
                'kitchen'      => app(\App\Http\Controllers\KitchenBotController::class),
                'driver'       => app(\App\Http\Controllers\TelegramDriverGuideSignUpController::class),
                default        => null,
            };

            if (!$controller) {
                $webhook->markFailed("Unknown bot: {$this->botName}");
                return;
            }

            // Call the controller's processUpdate method (business logic only, no HTTP)
            $controller->processUpdate($payload);

            $webhook->markProcessed();
        } catch (\Throwable $e) {
            Log::error("ProcessTelegramUpdateJob: {$this->botName} failed", [
                'webhook_id' => $this->incomingWebhookId,
                'error' => $e->getMessage(),
            ]);
            $webhook->markFailed($e->getMessage());
            throw $e; // Let queue retry
        }
    }
}
