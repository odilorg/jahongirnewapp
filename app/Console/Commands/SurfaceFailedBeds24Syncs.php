<?php

namespace App\Console\Commands;

use App\Enums\Beds24SyncStatus;
use App\Models\Beds24PaymentSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reports on Beds24PaymentSync rows that have permanently failed.
 * Intended to be run nightly so ops can investigate.
 */
class SurfaceFailedBeds24Syncs extends Command
{
    protected $signature   = 'fx:surface-failed-syncs {--notify : Send Telegram alert to owner}';
    protected $description = 'List Beds24 payment syncs that have permanently failed';

    public function handle(): int
    {
        $failed = Beds24PaymentSync::where('status', Beds24SyncStatus::Failed->value)
            ->orderBy('created_at')
            ->get(['id', 'beds24_booking_id', 'local_reference', 'amount_usd', 'last_error', 'created_at']);

        if ($failed->isEmpty()) {
            $this->info('No failed Beds24 payment syncs.');
            return self::SUCCESS;
        }

        $this->error("Found {$failed->count()} permanently failed Beds24 payment sync(s):");

        $this->table(
            ['ID', 'Booking', 'Ref', 'USD', 'Last Error', 'Created'],
            $failed->map(fn ($r) => [
                $r->id,
                $r->beds24_booking_id,
                substr($r->local_reference, 0, 8) . '...',
                $r->amount_usd,
                substr($r->last_error ?? '', 0, 60),
                $r->created_at->toDateTimeString(),
            ])->toArray(),
        );

        Log::warning('SurfaceFailedBeds24Syncs: permanently failed syncs found', [
            'count' => $failed->count(),
            'ids'   => $failed->pluck('id')->toArray(),
        ]);

        return self::FAILURE; // Non-zero so CI/monitoring can alert
    }
}
