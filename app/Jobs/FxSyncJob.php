<?php

namespace App\Jobs;

use App\Exceptions\Beds24RateLimitException;
use App\Models\Beds24Booking;
use App\Services\FxSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queued job that pushes FX amounts to a single Beds24 booking.
 *
 * Dispatched by:
 *   - Beds24WebhookController  after new/modified booking events
 *   - fx:repair-missing artisan command  for near-term arrivals missing a sync
 *
 * Rate limiting: Beds24 returns HTTP 429 → FxSyncService throws Beds24RateLimitException
 * → job is re-thrown → backoff() delays next attempt (10s, 30s, 60s, 120s, 300s).
 * No sleep() calls anywhere in the sync path.
 *
 * Queue: 'beds24-writes' — configure to run with a single worker process so
 * writes to Beds24 are naturally serialised (see config/horizon.php or queue worker).
 */
class FxSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $maxExceptions = 3;

    public function __construct(
        public readonly string $beds24BookingId,   // external Beds24 ID
        public readonly string $sourceTrigger,     // webhook | repair_job | manual
    ) {}

    /** Exponential backoff: 10s, 30s, 60s, 120s, 300s */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function handle(FxSyncService $fxSyncService): void
    {
        // Query by beds24_booking_id (external ID), not by local model PK
        $booking = Beds24Booking::where('beds24_booking_id', $this->beds24BookingId)->first();

        if (! $booking) {
            Log::warning('FxSyncJob: booking not found in local DB', [
                'beds24_booking_id' => $this->beds24BookingId,
                'trigger'           => $this->sourceTrigger,
            ]);
            return; // booking may not be synced yet — not an error
        }

        $fxSyncService->pushNow($booking, $this->sourceTrigger);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('FxSyncJob permanently failed', [
            'beds24_booking_id' => $this->beds24BookingId,
            'trigger'           => $this->sourceTrigger,
            'error'             => $e->getMessage(),
        ]);

        // Mark sync record as failed so repair job / isStale() can retry
        app(FxSyncService::class)->markFailed($this->beds24BookingId, $e->getMessage());
    }
}
