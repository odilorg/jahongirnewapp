<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Leads\IngestWhatsAppAsLead;
use App\Models\LeadWhatsAppIngestion;
use App\Services\Wacli\WacliRemoteClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchWacliMessages extends Command
{
    protected $signature = 'leads:fetch-wacli-messages
                            {--dry-run : Parse and decide; write nothing, make no remote state changes}';

    protected $description = 'Pull unseen WhatsApp messages from vps-main wacli and ingest them as leads.';

    public function handle(WacliRemoteClient $client, IngestWhatsAppAsLead $ingestor): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! config('wacli.lead_whatsapp_ingestion_enabled')) {
            $this->warn('Lead WhatsApp ingestion disabled (LEAD_WHATSAPP_INGESTION_ENABLED=false). Exiting.');

            return self::SUCCESS;
        }

        try {
            $messages = $client->fetchMessages();
        } catch (Throwable $e) {
            Log::error('leads:fetch-wacli-messages remote read failed', ['error' => $e->getMessage()]);
            $this->error('Remote read failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($messages->isEmpty()) {
            $this->info('No messages returned from wacli.');

            return self::SUCCESS;
        }

        // Local windowing: drop anything older than the resume point. If no
        // prior rows exist, fall back to initial_lookback_hours.
        $lastSeen = LeadWhatsAppIngestion::where('provider', LeadWhatsAppIngestion::PROVIDER_WACLI)
            ->whereNotNull('remote_sent_at')
            ->orderByDesc('remote_sent_at')
            ->value('remote_sent_at');
        $floor = $lastSeen ?? now()->subHours((int) config('wacli.initial_lookback_hours', 12));

        $messages = $messages
            ->filter(fn ($m) => $m->sentAt !== null && $m->sentAt->gt($floor))
            ->take((int) config('wacli.batch_size', 200))
            ->values();

        if ($messages->isEmpty()) {
            $this->info("No messages newer than {$floor->toIso8601String()}.");

            return self::SUCCESS;
        }

        $counts = [
            'fetched'                     => $messages->count(),
            'created_new_lead'            => 0,
            'appended_with_new_followup'  => 0,
            'appended_to_existing_open'   => 0,
            'skipped_duplicate'           => 0,
            'skipped_self'                => 0,
            'skipped_group'               => 0,
            'skipped_no_phone'            => 0,
            'skipped_blocklist'           => 0,
            'ambiguous'                   => 0,
            'failed'                      => 0,
        ];

        foreach ($messages as $msg) {
            try {
                $result = $ingestor->handle($msg, $dryRun);
                $counts[$result['decision']] = ($counts[$result['decision']] ?? 0) + 1;

                if ($dryRun) {
                    $this->line(sprintf(
                        '[dry-run] %-28s chat=%-24s phone=%-15s %s',
                        $result['decision'],
                        mb_strimwidth((string) ($msg->chatName ?? $msg->chatJid), 0, 24, '…'),
                        (string) ($msg->extractPhone() ?? '-'),
                        mb_strimwidth($msg->body, 0, 50, '…'),
                    ));
                }
            } catch (Throwable $e) {
                $counts['failed']++;
                Log::error('leads:fetch-wacli-messages failed on message', [
                    'message_id' => $msg->remoteMessageId,
                    'chat_jid'   => $msg->chatJid,
                    'error'      => $e->getMessage(),
                ]);
                $this->error("Failed {$msg->remoteMessageId}: {$e->getMessage()}");
            }
        }

        $this->info('leads:fetch-wacli-messages'.($dryRun ? ' [dry-run]' : '').' — '.json_encode($counts));
        Log::info('leads:fetch-wacli-messages completed', $counts + ['dry_run' => $dryRun]);

        return self::SUCCESS;
    }
}
