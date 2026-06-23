<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BookingInquiries\IngestGmailEmailAsInquiry;
use App\Models\BookingInquiry;
use App\Models\GmailLeadIngestion;
use App\Services\Gmail\GmailLeadInboundClient;
use App\Services\Gmail\GmailLeadQualifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Gmail -> CRM lead ingestion (Phase 2).
 *
 *   --dry-run : inspect + report decisions, write NOTHING, mailbox untouched.
 *   live      : gated by config('gmail_leads.ingestion_enabled') (default OFF);
 *               creates booking_inquiries for qualifying mail, records a ledger
 *               row per message (idempotent), and moves CREATED messages to the
 *               processed sublabel ONLY after the DB write succeeds.
 *
 * Read-only / qualification lives in GmailLeadQualifier; DB effects in
 * IngestGmailEmailAsInquiry; the only mailbox mutation is client->markProcessed.
 */
class FetchGmailLeadEmails extends Command
{
    protected $signature = 'leads:fetch-gmail-emails
        {--dry-run : Inspect + report decisions, write nothing, leave the mailbox untouched}
        {--limit=50 : Maximum messages to inspect per run}';

    protected $description = 'Ingest qualifying Gmail contact-form/direct emails as booking inquiries';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! (bool) config('gmail_leads.ingestion_enabled')) {
            $this->warn('Gmail lead ingestion is disabled (GMAIL_LEAD_INGESTION_ENABLED=false). Nothing done.');

            return self::SUCCESS;
        }

        $folder = (string) config('gmail_leads.folder', 'INBOX');
        $query = (string) config('gmail_leads.sender_query', '');
        $client = new GmailLeadInboundClient(
            (string) config('gmail_leads.himalaya_account', 'gmail'),
            $folder,
        );
        $qualifier = new GmailLeadQualifier(
            (array) config('gmail_leads.website_notifier_senders', []),
            (array) config('gmail_leads.sender_blocklist', []),
            (bool) config('gmail_leads.free_form_enabled', false),
        );
        $action = new IngestGmailEmailAsInquiry($qualifier);

        $envelopes = $client->labeledEnvelopes((int) $this->option('limit'), $query);
        $this->info(($dryRun ? '[DRY-RUN] ' : '')
            . "folder='{$folder}' query='" . ($query !== '' ? $query : '(none)') . "': "
            . count($envelopes) . ' message(s) to inspect.');

        $counts = [];
        foreach ($envelopes as $env) {
            try {
                $email = $client->toInboundEmail($env, $client->readRaw($env['id']));

                if ($dryRun) {
                    $key = $this->dryRunDecision($qualifier, $email);
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                    $this->line("  [{$key}] subj=\"" . mb_substr($email->subject !== '' ? $email->subject : '—', 0, 45) . '"');

                    continue;
                }

                $res = $action->ingest($email);
                $counts[$res['decision']] = ($counts[$res['decision']] ?? 0) + 1;

                // Inbox mode (default) does NOT move messages — idempotency is the
                // ledger, so we never mutate the mailbox. Only label mode moves.
                if ($res['move'] && (bool) config('gmail_leads.move_processed', false)) {
                    $client->markProcessed($env['id'], (string) config('gmail_leads.processed_folder'));
                }

                Log::info('FetchGmailLeadEmails: processed', [
                    'decision'     => $res['decision'],
                    'inquiry_id'   => $res['inquiry_id'],
                    'ingestion_id' => $res['ingestion_id'],
                ]);
            } catch (\Throwable $e) {
                $counts['error'] = ($counts['error'] ?? 0) + 1;
                $this->warn('  ✗ ' . $env['id'] . ': ' . $e->getMessage());
                Log::warning('FetchGmailLeadEmails: failed on envelope', [
                    'envelope_id' => $env['id'],
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        $this->info('Decisions: ' . json_encode($counts));
        if ($dryRun) {
            $this->info('No DB writes, no mailbox changes (--preview). Dry-run only.');
        }

        return self::SUCCESS;
    }

    /** Read-only decision + in-flight-duplicate preview (no writes). */
    private function dryRunDecision(GmailLeadQualifier $qualifier, \App\Services\Gmail\GmailInboundEmail $email): string
    {
        $d = $qualifier->qualify($email);
        if (! $d->qualifies) {
            return 'skip:' . $d->rejectReason;
        }
        if (($d->guest['email'] ?? '') !== ''
            && BookingInquiry::findInFlightDuplicates(null, $d->guest['email'])->isNotEmpty()) {
            return 'skip:duplicate_inquiry';
        }

        return 'would_create:' . $d->kind;
    }
}
