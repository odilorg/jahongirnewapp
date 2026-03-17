<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBeds24WebhookJob;
use App\Models\Beds24WebhookEvent;
use App\Models\IncomingWebhook;
use Illuminate\Console\Command;

class WebhookReplay extends Command
{
    protected $signature = 'webhooks:replay
                            {--source= : Filter by source (e.g. beds24, gyg, telegram)}
                            {--status=failed : Filter by status (pending|processing|failed)}
                            {--limit=100 : Maximum number of webhooks to re-queue}
                            {--dry-run : Show what would be re-queued without actually doing it}';

    protected $description = 'Re-queue failed or pending incoming webhooks for reprocessing';

    public function handle(): int
    {
        $source  = $this->option('source');
        $status  = $this->option('status');
        $limit   = (int) $this->option('limit');
        $dryRun  = (bool) $this->option('dry-run');

        $query = IncomingWebhook::query()->withStatus($status);

        if ($source) {
            $query->forSource($source);
        }

        $webhooks = $query->orderBy('received_at')->limit($limit)->get();

        if ($webhooks->isEmpty()) {
            $this->info('No matching webhooks found.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Found %d webhook(s) with status="%s"%s',
            $webhooks->count(),
            $status,
            $source ? " source=\"{$source}\"" : ''
        ));

        if ($dryRun) {
            $this->table(
                ['ID', 'Source', 'Status', 'Attempts', 'Received At', 'Error'],
                $webhooks->map(fn ($w) => [
                    $w->id,
                    $w->source,
                    $w->status,
                    $w->attempts,
                    $w->received_at?->toDateTimeString(),
                    mb_substr($w->error ?? '', 0, 60),
                ])->toArray()
            );
            $this->line('Dry run — nothing was re-queued.');
            return self::SUCCESS;
        }

        $queued = 0;
        $skipped = 0;

        foreach ($webhooks as $webhook) {
            $job = $this->resolveJob($webhook);

            if ($job === null) {
                $this->warn("  Skipping #{$webhook->id}: no job handler for source \"{$webhook->source}\"");
                $skipped++;
                continue;
            }

            // Reset to pending so the job handler picks it up cleanly
            $webhook->update([
                'status' => IncomingWebhook::STATUS_PENDING,
                'error'  => null,
            ]);

            dispatch($job);
            $this->line("  Re-queued #{$webhook->id} ({$webhook->source})");
            $queued++;
        }

        $this->info("Done. Re-queued: {$queued}, skipped: {$skipped}.");
        return self::SUCCESS;
    }

    /**
     * Resolve the appropriate job class for the given webhook source.
     *
     * For beds24, we find (or re-create) the Beds24WebhookEvent so that
     * ProcessBeds24WebhookJob can load it by its own table ID, while still
     * tracking status in the durable incoming_webhooks inbox.
     *
     * Add new sources here as integrations are added.
     */
    private function resolveJob(IncomingWebhook $webhook): ?object
    {
        if ($webhook->source === 'beds24') {
            // Find the corresponding Beds24WebhookEvent by re-hashing the stored payload
            $eventHash = hash('sha256', json_encode($webhook->payload));
            $event = Beds24WebhookEvent::where('event_hash', $eventHash)->first();

            if (!$event) {
                // Re-create the event record so the job can process it
                $bookingId = (string) ($webhook->payload['bookId']
                    ?? $webhook->payload['bookid']
                    ?? $webhook->payload['id']
                    ?? '');
                $event = Beds24WebhookEvent::create([
                    'event_hash' => $eventHash,
                    'booking_id' => $bookingId ?: null,
                    'payload'    => $webhook->payload,
                    'status'     => 'pending',
                ]);
            } else {
                // Reset so the job will re-process it
                $event->update(['status' => 'pending', 'error' => null]);
            }

            return new ProcessBeds24WebhookJob($event->id, $webhook->id);
        }

        return null;
    }
}
