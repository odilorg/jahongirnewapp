<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GygInboundEmail;
use App\Services\Gyg\GygInquiryWriter;
use App\Services\GygNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Stage 3 of the GYG email pipeline — apply parsed emails into
 * booking_inquiries via GygInquiryWriter.
 *
 * Replaces the legacy GygBookingApplicator which wrote into the dead
 * `bookings` + `guests` tables. Now targets the live booking_inquiries
 * table exclusively.
 */
class GygApplyBookings extends Command
{
    protected $signature = 'gyg:apply-bookings
        {--limit=50 : Maximum emails to apply per run}
        {--dry-run : Show what would be applied without persisting}';

    protected $description = 'Apply parsed GYG emails into booking_inquiries';

    public function __construct(
        private GygInquiryWriter $writer,
        private GygNotifier $notifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('[gyg:apply-bookings] Starting...');

        $emails = GygInboundEmail::whereIn('email_type', ['new_booking', 'cancellation', 'amendment'])
            ->where('processing_status', 'parsed')
            ->orderBy('email_date')
            ->limit($limit)
            ->get();

        $this->info("[gyg:apply-bookings] Found {$emails->count()} emails to apply");

        if ($emails->isEmpty()) {
            return self::SUCCESS;
        }

        $stats = ['created' => 0, 'cancelled' => 0, 'review' => 0, 'duplicate' => 0, 'failed' => 0];

        foreach ($emails as $email) {
            try {
                $this->processOne($email, $dryRun, $stats);
            } catch (\Throwable $e) {
                $ref = $email->gyg_booking_reference ?? 'unknown';
                $this->error("  ❌ Exception: {$ref} — {$e->getMessage()}");
                Log::error('gyg:apply-bookings: exception', [
                    'email_id' => $email->id,
                    'ref'      => $ref,
                    'error'    => $e->getMessage(),
                ]);

                if (! $dryRun) {
                    $email->update([
                        'processing_status' => 'failed',
                        'apply_error'       => $e->getMessage(),
                    ]);
                }
                $stats['failed']++;
            }
        }

        // Notify for any needs_review emails that haven't been notified yet
        if (! $dryRun) {
            $this->notifyPendingReviews();
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

    private function processOne(GygInboundEmail $email, bool $dryRun, array &$stats): void
    {
        $ref  = $email->gyg_booking_reference ?? 'unknown';
        $type = $email->email_type;

        if ($dryRun) {
            $this->line("  🆕 [DRY-RUN] Would apply {$type}: {$ref} — {$email->guest_name}");
            $stats['created']++;

            return;
        }

        match ($type) {
            'new_booking'  => $this->applyNewBooking($email, $ref, $stats),
            'cancellation' => $this->applyCancellation($email, $ref, $stats),
            'amendment'    => $this->applyAmendment($email, $ref, $stats),
            default        => null,
        };
    }

    private function applyNewBooking(GygInboundEmail $email, string $ref, array &$stats): void
    {
        $result = $this->writer->createFromInboundEmail($email);

        if ($result['created']) {
            $this->line("  ✅ Created: {$ref} → inquiry #{$result['inquiry_id']}");
            $stats['created']++;

            $this->notifier->notifyIfNeeded($email, 'new_booking', [
                'inquiry_id' => $result['inquiry_id'],
            ]);
        } elseif ($result['skipped_reason']) {
            $this->line("  ⏭ Duplicate: {$ref} ({$result['skipped_reason']})");
            $stats['duplicate']++;
        } else {
            $this->error("  ❌ Failed: {$ref} — " . ($result['error'] ?? 'Unknown'));
            $email->update([
                'processing_status' => 'failed',
                'apply_error'       => $result['error'],
            ]);
            $stats['failed']++;
        }
    }

    private function applyCancellation(GygInboundEmail $email, string $ref, array &$stats): void
    {
        $result = $this->writer->cancelFromInboundEmail($email);

        if ($result['cancelled']) {
            if ($result['skipped_reason'] === 'already_cancelled') {
                $this->line("  ⏭ Already cancelled: {$ref}");
                $stats['duplicate']++;
            } else {
                $this->line("  🚫 Cancelled: {$ref} → inquiry #{$result['inquiry_id']}");
                $stats['cancelled']++;

                $this->notifier->notifyIfNeeded($email, 'cancellation', [
                    'inquiry_id' => $result['inquiry_id'],
                ]);
            }
        } else {
            $this->warn("  ⚠️ Cancel target not found: {$ref}");
            $stats['review']++;
        }
    }

    private function applyAmendment(GygInboundEmail $email, string $ref, array &$stats): void
    {
        $this->writer->flagAmendmentForReview($email);

        $this->warn("  ✏️ Amendment → needs review: {$ref}");

        $this->notifier->notifyIfNeeded($email, 'amendment', [
            'inquiry_id' => $email->booking_inquiry_id,
            'reason'     => 'Amendment requires manual review',
        ]);
        $stats['review']++;
    }

    private function notifyPendingReviews(): void
    {
        $pending = GygInboundEmail::where('processing_status', 'needs_review')
            ->whereNull('notified_at')
            ->limit(20)
            ->get();

        foreach ($pending as $email) {
            $this->notifier->notifyIfNeeded($email, 'needs_review', [
                'reason' => $email->parse_error ?? $email->apply_error ?? 'Unknown reason',
            ]);
        }

        if ($pending->isNotEmpty()) {
            $this->info("[gyg:apply-bookings] Sent {$pending->count()} pending review notifications");
        }
    }
}
