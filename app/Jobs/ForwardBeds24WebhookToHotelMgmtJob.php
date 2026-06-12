<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IncomingWebhook;
use App\Services\HotelMgmtClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Forwards a copy of an incoming Beds24 webhook to the hotel-mgmt PMS
 * discovery receiver. Best-effort, isolated from the critical Beds24
 * booking/payment pipeline:
 *
 *  - Runs on its OWN 'fanout' queue (placed LAST on the worker line) so a
 *    backed-up / down hotel-mgmt can never delay ProcessBeds24WebhookJob.
 *  - Re-loads the raw payload from the durable inbox (incoming_webhooks) by
 *    id, keeping the serialized job body tiny.
 *  - Retries on transient failure (hotel-mgmt is idempotent, so re-delivery
 *    is safe), then gives up quietly — discovery is not mission-critical.
 */
class ForwardBeds24WebhookToHotelMgmtJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 15;

    public function __construct(public int $incomingWebhookId)
    {
        $this->onQueue('fanout');
    }

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(HotelMgmtClient $client): void
    {
        if (! config('services.hotel_mgmt.fanout_enabled', false)) {
            return;
        }

        $incoming = IncomingWebhook::find($this->incomingWebhookId);
        if ($incoming === null) {
            // Inbox row gone (pruned) — nothing to forward.
            return;
        }

        $payload = $incoming->payload;
        if (! is_array($payload) || $payload === []) {
            return;
        }

        $result = $client->forwardBeds24Webhook($payload);

        if (! ($result['ok'] ?? false)) {
            // Throw so Laravel retries against the idempotent receiver.
            throw new \RuntimeException(
                'hotel-mgmt fan-out failed: '.($result['error'] ?? 'unknown')
            );
        }
    }

    public function failed(\Throwable $e): void
    {
        // Discovery is best-effort — a permanently-failed fan-out is a warning,
        // never critical. The payload is preserved in the durable inbox.
        Log::warning('Beds24 hotel-mgmt fan-out permanently failed', [
            'incoming_webhook_id' => $this->incomingWebhookId,
            'error' => $e->getMessage(),
        ]);
    }
}
