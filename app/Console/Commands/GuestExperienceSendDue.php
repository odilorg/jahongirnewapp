<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\GuestExperienceMessage;
use App\Services\GuestExperience\GuestExperienceDispatcher;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Sends due guest experience messages (Phase 29).
 *
 * Scheduled every 5 minutes. Thin orchestration only — all guard logic
 * lives in GuestExperienceDispatcher. Safe to re-run: the dispatcher's
 * compare-and-swap guarantees each message sends at most once.
 */
class GuestExperienceSendDue extends Command
{
    protected $signature = 'guest-experience:send-due
        {--dry-run : Print intended sends without sending or mutating state}
        {--limit=200 : Max messages to process this run}';

    protected $description = 'Send due guest experience touchpoints (welcome / sunset tip / feedback)';

    public function handle(GuestExperienceDispatcher $dispatcher): int
    {
        if (! config('guest_experience.enabled')) {
            $this->info('guest_experience.enabled = false — nothing to do.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $now = now();

        // 1. Sweep stale 'sending' rows (crashed prior dispatch) → unknown.
        $staleCutoff = Carbon::now()->subMinutes((int) config('guest_experience.sending_stale_minutes'));
        if (! $dryRun) {
            GuestExperienceMessage::query()
                ->where('status', GuestExperienceMessage::STATUS_SENDING)
                ->where('last_attempted_at', '<', $staleCutoff)
                ->get()
                ->each(fn (GuestExperienceMessage $m) => $dispatcher->sweepStale($m));
        }

        // 2. Send due pending rows.
        $due = GuestExperienceMessage::query()
            ->with('bookingInquiry')
            ->where('status', GuestExperienceMessage::STATUS_PENDING)
            ->where('due_at', '<=', $now)
            ->orderBy('due_at')
            ->limit($limit)
            ->get();

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($due as $message) {
            $result = $dispatcher->send($message, dryRun: $dryRun);

            if ($result['ok'] ?? false) {
                $sent++;
                $this->line("  ✅ {$message->bookingInquiry?->reference} · {$message->message_type}".($dryRun ? ' [DRY]' : ''));
            } elseif (($result['status'] ?? null) === GuestExperienceMessage::STATUS_SKIPPED) {
                $skipped++;
                $this->line("  ⏭ {$message->bookingInquiry?->reference} · {$message->message_type} · {$result['reason']}");
            } else {
                $failed++;
                $this->line("  ⚠ {$message->bookingInquiry?->reference} · {$message->message_type} · {$result['reason']}");
            }
        }

        $this->info("done — candidates={$due->count()} sent={$sent} skipped={$skipped} failed={$failed}".($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
