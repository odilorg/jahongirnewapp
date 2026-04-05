<?php

namespace App\Console\Commands;

use App\Enums\FxSyncPushStatus;
use App\Jobs\FxSyncJob;
use App\Models\Beds24Booking;
use App\Models\BookingFxSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Repair near-term bookings that are missing or have a failed FX sync.
 *
 * Runs nightly (or on demand) to catch any bookings that slipped through:
 *   - Webhook fired before the daily rate existed
 *   - FxSyncJob hit a permanent 429 failure
 *   - Booking created before the event-driven system was deployed
 *
 * Scopes to arrivals in the next --days window to limit blast radius.
 * Dispatches FxSyncJob per booking with random jitter so the queue
 * worker is not slammed all at once.
 *
 * Usage:
 *   php artisan fx:repair-missing
 *   php artisan fx:repair-missing --days=14
 *   php artisan fx:repair-missing --dry-run
 */
class RepairMissingFxSyncs extends Command
{
    protected $signature = 'fx:repair-missing
                            {--days=30 : Look-ahead window in days}
                            {--dry-run : Show which bookings would be queued, without dispatching}';

    protected $description = 'Queue FxSyncJob for near-term bookings with missing or failed FX syncs';

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        $window = [today(), today()->addDays($days)];

        // Bookings arriving in window with status that has a payable amount
        $bookingIds = Beds24Booking::query()
            ->whereBetween('arrival_date', $window)
            ->whereIn('booking_status', ['confirmed', 'new'])
            ->where(function ($q) {
                $q->where('invoice_balance', '>', 0)
                  ->orWhere('total_amount', '>', 0);
            })
            ->pluck('beds24_booking_id');

        // Filter to those with no sync row, or a failed/pending sync
        $synced = BookingFxSync::whereIn('beds24_booking_id', $bookingIds)
            ->where('push_status', FxSyncPushStatus::Pushed->value)
            ->pluck('beds24_booking_id')
            ->flip();  // O(1) lookup

        $toRepair = $bookingIds->filter(fn ($id) => ! $synced->has((string) $id));

        $count = $toRepair->count();

        if ($count === 0) {
            $this->info("No bookings need repair in the next {$days} days.");
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Queuing FX repair for {$count} bookings (next {$days} days)…");

        if ($dryRun) {
            $this->table(['Beds24 Booking ID'], $toRepair->map(fn ($id) => [$id])->values()->toArray());
            return self::SUCCESS;
        }

        foreach ($toRepair as $beds24BookingId) {
            // Jitter: spread dispatches up to 60 s to avoid thundering-herd on queue
            $delaySeconds = random_int(0, 60);

            FxSyncJob::dispatch((string) $beds24BookingId, 'repair_job')
                ->onQueue('beds24-writes')
                ->delay(now()->addSeconds($delaySeconds));
        }

        Log::info('fx:repair-missing: dispatched FX sync jobs', [
            'count' => $count,
            'days'  => $days,
        ]);

        $this->info("Dispatched {$count} FxSyncJob(s) to beds24-writes queue.");

        return self::SUCCESS;
    }
}
