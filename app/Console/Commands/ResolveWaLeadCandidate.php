<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\WhatsApp\RecordWaClassification;
use App\Models\WaLeadCandidate;
use Illuminate\Console\Command;

/**
 * Records a classifier verdict (passed as --result JSON by the runner-side
 * classifier) onto a candidate and routes it per WaLeadDecision. Terminal
 * actions stay gated in RecordWaClassification; with gates off this only
 * records + sets status=review. Never creates a booking_inquiry, never sends.
 */
class ResolveWaLeadCandidate extends Command
{
    protected $signature = 'wa-leads:resolve {candidate : wa_lead_candidate id} {--result= : classifier JSON}';

    protected $description = 'Record a classifier verdict on a WhatsApp candidate and route it (gated; no auto-create/dismiss by default)';

    public function handle(RecordWaClassification $recorder): int
    {
        $candidate = WaLeadCandidate::find((int) $this->argument('candidate'));
        if (! $candidate) {
            $this->error('candidate not found');

            return self::FAILURE;
        }

        $result = json_decode((string) $this->option('result'), true);
        if (! is_array($result)) {
            $this->error('--result must be valid JSON');

            return self::FAILURE;
        }

        $out = $recorder->record($candidate, $result);
        $this->info("candidate #{$candidate->id}: decision={$out['decision']} status={$out['status']}");

        return self::SUCCESS;
    }
}
