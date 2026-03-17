<?php

namespace App\Console\Commands;

use App\Models\GygInboundEmail;
use App\Services\GygBookingApplicator;
use App\Services\GygNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GygApplyBookings extends Command
{
    protected $signature = 'gyg:apply-bookings
        {--limit=50 : Maximum emails to apply per run}
        {--dry-run : Show what would be applied without persisting}';

    protected $description = 'Apply parsed GYG emails (bookings, cancellations, amendments) and send owner notifications';

    public function __construct(
        private GygBookingApplicator $applicator,
        private GygNotifier $notifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $dryRun = $this->option('dry-run');

        $this->info('[gyg:apply-bookings] Starting...');

        // Fetch parsable emails: new_booking (parsed) + cancellation (parsed) + amendment (parsed)
        $emails = GygInboundEmail::whereIn('email_type', ['new_booking', 'cancellation', 'amendment'])
            ->where('processing_status', 'parsed')
            ->orderBy('email_date')
            ->limit($limit)
            ->get();

        $this->info("[gyg:apply-bookings] Found {$emails->count()} emails to apply");

        if ($emails->isEmpty()) {
            return self::SUCCESS;
        }

        $stats = ['applied' => 0, 'cancelled' => 0, 'review' => 0, 'idempotent' => 0, 'failed' => 0];

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

                    $this->notifier->notifyIfNeeded($email, 'apply_failure', [
                        'error' => $e->getMessage(),
                    ]);
                }
                $stats['failed']++;
            }
        }

        // Also notify for any existing needs_review emails that haven't been notified yet
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
            $stats['applied']++;
            return;
        }

        // Reset notification state so reprocessed emails get fresh notifications
        // (prevents failure notification from blocking success notification on retry)
        if ($email->notified_at !== null) {
            $email->update(['notified_at' => null]);
        }

        $result = match ($type) {
            'new_booking'  => $this->applyNewBooking($email, $ref, $stats),
            'cancellation' => $this->applyCancellation($email, $ref, $stats),
            'amendment'    => $this->applyAmendment($email, $ref, $stats),
            default        => null,
        };
    }

    private function applyNewBooking(GygInboundEmail $email, string $ref, array &$stats): void
    {
        $result = $this->applicator->applyNewBooking($email);

        if ($result['applied']) {
            if ($result['skipped_reason'] === 'already_exists') {
                $this->line("  ⏭ Idempotent: {$ref} — booking #{$result['booking_id']}");
                $stats['idempotent']++;
            } else {
                $this->line("  ✅ Applied: {$ref} → booking #{$result['booking_id']}");
                $stats['applied']++;

                $this->notifier->notifyIfNeeded($email, 'new_booking', [
                    'booking_id' => $result['booking_id'],
                ]);
            }
        } else {
            $this->error("  ❌ Failed: {$ref} — " . ($result['error'] ?? 'Unknown'));
            $email->update([
                'processing_status' => 'failed',
                'apply_error'       => $result['error'],
            ]);

            $this->notifier->notifyIfNeeded($email, 'apply_failure', [
                'error' => $result['error'],
            ]);
            $stats['failed']++;
        }
    }

    private function applyCancellation(GygInboundEmail $email, string $ref, array &$stats): void
    {
        $result = $this->applicator->applyCancellation($email);

        if ($result['applied']) {
            if ($result['skipped_reason'] === 'already_cancelled') {
                $this->line("  ⏭ Already cancelled: {$ref}");
                $stats['idempotent']++;
            } else {
                $this->line("  ❌ Cancelled: {$ref} → booking #{$result['booking_id']}");
                $stats['cancelled']++;

                $this->notifier->notifyIfNeeded($email, 'cancellation', [
                    'booking_id' => $result['booking_id'],
                ]);
            }
        } else {
            $errorMsg = $result['error'] ?? 'Unknown';
            $this->warn("  ⚠️ Cancel failed: {$ref} — {$errorMsg}");

            $this->notifier->notifyIfNeeded($email, 'needs_review', [
                'reason' => "Cancellation failed: {$errorMsg}",
            ]);
            $stats['review']++;
        }
    }

    private function applyAmendment(GygInboundEmail $email, string $ref, array &$stats): void
    {
        $result = $this->applicator->handleAmendment($email);

        $this->warn("  ✏️ Amendment → needs review: {$ref}");

        $this->notifier->notifyIfNeeded($email, 'amendment', [
            'booking_id' => $result['booking_id'],
            'reason'     => 'Amendment requires manual review',
        ]);
        $stats['review']++;
    }

    /**
     * Notify owner about any needs_review emails that haven't been notified yet.
     * This catches emails that were marked needs_review by the parser (Phase 4)
     * but never had a notification sent.
     */
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
