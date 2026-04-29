<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Support\OperationalFlagExtractor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot (and re-runnable) backfill: derive the four operational flag
 * booleans for every existing inquiry by passing `operational_notes`
 * through OperationalFlagExtractor (single source of truth, also used by
 * QuickSaveOperationalNotesAction).
 *
 * MANDATORY: --dry-run is the default. Real writes require --apply AND
 * happen in chunks with progress reporting. Per operator constraint:
 *   1. Run --dry-run first.
 *   2. Inspect the distribution table.
 *   3. Only run --apply off-peak if the counts look sane.
 *
 * Idempotent. Re-running --apply on already-backfilled rows is a no-op
 * unless the keyword set has changed (legitimate re-run after extractor
 * tuning).
 */
class InquiryBackfillOperationalFlags extends Command
{
    protected $signature = 'inquiries:backfill-operational-flags
                            {--apply : Persist changes (omit for dry-run; dry-run is the default)}
                            {--chunk=200 : Inquiries per processing chunk}';

    protected $description = 'Derive has_*_flag booleans from operational_notes for every existing booking inquiry. Default mode is dry-run.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $mode = $apply ? 'APPLY (writes)' : 'DRY-RUN (no writes)';
        $this->info("Mode: {$mode}");

        $totals = [
            'scanned'    => 0,
            'with_notes' => 0,
            'changed'    => 0,
            'unchanged'  => 0,
        ];
        $flagCounts = [
            'dietary'       => 0,
            'accessibility' => 0,
            'language'      => 0,
            'occasion'      => 0,
        ];

        BookingInquiry::query()
            ->orderBy('id')
            ->chunk($chunkSize, function ($inquiries) use ($apply, &$totals, &$flagCounts) {
                foreach ($inquiries as $inquiry) {
                    $totals['scanned']++;

                    $notes = $inquiry->operational_notes;
                    if (filled($notes)) {
                        $totals['with_notes']++;
                    }

                    $flags = OperationalFlagExtractor::extract($notes);
                    foreach ($flags as $key => $value) {
                        if ($value) {
                            $flagCounts[$key]++;
                        }
                    }

                    $current = [
                        'dietary'       => (bool) $inquiry->has_dietary_flag,
                        'accessibility' => (bool) $inquiry->has_accessibility_flag,
                        'language'      => (bool) $inquiry->has_language_flag,
                        'occasion'      => (bool) $inquiry->has_occasion_flag,
                    ];

                    if ($current === $flags) {
                        $totals['unchanged']++;
                        continue;
                    }

                    $totals['changed']++;

                    if (! $apply) {
                        continue;
                    }

                    // Bypass updated_at bump — flag derivation is bookkeeping,
                    // not an operator action. Mirrors BackfillDispatchTimestamps.
                    DB::table('booking_inquiries')
                        ->where('id', $inquiry->id)
                        ->update([
                            'has_dietary_flag'       => $flags['dietary'],
                            'has_accessibility_flag' => $flags['accessibility'],
                            'has_language_flag'      => $flags['language'],
                            'has_occasion_flag'      => $flags['occasion'],
                        ]);
                }
            });

        $this->newLine();
        $this->line('=== Distribution ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total scanned',           $totals['scanned']],
                ['With operational_notes',  $totals['with_notes']],
                ['Would change / changed',  $totals['changed']],
                ['Already in sync',         $totals['unchanged']],
                ['Flag: dietary',           $flagCounts['dietary']],
                ['Flag: accessibility',     $flagCounts['accessibility']],
                ['Flag: language',          $flagCounts['language']],
                ['Flag: occasion',          $flagCounts['occasion']],
            ],
        );

        if (! $apply) {
            $this->newLine();
            $this->warn('DRY-RUN — no changes persisted. Re-run with --apply once the distribution looks sane.');
        } else {
            $this->info('Backfill applied.');
        }

        return self::SUCCESS;
    }
}
