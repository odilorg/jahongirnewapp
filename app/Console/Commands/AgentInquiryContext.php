<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Services\Agent\InquiryContextBuilder;
use Illuminate\Console\Command;

/**
 * Tour-agent EYES (Phase 0, draft-only): emit a read-only JSON snapshot of a
 * single booking inquiry — guest, request, linked tour, computed quote, status,
 * money and timeline — for the headless agent to read before drafting.
 *
 * Strictly read-only. No writes, no sends. Orchestration only (Principle 9):
 * the real assembly lives in InquiryContextBuilder.
 *
 *   php artisan agent:inquiry-context 176
 *   php artisan agent:inquiry-context INQ-2026-000168 --compact
 */
class AgentInquiryContext extends Command
{
    protected $signature = 'agent:inquiry-context
                            {inquiry : Inquiry id or reference (INQ-YYYY-NNNNNN)}
                            {--compact : Emit single-line JSON instead of pretty-printed}';

    protected $description = 'Read-only JSON context for one booking inquiry (tour-agent input).';

    public function handle(InquiryContextBuilder $builder): int
    {
        $key = (string) $this->argument('inquiry');

        $inquiry = BookingInquiry::with(InquiryContextBuilder::EAGER_LOAD)
            ->when(
                ctype_digit($key),
                fn ($q) => $q->whereKey((int) $key),
                fn ($q) => $q->where('reference', $key),
            )
            ->first();

        if ($inquiry === null) {
            $this->error("No booking inquiry found for: {$key}");

            return self::FAILURE;
        }

        $flags = $this->option('compact') ? 0 : JSON_PRETTY_PRINT;
        $this->line((string) json_encode(
            $builder->build($inquiry),
            $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return self::SUCCESS;
    }
}
