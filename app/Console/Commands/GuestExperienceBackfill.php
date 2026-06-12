<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\GuestExperience\MaterializeExperienceMessages;
use App\Models\BookingInquiry;
use App\Services\GuestExperience\MessageCatalog;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * One-shot backfill: materialize experience messages for already-confirmed
 * future bookings (Phase 29).
 *
 * New confirmations are covered automatically by ConfirmBookingAction. This
 * command catches bookings confirmed before the engine shipped. The
 * materializer skips any message whose due_at has already passed, so this
 * never back-fires a stale touchpoint.
 */
class GuestExperienceBackfill extends Command
{
    protected $signature = 'guest-experience:backfill
        {--dry-run : List eligible bookings without creating rows}';

    protected $description = 'Materialize experience messages for existing confirmed future bookings';

    public function handle(MessageCatalog $catalog, MaterializeExperienceMessages $materializer): int
    {
        $today = Carbon::now('Asia/Tashkent')->toDateString();
        $dryRun = (bool) $this->option('dry-run');

        $bookings = BookingInquiry::query()
            ->where('status', BookingInquiry::STATUS_CONFIRMED)
            ->where('experience_messages_opted_out', false)
            ->whereDate('travel_date', '>=', $today)
            ->orderBy('travel_date')
            ->get()
            ->filter(fn (BookingInquiry $b) => $catalog->appliesTo($b));

        $this->info("Eligible confirmed future bookings on catalogued tours: {$bookings->count()}");

        $total = 0;
        foreach ($bookings as $b) {
            if ($dryRun) {
                $this->line("  • {$b->reference} · {$b->tour_slug} · {$b->travel_date?->toDateString()}");

                continue;
            }
            $created = $materializer->handle($b);
            $total += $created;
            $this->line("  ✅ {$b->reference} · {$created} message(s)");
        }

        $this->info($dryRun ? 'dry-run — nothing created.' : "done — {$total} message row(s) materialized.");

        return self::SUCCESS;
    }
}
