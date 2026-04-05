<?php

namespace App\Jobs;

use App\Models\IncomingWebhook;
use App\Services\OwnerAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * Called by the queue after all retry attempts are exhausted.
     * Sends a single owner alert — not once per retry attempt.
     */
    public function failed(Throwable $e): void
    {
        Log::error("ProcessTelegramUpdateJob: {$this->botName} permanently failed after all retries", [
            'webhook_id' => $this->incomingWebhookId,
            'error'      => $e->getMessage(),
        ]);

        try {
            /** @var OwnerAlertService $alert */
            $alert = app(OwnerAlertService::class);
            $msg   = "🔴 <b>" . ucfirst($this->botName) . " Bot Error</b>\n\n"
                . "📍 All retries exhausted\n"
                . "🪝 Webhook ID: {$this->incomingWebhookId}\n"
                . "❌ " . mb_substr($e->getMessage(), 0, 200) . "\n"
                . "📄 " . basename($e->getFile()) . ':' . $e->getLine();
            $alert->sendShiftCloseReport($msg);
        } catch (\Throwable $ignore) {
            // Never let the alert failure mask the original error
        }
    }
}
