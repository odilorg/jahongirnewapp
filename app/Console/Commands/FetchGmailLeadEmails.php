<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Services\Gmail\GmailLeadInboundClient;
use App\Services\Gmail\GmailLeadQualifier;
use Illuminate\Console\Command;

/**
 * Gmail -> CRM lead ingestion.
 *
 * PHASE 1 (this version): --dry-run ONLY. It reads the dedicated lead label,
 * qualifies each message, and reports the decision it WOULD make. It writes
 * nothing to the DB and never mutates the mailbox (himalaya `--preview`). The
 * write path (create booking_inquiries + ledger + mark-processed) is Phase 2,
 * behind config('gmail_leads.ingestion_enabled').
 */
class FetchGmailLeadEmails extends Command
{
    protected $signature = 'leads:fetch-gmail-emails
        {--dry-run : Inspect + report decisions, write nothing, leave the mailbox untouched}
        {--limit=50 : Maximum messages to inspect per run}';

    protected $description = 'Ingest qualifying Gmail contact-form/direct emails as booking inquiries (Phase 1: --dry-run only)';

    public function handle(): int
    {
        if (! $this->option('dry-run')) {
            $this->error('Phase 1: only --dry-run is implemented (no writes yet). Re-run with --dry-run.');

            return self::FAILURE;
        }

        $label = (string) config('gmail_leads.label');
        $client = new GmailLeadInboundClient(
            (string) config('gmail_leads.himalaya_account', 'gmail'),
            $label,
        );
        $qualifier = new GmailLeadQualifier(
            (array) config('gmail_leads.website_notifier_senders', []),
            (array) config('gmail_leads.sender_blocklist', []),
        );

        $envelopes = $client->labeledEnvelopes((int) $this->option('limit'));
        $this->info("[DRY-RUN] label='{$label}': " . count($envelopes) . ' message(s) to inspect.');

        $counts = [];
        foreach ($envelopes as $env) {
            try {
                $email = $client->toInboundEmail($env, $client->readRaw($env['id']));
                $decision = $qualifier->qualify($email);

                $key = $decision->qualifies ? "would_create:{$decision->kind}" : "skip:{$decision->rejectReason}";
                $note = '';

                // Read-only dedup preview: would this collide with an in-flight inquiry?
                if ($decision->qualifies && ($decision->guest['email'] ?? '') !== '') {
                    $dups = BookingInquiry::findInFlightDuplicates(null, $decision->guest['email']);
                    if ($dups->isNotEmpty()) {
                        $key = 'skip:duplicate_inquiry';
                        $note = ' (existing #' . $dups->first()->id . ')';
                    }
                }

                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $this->line(sprintf(
                    '  [%s]%s subj="%s"%s',
                    $key,
                    $note,
                    mb_substr($email->subject !== '' ? $email->subject : '—', 0, 45),
                    $decision->qualifies
                        ? ' name="' . ($decision->guest['name'] ?? '') . '" email=' . ($decision->guest['email'] ?? '')
                        : '',
                ));
            } catch (\Throwable $e) {
                $counts['error'] = ($counts['error'] ?? 0) + 1;
                $this->warn('  ✗ ' . $env['id'] . ': ' . $e->getMessage());
            }
        }

        $this->info('Decisions: ' . json_encode($counts));
        $this->info('No DB writes, no mailbox changes (--preview). Phase 1 dry-run only.');

        return self::SUCCESS;
    }
}
