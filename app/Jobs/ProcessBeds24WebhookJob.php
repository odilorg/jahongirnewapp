<?php

namespace App\Jobs;

use App\Models\Beds24WebhookEvent;
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
        public int $webhookEventId
    ) {
        $this->onQueue('webhooks');
    }

    public function backoff(): array
    {
        return [10, 30, 120, 300];
    }

    public function handle(): void
    {
        $event = Beds24WebhookEvent::find($this->webhookEventId);

        if (!$event || $event->status === 'processed') {
            return;
        }

        $event->markProcessing();

        try {
            $controller = app(Beds24WebhookController::class);
            $controller->processWebhookPayload($event->payload);

            $event->markProcessed();

            Log::info('Beds24 webhook processed via queue', [
                'event_id'   => $event->id,
                'booking_id' => $event->booking_id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Beds24 webhook job failed', [
                'event_id'   => $event->id,
                'booking_id' => $event->booking_id,
                'error'      => $e->getMessage(),
                'attempt'    => $this->attempts(),
            ]);

            $event->markFailed($e->getMessage());

            throw $e; // Let Laravel retry
        }
    }

    public function failed(\Throwable $e): void
    {
        $event = Beds24WebhookEvent::find($this->webhookEventId);
        if ($event) {
            $event->markFailed('All retries exhausted: ' . $e->getMessage());
        }

        Log::critical('Beds24 webhook job permanently failed', [
            'event_id' => $this->webhookEventId,
            'error'    => $e->getMessage(),
        ]);
    }
}
