<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Models\InquiryStay;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Parses `internal_notes` for historical dispatch audit lines and populates
 * driver_dispatched_at / guide_dispatched_at / stays.dispatched_at for
 * records that dispatched BEFORE the migration added those columns.
 *
 * Idempotent: always takes the LATEST timestamp found per role/stay.
 * Uses query-builder updates so updated_at is not bumped (preserves the
 * "updated_at > dispatched_at" stale-detection semantics).
 *
 * Patterns it recognises:
 *   [YYYY-MM-DD HH:MM] ... dispatch TG → driver <name> ok
 *   [YYYY-MM-DD HH:MM] ... dispatch TG → guide <name> ok
 *   [YYYY-MM-DD HH:MM] ... dispatch TG → stay <accommodation name> ok
 *
 * Old and new audit formats both work (same bracketed timestamp, variable
 * middle text, "→ <role> <name> ok" tail).
 */
class BackfillDispatchTimestamps extends Command
{
    protected $signature = 'tg:backfill-dispatch-timestamps
        {--dry-run : Print intended updates, write nothing}
        {--only= : Restrict to one of driver|guide|stay}';

    protected $description = 'Backfill driver/guide/stay dispatched_at from internal_notes audit trail';

    public function handle(): int
    {
        $dry  = (bool) $this->option('dry-run');
        $only = $this->option('only');

        $scanRoles = match ($only) {
            'driver' => ['driver'],
            'guide'  => ['guide'],
            'stay'   => ['stay'],
            null     => ['driver', 'guide', 'stay'],
            default  => $this->fail('--only must be driver, guide, or stay'),
        };

        $inquiryUpdates = ['driver' => 0, 'guide' => 0];
        $stayUpdates    = 0;

        BookingInquiry::query()
            ->whereNotNull('internal_notes')
            ->where('internal_notes', '!=', '')
            ->chunkById(200, function ($chunk) use ($scanRoles, $dry, &$inquiryUpdates, &$stayUpdates) {
                foreach ($chunk as $inquiry) {
                    $notes = (string) $inquiry->internal_notes;

                    foreach (['driver', 'guide'] as $role) {
                        if (! in_array($role, $scanRoles, true)) continue;
                        if ($inquiry->{$role . '_dispatched_at'}) continue;

                        $ts = $this->latestTimestampFor($notes, $role);
                        if (! $ts) continue;

                        $this->line(sprintf(
                            '  inquiry #%d  %s  %s_dispatched_at ← %s',
                            $inquiry->id,
                            $inquiry->reference ?? '(no ref)',
                            $role,
                            $ts->toDateTimeString()
                        ));

                        if (! $dry) {
                            BookingInquiry::query()
                                ->whereKey($inquiry->id)
                                ->update([$role . '_dispatched_at' => $ts]);
                        }
                        $inquiryUpdates[$role]++;
                    }

                    if (in_array('stay', $scanRoles, true)) {
                        $this->backfillStaysFor($inquiry, $notes, $dry, $stayUpdates);
                    }
                }
            });

        $this->newLine();
        $this->info(sprintf(
            '%s: driver=%d  guide=%d  stay=%d',
            $dry ? 'DRY-RUN' : 'UPDATED',
            $inquiryUpdates['driver'],
            $inquiryUpdates['guide'],
            $stayUpdates
        ));

        return self::SUCCESS;
    }

    /**
     * Walks every audit line in $notes, returning the LATEST timestamp
     * whose text matches "dispatch TG → {$role}". Returns null if none.
     */
    private function latestTimestampFor(string $notes, string $role): ?Carbon
    {
        $pattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2})\][^\n]*?dispatch TG\s*[→\-]>?\s*' . preg_quote($role, '/') . '\b[^\n]*\bok\b/u';

        if (! preg_match_all($pattern, $notes, $matches)) {
            return null;
        }

        $latest = null;
        foreach ($matches[1] as $ts) {
            try {
                $carbon = Carbon::parse($ts);
            } catch (\Throwable) {
                continue;
            }
            if (! $latest || $carbon->gt($latest)) {
                $latest = $carbon;
            }
        }

        return $latest;
    }

    /**
     * Stays are trickier: one inquiry can have multiple stays, and audit
     * lines say "→ stay <accommodation name> ok" which we match against
     * each stay's accommodation->name. If a stay has NO matching line
     * (e.g. name drifted), we leave dispatched_at null — better than
     * mis-attributing.
     */
    private function backfillStaysFor(BookingInquiry $inquiry, string $notes, bool $dry, int &$counter): void
    {
        $stays = $inquiry->stays()->with('accommodation')->get();
        if ($stays->isEmpty()) return;

        foreach ($stays as $stay) {
            if ($stay->dispatched_at) continue;
            $accName = $stay->accommodation?->name;
            if (! $accName) continue;

            $pattern = '/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2})\][^\n]*?dispatch TG\s*[→\-]>?\s*stay\s+' . preg_quote($accName, '/') . '[^\n]*\bok\b/u';
            if (! preg_match_all($pattern, $notes, $m)) continue;

            $latest = null;
            foreach ($m[1] as $ts) {
                try {
                    $c = Carbon::parse($ts);
                } catch (\Throwable) {
                    continue;
                }
                if (! $latest || $c->gt($latest)) $latest = $c;
            }
            if (! $latest) continue;

            $this->line(sprintf(
                '  stay #%d  (%s)  dispatched_at ← %s',
                $stay->id,
                $accName,
                $latest->toDateTimeString()
            ));

            if (! $dry) {
                InquiryStay::query()
                    ->whereKey($stay->id)
                    ->update(['dispatched_at' => $latest]);
            }
            $counter++;
        }
    }
}
