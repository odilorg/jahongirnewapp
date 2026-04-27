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
     * Calls the Beds24 v2 API to record a payment on the booking.
     * Returns the beds24 payment ID from the response.
     *
     * Uses Beds24BookingService::apiCall() so this job participates in
     * the same access-token cache + refresh + 401-retry guardrail every
     * other Beds24 caller already does. Previously this method read
     * config('services.beds24.api_key') — a key that does not exist in
     * config/services.php — and silently sent `token: ` (empty),
     * producing 401 "Token is missing" on every attempt.
     *
     * @throws \RuntimeException on non-2xx response or missing payment ID
     */
    private function pushToBeds24(Beds24PaymentSync $sync, Beds24BookingService $beds24): string
    {
        // Embed local_reference in description so the incoming Beds24
        // webhook can match the payment back to this row without a
        // time-window dedup.
        $description = "[ref:{$sync->local_reference}] Bot payment";

        $response = $beds24->apiCall('POST', "/bookings/{$sync->beds24_booking_id}/payments", [
            'amount'      => (float) $sync->amount_usd,
            'currency'    => 'USD',
            'description' => $description,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Beds24 API error {$response->status()}: {$response->body()}"
            );
        }

        $paymentId = $response->json('id') ?? $response->json('paymentId');

        if (! $paymentId) {
            throw new \RuntimeException(
                "Beds24 API returned success but no payment ID in response: {$response->body()}"
            );
        }

        return (string) $paymentId;
    }
}
