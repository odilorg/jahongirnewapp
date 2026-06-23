<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\WhatsApp\IngestWaCandidates;
use App\Services\WhatsApp\WaCandidateScanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1: scan inbound WhatsApp DMs (via the read-only command-locked scanner
 * on vps-main) and queue NEW prospects into wa_lead_candidates for operator
 * review. NEVER creates booking_inquiries, never sends/mutates WhatsApp.
 *
 *   --dry-run : detect + report, write nothing (read-only end to end).
 *   live      : gated by config('wa_leads.ingestion_enabled') (default OFF);
 *               upserts pending candidates only.
 */
class ScanWaLeadCandidates extends Command
{
    protected $signature = 'wa-leads:scan
        {--dry-run : Detect + report candidate decisions, write nothing}
        {--days= : Lookback window in days (default config)}';

    protected $description = 'Detect inbound WhatsApp prospects and queue NEW ones for operator review (no auto-create)';

    public function handle(WaCandidateScanner $scanner, IngestWaCandidates $ingestor): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! (bool) config('wa_leads.ingestion_enabled')) {
            $this->warn('WhatsApp lead ingestion is disabled (WA_LEAD_INGESTION_ENABLED=false). Nothing done.');

            return self::SUCCESS;
        }

        $days = (int) ($this->option('days') ?: config('wa_leads.scan_days', 120));

        try {
            $scan = $scanner->scan($days, (int) config('wa_leads.scan_max', 500));
        } catch (\Throwable $e) {
            $this->error('Scan failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(($dryRun ? '[DRY-RUN] ' : '') . sprintf(
            'scanned %d inbound DM candidates (as_of %s, %dd window)',
            count($scan['candidates']),
            substr((string) $scan['as_of'], 0, 19),
            $days,
        ));

        $counts = $ingestor->ingest($scan['candidates'], $dryRun);
        $this->info('Decisions: ' . json_encode($counts));
        if ($dryRun) {
            $this->info('No writes. Detection is read-only; nothing queued.');
        } else {
            Log::info('wa-leads:scan queued candidates', ['counts' => $counts, 'as_of' => $scan['as_of']]);
        }

        return self::SUCCESS;
    }
}
