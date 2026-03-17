<?php

namespace App\Console\Commands;

use App\Models\GygInboundEmail;
use App\Services\GygBookingApplicator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GygApplyBookings extends Command
{
    protected $signature = 'gyg:apply-bookings
        {--limit=50 : Maximum emails to apply per run}
        {--dry-run : Show what would be applied without persisting}';

    protected $description = 'Apply parsed GYG new_booking emails into the main booking tables';

    public function __construct(
        private GygBookingApplicator $applicator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('[gyg:apply-bookings] Starting...');

        $emails = GygInboundEmail::where('email_type', 'new_booking')
            ->where('processing_status', 'parsed')
            ->orderBy('email_date')
            ->limit($limit)
            ->get();

        $this->info("[gyg:apply-bookings] Found {$emails->count()} parsed new_booking emails to apply");

        if ($emails->isEmpty()) {
            return self::SUCCESS;
        }

        $stats = ['applied' => 0, 'idempotent' => 0, 'failed' => 0];

        foreach ($emails as $email) {
            $ref = $email->gyg_booking_reference ?? 'unknown';

            if ($dryRun) {
                $this->line("  🆕 [DRY-RUN] Would apply: {$ref} — {$email->guest_name} on {$email->travel_date}");
                $stats['applied']++;
                continue;
            }

            try {
                $result = $this->applicator->applyNewBooking($email);

                if ($result['applied']) {
                    if ($result['skipped_reason'] === 'already_exists') {
                        $this->line("  ⏭ Idempotent: {$ref} — booking #{$result['booking_id']} already exists");
                        $stats['idempotent']++;
                    } else {
                        $this->line("  ✅ Applied: {$ref} → booking #{$result['booking_id']}");
                        $stats['applied']++;
                    }
                } else {
                    $errorMsg = $result['error'] ?? 'Unknown error';
                    $this->error("  ❌ Failed: {$ref} — {$errorMsg}");

                    $email->update([
                        'processing_status' => 'failed',
                        'apply_error'       => $errorMsg,
                    ]);
                    $stats['failed']++;
                }
            } catch (\Throwable $e) {
                $this->error("  ❌ Exception: {$ref} — {$e->getMessage()}");
                Log::error('gyg:apply-bookings: exception', [
                    'email_id' => $email->id,
                    'ref'      => $ref,
                    'error'    => $e->getMessage(),
                ]);

                $email->update([
                    'processing_status' => 'failed',
                    'apply_error'       => $e->getMessage(),
                ]);
                $stats['failed']++;
            }
        }

        $summary = "gyg:apply-bookings: done — " . implode(', ', array_map(
            fn ($k, $v) => "{$k}={$v}",
            array_keys($stats),
            array_values($stats),
        ));
        $this->info("[gyg:apply-bookings] {$summary}");
        Log::info($summary);

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
