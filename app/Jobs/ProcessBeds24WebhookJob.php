<?php

namespace App\Jobs;

use App\Models\Beds24WebhookEvent;
use App\Models\IncomingWebhook;
use App\Http\Controllers\Beds24WebhookController;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBeds24WebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 120;

    public function __construct(
        public int $webhookEventId,
        public ?int $incomingWebhookId = null,
    ) {
        $this->onQueue('webhooks');
    }

    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function handle(): void
    {
        $event           = Beds24WebhookEvent::find($this->webhookEventId);
        $incomingWebhook = $this->incomingWebhookId
            ? IncomingWebhook::find($this->incomingWebhookId)
            : null;

        if (!$event || $event->status === 'processed') {
            // Also mark durable inbox as processed if it exists
            $incomingWebhook?->markProcessed();
            return;
        }

        $event->markProcessing();
        $incomingWebhook?->markProcessing();

        try {
            $controller = app(Beds24WebhookController::class);
            $controller->processWebhookPayload($event->payload);

            $event->markProcessed();
            $incomingWebhook?->markProcessed();

            Log::info('Beds24 webhook processed via queue', [
                'event_id'            => $event->id,
                'booking_id'          => $event->booking_id,
                'incoming_webhook_id' => $incomingWebhook?->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Beds24 webhook job failed', [
                'event_id'            => $event->id,
                'booking_id'          => $event->booking_id,
                'error'               => $e->getMessage(),
                'attempt'             => $this->attempts(),
                'incoming_webhook_id' => $incomingWebhook?->id,
            ]);

            $event->markFailed($e->getMessage());
            $incomingWebhook?->markFailed($e->getMessage());

            throw $e; // Let Laravel retry
        }
    }

    public function failed(\Throwable $e): void
    {
        $event = Beds24WebhookEvent::find($this->webhookEventId);
        if ($event) {
            $event->markFailed('All retries exhausted: ' . $e->getMessage());
        }

        $incomingWebhook = $this->incomingWebhookId
            ? IncomingWebhook::find($this->incomingWebhookId)
            : null;
        $incomingWebhook?->markFailed('All retries exhausted: ' . $e->getMessage());

        Log::critical('Beds24 webhook job permanently failed', [
            'event_id'            => $this->webhookEventId,
            'incoming_webhook_id' => $this->incomingWebhookId,
            'error'               => $e->getMessage(),
        ]);
    }
}
