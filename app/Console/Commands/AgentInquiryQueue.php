<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BookingInquiry;
use App\Services\Agent\InquiryQueueReader;
use Illuminate\Console\Command;

/**
 * Tour-agent work queue (M3, read-only): list inquiries that need a draft,
 * oldest-first, as PII-free JSON. Consumed by `tour-agent poll`.
 *
 * Strictly read-only. Orchestration only (Principle 9) — the query lives in
 * InquiryQueueReader.
 *
 *   php artisan agent:inquiry-queue --statuses=new --exclude-ota --limit=20
 */
class AgentInquiryQueue extends Command
{
    protected $signature = 'agent:inquiry-queue
                            {--statuses=new : Comma-separated commercial statuses to include}
                            {--limit=20 : Max candidates to return}
                            {--exclude-ota : Exclude GetYourGuide / Viator sources}
                            {--compact : Single-line JSON}';

    protected $description = 'Read-only PII-free queue of inquiries needing a draft (for the tour-agent poller).';

    public function handle(InquiryQueueReader $reader): int
    {
        $statuses = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $this->option('statuses'))
        )));
        $statuses = $statuses ?: [BookingInquiry::STATUS_NEW];

        $invalid = array_diff($statuses, BookingInquiry::STATUSES);
        if ($invalid !== []) {
            $this->line((string) json_encode(
                ['ok' => false, 'error' => 'unknown status(es): '.implode(', ', $invalid)],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::FAILURE;
        }

        $limit = max(1, min(200, (int) $this->option('limit')));

        $result = $reader->candidates($statuses, $limit, (bool) $this->option('exclude-ota'));

        $flags = $this->option('compact') ? 0 : JSON_PRETTY_PRINT;
        $this->line((string) json_encode($result, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
