<?php

namespace App\Console\Commands;

use App\Models\GygInboundEmail;
use App\Services\GygEmailClassifier;
use App\Services\GygEmailParser;
use App\Services\OwnerAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GygProcessEmails extends Command
{
    /** Email types that represent real booking actions and must never be silently dropped. */
    public const ACTIONABLE_TYPES = ['new_booking', 'cancellation', 'amendment'];

    protected $signature = 'gyg:process-emails
        {--limit=50 : Maximum emails to process per run}
        {--dry-run : Classify and parse but do not persist changes}
        {--reprocess : Re-process emails in needs_review status}';

    protected $description = 'Classify and extract fields from fetched GYG emails';

    public function __construct(
        private GygEmailClassifier $classifier,
        private GygEmailParser $parser,
        private OwnerAlertService $ownerAlert,
    ) {
        parent::__construct();
    }

    /**
     * A row is considered un-actionable-empty when classifier identified a real
     * booking action but body_text is missing (Gmail body fetch timeout). The
     * classifier works on subject alone, so this catches first-delivery timeouts
     * that would otherwise drop a real booking on the floor.
     */
    public static function isActionableTypeWithEmptyBody(string $emailType, ?string $bodyText): bool
    {
        return in_array($emailType, self::ACTIONABLE_TYPES, true)
            && trim((string) $bodyText) === '';
    }

    public function handle(): int
    {
        $limit     = (int) $this->option('limit');
        $dryRun    = $this->option('dry-run');
        $reprocess = $this->option('reprocess');

        $this->info('[gyg:process-emails] Starting...');

        // Fetch unprocessed emails
        $query = GygInboundEmail::query();
        if ($reprocess) {
            $query->whereIn('processing_status', ['fetched', 'needs_review']);
        } else {
            $query->where('processing_status', 'fetched');
        }
        $emails = $query->orderBy('email_date')->limit($limit)->get();

        $this->info("[gyg:process-emails] Found {$emails->count()} emails to process");

        if ($emails->isEmpty()) {
            return self::SUCCESS;
        }

        // 'needs_refetch' is recoverable (body-timeout retry expected by ops),
        // not an exception — kept separate from 'error' so cron exit stays 0
        // and the schedule's onFailure handler doesn't fire on every timeout.
        $stats = ['classified' => 0, 'parsed' => 0, 'needs_review' => 0, 'skipped' => 0, 'needs_refetch' => 0, 'error' => 0];

        foreach ($emails as $email) {
            try {
                $this->processOne($email, $dryRun, $stats);
            } catch (\Throwable $e) {
                $this->error("  ❌ Exception processing #{$email->id}: {$e->getMessage()}");
                Log::error('gyg:process-emails: exception', [
                    'email_id' => $email->id,
                    'error'    => $e->getMessage(),
                ]);

                if (! $dryRun) {
                    $email->update([
                        'processing_status' => 'failed',
                        'parse_error'       => $e->getMessage(),
                        'parse_attempts'    => $email->parse_attempts + 1,
                    ]);
                }
                $stats['error']++;
            }
        }

        $summary = "gyg:process-emails: done — " . implode(', ', array_map(
            fn ($k, $v) => "{$k}={$v}",
            array_keys($stats),
            array_values($stats),
        ));
        $this->info("[gyg:process-emails] {$summary}");
        Log::info($summary);

        return $stats['error'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processOne(GygInboundEmail $email, bool $dryRun, array &$stats): void
    {
        $subject = $email->email_subject ?? '';
        $from    = $email->email_from ?? '';
        $body    = $email->body_text ?? '';

        // Step 1: Classify
        $emailType = $this->classifier->classify($subject, $from);
        $this->line("  📧 [{$emailType}] " . $this->truncate($subject, 60));

        // Step 1b: Body-missing safety net.
        // The fetcher stores rows with body_text=NULL when the Gmail body read
        // times out. Subject classification still works, so if we get an
        // actionable type (new_booking|cancellation|amendment) without a body,
        // the row CANNOT be parsed/applied — but it must NOT be silently
        // skipped either. Mark 'failed' with a clear parse_error and fire an
        // ops alert so an operator can re-fetch or handle manually.
        if (self::isActionableTypeWithEmptyBody($emailType, $body)) {
            $this->line("  ⚠️  [{$emailType}] body empty — marking failed for re-fetch");
            if (! $dryRun) {
                $email->update([
                    'email_type'        => $emailType,
                    'processing_status' => 'failed',
                    'parse_error'       => 'Body fetch timed out — re-fetch required',
                    'classified_at'     => now(),
                    'parse_attempts'    => $email->parse_attempts + 1,
                ]);

                $this->dispatchEmptyBodyAlert($email, $emailType, $subject);
            }
            $stats['needs_refetch']++;
            return;
        }

        // Skip non-actionable types
        if (in_array($emailType, ['guest_reply', 'unknown'])) {
            if (! $dryRun) {
                $email->update([
                    'email_type'        => $emailType,
                    'processing_status' => 'skipped',
                    'classified_at'     => now(),
                ]);
            }

            // Observability for 'unknown': a silent skip means GYG changed the
            // subject format and we just dropped a real booking on the floor
            // (incident 2026-04-27 — GYG48YVRXWBH was lost for ~5 hours
            // because no alert fired). Log so the daily exception report and
            // log-based alerts catch it.
            if ($emailType === 'unknown') {
                Log::warning('gyg:process-emails: unknown email format — possible classifier gap', [
                    'email_id' => $email->id,
                    'subject'  => $this->truncate($subject, 200),
                    'from'     => $from,
                ]);
            }

            $stats['skipped']++;
            return;
        }

        // Step 2: Extract fields
        $extracted = match ($emailType) {
            'new_booking'  => $this->parser->parseNewBooking($body, $subject),
            'cancellation' => $this->parser->parseCancellation($body, $subject),
            'amendment'    => $this->parser->parseAmendment($body, $subject),
            default        => [],
        };

        $stats['classified']++;

        // Step 3: Validate required fields
        $missingFields = $this->parser->validateRequired($emailType, $extracted);
        $parseErrors   = array_merge($extracted['parse_errors'] ?? [], array_map(
            fn ($f) => "Missing required field: {$f}",
            $missingFields,
        ));

        $isValid = empty($missingFields);

        if ($dryRun) {
            $icon = $isValid ? '✅' : '⚠️';
            $ref   = $extracted['gyg_booking_reference'] ?? 'null';
            $guest = $extracted['guest_name'] ?? 'null';
            $date  = $extracted['travel_date'] ?? 'null';
            $this->line("    {$icon} ref={$ref} guest={$guest} date={$date}");
            if (! empty($extracted['option_title'])) {
                $this->line("    📦 option: {$extracted['option_title']}");
            }
            if (! empty($extracted['tour_type'])) {
                $this->line("    🏷 type={$extracted['tour_type']}({$extracted['tour_type_source']}) guide={$extracted['guide_status']}({$extracted['guide_status_source']})");
            }
            if (! $isValid) {
                $this->line("    ⚠️ Missing: " . implode(', ', $missingFields));
            }
            $stats[$isValid ? 'parsed' : 'needs_review']++;
            return;
        }

        // Step 4: Persist extracted fields
        $updateData = [
            'email_type'            => $emailType,
            'gyg_booking_reference' => $extracted['gyg_booking_reference'] ?? null,
            'tour_name'             => $extracted['tour_name'] ?? null,
            'option_title'          => $extracted['option_title'] ?? null,
            'guest_name'            => $extracted['guest_name'] ?? null,
            'guest_email'           => $extracted['guest_email'] ?? null,
            'guest_phone'           => $extracted['guest_phone'] ?? null,
            'travel_date'           => $extracted['travel_date'] ?? null,
            'travel_time'           => $extracted['travel_time'] ?? null,
            'pax'                   => $extracted['pax'] ?? null,
            'price'                 => $extracted['price'] ?? null,
            'currency'              => $extracted['currency'] ?? null,
            'language'              => $extracted['language'] ?? null,
            'tour_type'             => $extracted['tour_type'] ?? null,
            'tour_type_source'      => $extracted['tour_type_source'] ?? null,
            'guide_status'          => $extracted['guide_status'] ?? null,
            'guide_status_source'   => $extracted['guide_status_source'] ?? null,
            'classified_at'         => now(),
            'parsed_at'             => now(),
            'parse_attempts'        => $email->parse_attempts + 1,
        ];

        if ($isValid) {
            $updateData['processing_status'] = 'parsed';
            $stats['parsed']++;

            // Soft guardrail: even when validation passed, flag suspicious
            // gaps that auto-defaults can mask (defaulted tour_type without
            // any group/private signal in titles is the common one).
            // Loud enough for log-based alerting, quiet enough not to block.
            $softGaps = [];
            if (empty($extracted['language'])) {
                $softGaps[] = 'language';
            }
            if (($extracted['tour_type_source'] ?? null) === 'defaulted') {
                $softGaps[] = 'tour_type(defaulted)';
            }
            if (! empty($softGaps)) {
                Log::warning('gyg:process-emails: parsed with soft gaps', [
                    'email_id' => $email->id,
                    'gaps'     => $softGaps,
                    'subject'  => $this->truncate($subject, 100),
                ]);
            }
        } else {
            $updateData['processing_status'] = 'needs_review';
            $updateData['parse_error'] = implode('; ', $parseErrors);
            $stats['needs_review']++;
            Log::warning('gyg:process-emails: needs_review', [
                'email_id' => $email->id,
                'missing'  => $missingFields,
                'subject'  => $this->truncate($subject, 100),
            ]);
        }

        $email->update($updateData);

        Log::info('gyg:process-emails: processed', [
            'email_id'  => $email->id,
            'type'      => $emailType,
            'status'    => $updateData['processing_status'],
            'reference' => $extracted['gyg_booking_reference'] ?? null,
        ]);
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) : $text;
    }

    /**
     * Notify ops that a real booking-action email arrived without a body.
     * Loud alert (error log + Telegram via OwnerAlertService) so the row
     * cannot disappear silently. Failure to send must NOT abort processing.
     */
    private function dispatchEmptyBodyAlert(GygInboundEmail $email, string $emailType, string $subject): void
    {
        Log::error('gyg:process-emails: actionable email with empty body — re-fetch required', [
            'email_id' => $email->id,
            'type'     => $emailType,
            'subject'  => $this->truncate($subject, 200),
        ]);

        try {
            $this->ownerAlert->sendOpsAlert(sprintf(
                "⚠️ GYG %s email arrived without body (Gmail timeout).\n"
                . "Email ID: %d\n"
                . "Subject: %s\n"
                . "Action: re-fetch the body or handle manually — auto-apply was blocked to prevent silent loss.",
                $emailType,
                $email->id,
                $this->truncate($subject, 150),
            ));
        } catch (\Throwable $e) {
            Log::warning('gyg:process-emails: ops alert dispatch failed', [
                'email_id' => $email->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
