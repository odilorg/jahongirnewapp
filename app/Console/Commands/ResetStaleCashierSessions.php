<?php

namespace App\Console\Commands;

use App\Models\TelegramPosSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Reset cashier-bot sessions that have been stuck in a mid-flow state for too long.
 *
 * Targets only states owned by the cashier bot (payment_*, expense_*, exchange_*,
 * cash_in_*, shift_*). The housekeeping bot (`hk_*`) and kitchen bot (`kitchen_*`)
 * use the same `telegram_pos_sessions` table and MUST NOT be touched here.
 *
 * Idempotent. Supports --dry-run.
 *
 * Run on demand; not scheduled. The runtime safety rail in
 * CashierBotController::resetSessionToMainMenu() also catches orphans on the
 * next user message, so this command is for proactive cleanup only.
 */
class ResetStaleCashierSessions extends Command
{
    protected $signature = 'cashier:reset-stale-sessions
                            {--days=7 : Only reset sessions idle longer than N days}
                            {--dry-run : Report what would be reset without changing anything}';

    protected $description = 'Reset cashier-bot sessions stuck in mid-flow states past the idle threshold.';

    /**
     * State prefixes owned by the cashier bot. Anything else is left alone.
     */
    private const CASHIER_STATE_PREFIXES = [
        'payment_',
        'expense_',
        'exchange_',
        'cash_in_',
        'shift_',
    ];

    public function handle(): int
    {
        $days   = max(1, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $query = TelegramPosSession::query()
            ->where('updated_at', '<', $cutoff)
            ->where(function ($q) {
                foreach (self::CASHIER_STATE_PREFIXES as $prefix) {
                    $q->orWhere('state', 'like', $prefix . '%');
                }
            })
            ->whereNotIn('state', ['main_menu', 'idle']);

        $rows = $query->get(['id', 'chat_id', 'user_id', 'state', 'updated_at']);

        if ($rows->isEmpty()) {
            $this->info("No stale cashier sessions found older than {$days}d.");
            return self::SUCCESS;
        }

        $this->info("Found {$rows->count()} stale cashier session(s) (idle > {$days}d):");
        foreach ($rows as $r) {
            $this->line(sprintf(
                '  #%d  chat=%d  user=%s  state=%s  last=%s',
                $r->id,
                $r->chat_id,
                $r->user_id ?? 'null',
                $r->state,
                $r->updated_at?->toDateTimeString() ?? 'null'
            ));
        }

        if ($dryRun) {
            $this->warn('Dry run — no changes applied.');
            return self::SUCCESS;
        }

        $resetCount = 0;
        foreach ($rows as $r) {
            $session = TelegramPosSession::find($r->id);
            if (! $session) {
                continue;
            }

            Log::info('CashierBot: stale session reset by command', [
                'session_id' => $session->id,
                'chat_id'    => $session->chat_id,
                'user_id'    => $session->user_id,
                'old_state'  => $session->state,
                'idle_days'  => $days,
            ]);

            $session->update(['state' => 'main_menu', 'data' => null]);
            $resetCount++;
        }

        $this->info("Reset {$resetCount} session(s) to main_menu.");
        return self::SUCCESS;
    }
}
