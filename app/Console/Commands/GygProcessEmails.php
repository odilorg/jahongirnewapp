<?php

namespace App\Console\Commands;

use App\Models\GygInboundEmail;
use App\Services\GygEmailClassifier;
use App\Services\GygEmailParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GygProcessEmails extends Command
{
    protected $signature = 'gyg:process-emails
        {--limit=50 : Maximum emails to process per run}
        {--dry-run : Classify and parse but do not persist changes}
        {--reprocess : Re-process emails in needs_review status}';

    protected $description = 'Classify and extract fields from fetched GYG emails';

    public function __construct(
        private GygEmailClassifier $classifier,
        private GygEmailParser $parser,
    ) {
        parent::__construct();
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

        $stats = ['classified' => 0, 'parsed' => 0, 'needs_review' => 0, 'skipped' => 0, 'error' => 0];

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

        // Skip non-actionable types
        if (in_array($emailType, ['guest_reply', 'unknown'])) {
            if (! $dryRun) {
                $email->update([
                    'email_type'        => $emailType,
                    'processing_status' => 'skipped',
                    'classified_at'     => now(),
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
            $date  = $extracted['tour_date'] ?? 'null';
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
            'tour_date'             => $extracted['tour_date'] ?? null,
            'tour_time'             => $extracted['tour_time'] ?? null,
            'number_of_guests'      => $extracted['number_of_guests'] ?? null,
            'total_price'           => $extracted['total_price'] ?? null,
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
}
