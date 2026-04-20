<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Leads\IngestEmailAsLead;
use App\Services\Zoho\ZohoMailInboundClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchZohoLeadEmails extends Command
{
    protected $signature = 'leads:fetch-zoho-emails
                            {--dry-run : Parse and decide, but write nothing and leave the mailbox untouched}';

    protected $description = 'Fetch unseen inbound emails from Zoho Mail and ingest them as leads.';

    public function handle(ZohoMailInboundClient $client, IngestEmailAsLead $ingestor): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! config('zoho.lead_email_ingestion_enabled')) {
            $this->warn('Lead email ingestion is disabled (LEAD_EMAIL_INGESTION_ENABLED=false). Exiting.');

            return self::SUCCESS;
        }

        $batch = (int) config('zoho.mail_inbound.batch_size', 25);
        $messages = $client->unseenMessages($batch);

        if ($messages->isEmpty()) {
            $this->info('No unseen messages.');

            return self::SUCCESS;
        }

        $counts = [
            'fetched'                     => $messages->count(),
            'created_new_lead'            => 0,
            'appended_with_new_followup'  => 0,
            'appended_to_existing_open'   => 0,
            'skipped_duplicate'           => 0,
            'skipped_no_sender'           => 0,
            'skipped_blocklist'           => 0,
            'ambiguous'                   => 0,
            'failed'                      => 0,
        ];

        foreach ($messages as $email) {
            try {
                $result = $ingestor->handle($email, $dryRun);
                $counts[$result['decision']] = ($counts[$result['decision']] ?? 0) + 1;

                if ($dryRun) {
                    $this->line(sprintf(
                        '[dry-run] %-30s sender=%-35s subject=%s',
                        $result['decision'],
                        $email->senderEmail ?? '(none)',
                        mb_strimwidth($email->subject, 0, 50, '…'),
                    ));

                    continue;
                }

                // Mailbox mutation ONLY after DB write succeeded. Processed +
                // skipped_blocklist leave INBOX; everything else stays unread
                // for human reconciliation.
                $shouldMarkProcessed = in_array($result['decision'], [
                    IngestEmailAsLead::DECISION_CREATED_NEW_LEAD,
                    IngestEmailAsLead::DECISION_APPENDED_NO_FOLLOW,
                    IngestEmailAsLead::DECISION_APPENDED_EXISTING,
                    IngestEmailAsLead::DECISION_SKIPPED_BLOCKLIST,
                ], true);

                if ($shouldMarkProcessed && $email->uid !== null) {
                    $client->markProcessed($email->uid);
                }
            } catch (Throwable $e) {
                $counts['failed']++;
                Log::error('leads:fetch-zoho-emails failed on message', [
                    'message_id' => $email->messageId,
                    'sender'     => $email->senderEmail,
                    'error'      => $e->getMessage(),
                ]);
                $this->error("Failed message_id={$email->messageId}: {$e->getMessage()}");
            }
        }

        $this->info('leads:fetch-zoho-emails'.($dryRun ? ' [dry-run]' : '').' — '.json_encode($counts));
        Log::info('leads:fetch-zoho-emails completed', $counts + ['dry_run' => $dryRun]);

        return self::SUCCESS;
    }
}
