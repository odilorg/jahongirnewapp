<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GygInboundEmail;
use App\Services\Gyg\GygInquiryWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Controlled replay of GYG inbound emails through the inquiry writer.
 *
 * Unlike the normal pipeline (which processes only status=parsed), this
 * command re-processes emails that were already applied to the legacy
 * bookings table, or that landed in needs_review / failed states.
 *
 * Dedup by (source, external_reference) makes this idempotent:
 * inquiries already imported (from XLSX, Google Sheet, or prior runs)
 * are safely skipped.
 *
 * Usage:
 *   php artisan gyg:replay-inbound --status=applied          # re-apply legacy-applied
 *   php artisan gyg:replay-inbound --status=needs_review     # retry review items
 *   php artisan gyg:replay-inbound --ref=GYGX7NLH94W8       # single booking
 *   php artisan gyg:replay-inbound --status=applied --dry-run
 */
class GygReplayInboundEmails extends Command
{
    protected $signature = 'gyg:replay-inbound
        {--status=* : Process emails in these statuses (required unless --ref is used)}
        {--ref= : Replay a single GYG booking reference}
        {--dry-run : Show what would happen without writing}
        {--limit=50 : Max emails to replay}';

    protected $description = 'Replay historic GYG inbound emails through the inquiry writer';

    public function __construct(
        private GygInquiryWriter $writer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $statuses = array_filter((array) $this->option('status'));
        $ref      = $this->option('ref');
        $dryRun   = (bool) $this->option('dry-run');
        $limit    = (int) $this->option('limit');

        if (empty($statuses) && ! $ref) {
            $this->error('Specify --status=<status> or --ref=<GYG ref>. No default statuses to prevent accidental bulk replay.');

            return self::FAILURE;
        }

        $query = GygInboundEmail::query()
            ->whereIn('email_type', ['new_booking', 'cancellation', 'amendment']);

        if ($ref) {
            $query->where('gyg_booking_reference', $ref);
        } elseif (! empty($statuses)) {
            $query->whereIn('processing_status', $statuses);
        }

        $emails = $query->orderBy('email_date')->limit($limit)->get();

        $this->info("[gyg:replay-inbound] Found {$emails->count()} emails to replay");

        if ($emails->isEmpty()) {
            return self::SUCCESS;
        }

        $stats = ['created' => 0, 'cancelled' => 0, 'review' => 0, 'duplicate' => 0, 'failed' => 0];

        foreach ($emails as $email) {
            $emailRef  = $email->gyg_booking_reference ?? 'unknown';
            $emailType = $email->email_type;

            if ($dryRun) {
                $this->line("  [DRY-RUN] Would replay {$emailType}: {$emailRef} — {$email->guest_name}");
                $stats['created']++;

                continue;
            }

            try {
                $result = match ($emailType) {
                    'new_booking'  => $this->writer->createFromInboundEmail($email),
                    'cancellation' => $this->writer->cancelFromInboundEmail($email),
                    'amendment'    => $this->writer->flagAmendmentForReview($email),
                    default        => null,
                };

                if ($result === null) {
                    continue;
                }

                // Categorize the result
                if (isset($result['created']) && $result['created']) {
                    $this->line("  ✅ Created: {$emailRef} → inquiry #{$result['inquiry_id']}");
                    $stats['created']++;
                } elseif (isset($result['cancelled']) && $result['cancelled']) {
                    $this->line("  🚫 Cancelled: {$emailRef}");
                    $stats['cancelled']++;
                } elseif (isset($result['flagged']) && $result['flagged']) {
                    $this->line("  ✏️ Amendment flagged: {$emailRef}");
                    $stats['review']++;
                } elseif (isset($result['skipped_reason'])) {
                    $this->line("  ⏭ Skipped: {$emailRef} ({$result['skipped_reason']})");
                    $stats['duplicate']++;
                } else {
                    $this->warn("  ⚠️ Unhandled: {$emailRef} — " . json_encode($result));
                    $stats['failed']++;
                }
            } catch (\Throwable $e) {
                $this->error("  ❌ Exception: {$emailRef} — {$e->getMessage()}");
                Log::error('gyg:replay-inbound: exception', [
                    'email_id' => $email->id,
                    'ref'      => $emailRef,
                    'error'    => $e->getMessage(),
                ]);
                $stats['failed']++;
            }
        }

        $summary = implode(', ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($stats), array_values($stats)));
        $this->info("[gyg:replay-inbound] Done — {$summary}");
        Log::info("gyg:replay-inbound: done — {$summary}");

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
