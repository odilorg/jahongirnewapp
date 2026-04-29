<?php

namespace App\Jobs;

use App\Enums\Beds24SyncStatus;
use App\Models\Beds24PaymentSync;
use App\Services\Beds24BookingService;
use App\Services\Fx\Beds24PaymentSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pushes a single payment to the Beds24 API and transitions the sync state.
 *
 * Retry policy: 3 attempts, 60-second backoff.
 * On exhaustion, status is set to Failed and the nightly SurfaceFailedBeds24Syncs
 * command will alert ops.
 *
 * The [ref:UUID] is embedded in the payment description so that the incoming
 * Beds24 webhook can be matched back to this row without time-window dedup.
 */
class Beds24PaymentSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 60; // seconds

    public function __construct(
        private readonly int $syncId,
    ) {}

    public function handle(Beds24PaymentSyncService $syncService, Beds24BookingService $beds24): void
    {
        $sync = Beds24PaymentSync::find($this->syncId);

        if (! $sync) {
            Log::warning("Beds24PaymentSyncJob: sync row #{$this->syncId} not found, skipping.");
            return;
        }

        // Skip if already in terminal state (confirmed / skipped / double-dispatched)
        if ($sync->status->isTerminal()) {
            return;
        }

        $started = $syncService->markPushing($sync);
        if (! $started) {
            return; // Race condition — another job already claimed this row
        }

        try {
            $beds24PaymentId = $this->pushToBeds24($sync, $beds24);
            $syncService->markPushed($sync->fresh(), $beds24PaymentId);
        } catch (\Throwable $e) {
            $exhausted = $this->attempts() >= $this->tries;
            $syncService->markFailed($sync->fresh(), $e->getMessage(), $exhausted);

            Log::error("Beds24PaymentSyncJob failed for sync #{$this->syncId}", [
                'attempt'   => $this->attempts(),
                'exhausted' => $exhausted,
                'error'     => $e->getMessage(),
            ]);

            if (! $exhausted) {
                $this->release($this->backoff);
            }
        }
    }

    /**
     * Records a payment on a booking via the Beds24 v2 API.
     *
     * Beds24 v2 has NO `/bookings/{id}/payments` endpoint. The correct path
     * is `POST /bookings` with an `invoiceItems` array on each booking.
     * History (2026-04-29 audit): the previous implementation hit the
     * non-existent path and produced HTTP 500 on every attempt — three rows
     * (#23, #54, #189) accumulated 1000+ failed retries before being
     * manually skipped. No production payment has ever reached Beds24 via
     * this code path before the present hotfix.
     *
     * Request shape (v2):
     *   POST /bookings
     *   [
     *     { "id": <bookingId>, "invoiceItems": [
     *         { "type": "payment", "amount": <usd>, "description": "[ref:<UUID>] Bot payment" }
     *     ]}
     *   ]
     *
     * Response shape (v2):
     *   [ { "success": true, "modified": true, "new": [...], "info": [...], "errors": [...] } ]
     *
     * v2 does NOT return a specific invoice-item ID. The eventual Beds24
     * payment ID arrives later via the booking webhook; the embedded
     * [ref:UUID] in `description` is what WebhookReconciliationService uses
     * to match the webhook back to this sync row. Empty string is therefore
     * a valid return value — markPushed just records the transition.
     *
     * Uses Beds24BookingService::apiCall() so this job participates in the
     * shared token cache + 401-retry guardrail.
     *
     * @throws \RuntimeException on non-2xx response, success=false, or non-empty errors array
     */
    private function pushToBeds24(Beds24PaymentSync $sync, Beds24BookingService $beds24): string
    {
        $description = "[ref:{$sync->local_reference}] Bot payment";

        $payload = [[
            'id'           => (int) $sync->beds24_booking_id,
            'invoiceItems' => [[
                'type'        => 'payment',
                'amount'      => (float) $sync->amount_usd,
                'description' => $description,
            ]],
        ]];

        $response = $beds24->apiCall('POST', '/bookings', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Beds24 API error {$response->status()}: {$response->body()}"
            );
        }

        $body = $response->json();
        $first = is_array($body) ? ($body[0] ?? null) : null;

        if (! is_array($first)) {
            throw new \RuntimeException(
                "Beds24 v2 returned unexpected response shape: {$response->body()}"
            );
        }

        if (($first['success'] ?? false) !== true) {
            $errors = $first['errors'] ?? [];
            throw new \RuntimeException(
                "Beds24 v2 rejected payment write: " . json_encode($errors ?: $first)
            );
        }

        // Beds24 v2 partial-success defence: success=true with a non-empty
        // errors array means the booking was modified but at least one item
        // failed. writePaymentOptionsToInfoItems guards against the same
        // shape — keep the bot push consistent.
        if (! empty($first['errors'])) {
            throw new \RuntimeException(
                "Beds24 v2 partial success with errors: " . json_encode($first['errors'])
            );
        }

        // v2 doesn't return a specific invoiceItem ID for newly-created items.
        // The webhook will fill this in via WebhookReconciliationService when
        // Beds24 fires the booking-modified event for this booking.
        return '';
    }
}
