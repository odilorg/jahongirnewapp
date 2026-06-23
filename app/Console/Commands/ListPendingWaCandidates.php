<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\WaLeadCandidate;
use Illuminate\Console\Command;

/**
 * Read-only lister: emit pending WhatsApp candidates as JSON for the runner-side
 * classifier (tour-agent `wa-classify`). Pure SELECT — never writes, never
 * creates booking_inquiries, never sends. The runner needs id + phone to fetch
 * each thread via the read-only bridge; first_inbound is a fallback snippet when
 * the live bridge is unavailable. Not a gate — listing is always safe.
 */
class ListPendingWaCandidates extends Command
{
    protected $signature = 'wa-leads:pending
        {--limit=50 : Max candidates to emit}
        {--status=pending : Candidate status to list}';

    protected $description = 'Emit pending WhatsApp candidates as JSON (read-only) for the runner-side classifier';

    public function handle(): int
    {
        $rows = WaLeadCandidate::query()
            ->where('status', (string) $this->option('status'))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'phone', 'first_inbound', 'last_inbound_at', 'inbound_count'])
            ->map(fn (WaLeadCandidate $c): array => [
                'id'              => $c->id,
                'phone'           => $c->phone,
                'first_inbound'   => $c->first_inbound,
                'last_inbound_at' => optional($c->last_inbound_at)->toIso8601String(),
                'inbound_count'   => $c->inbound_count,
            ])
            ->all();

        $this->line(json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
