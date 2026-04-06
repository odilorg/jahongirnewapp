<?php

namespace App\Console\Commands;

use App\Enums\Beds24SyncStatus;
use App\Enums\CashTransactionSource;
use App\Models\Beds24PaymentSync;
use App\Models\CashTransaction;
use App\Services\OwnerAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily FX exception report sent at 08:30 Tashkent time.
 *
 * Reports three categories:
 *   1. beds24_external cash rows in the last 24h — likely policy violations
 *   2. Pushed syncs with no webhook confirmation after 2h grace period
 *   3. Permanently failed sync rows (exhausted retries)
 *
 * Exits silently (SUCCESS) when nothing to report.
 */
class FxNightlyExceptionReport extends Command
{
    protected $signature   = 'fx:nightly-report {--dry-run : Print report to console without sending Telegram}';
    protected $description = 'Daily FX exception report: policy violations, unconfirmed syncs, failed syncs';

    public function __construct(
        private readonly OwnerAlertService $alertService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $since = now()->subHours(24);
        $tz    = 'Asia/Tashkent';

        // ── 1. Policy violations: external cash rows ──────────────────────────
        $violations = CashTransaction::where('source_trigger', CashTransactionSource::Beds24External->value)
            ->whereIn('payment_method', ['naqd', 'cash', 'наличные'])
            ->where('occurred_at', '>=', $since)
            ->orderBy('occurred_at')
            ->get(['beds24_booking_id', 'amount', 'payment_method', 'guest_name', 'occurred_at']);

        // ── 2. Pushed but unconfirmed (webhook never came back) ───────────────
        // Grace period: 2 hours after push
        $unconfirmed = Beds24PaymentSync::where('status', Beds24SyncStatus::Pushed->value)
            ->where('last_push_at', '<=', now()->subHours(2))
            ->orderBy('last_push_at')
            ->get(['id', 'beds24_booking_id', 'amount_usd', 'last_push_at']);

        // ── 3. Permanently failed syncs ───────────────────────────────────────
        $failed = Beds24PaymentSync::where('status', Beds24SyncStatus::Failed->value)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->get(['id', 'beds24_booking_id', 'amount_usd', 'push_attempts', 'last_error', 'created_at']);

        // ── 4. Stuck pending/pushing (repair job should have caught these) ────
        // Rows older than 1 h that are still pending/pushing indicate the
        // repair job itself is failing or not running. Flag as escalation.
        $stuckPending = Beds24PaymentSync::whereIn('status', [
                Beds24SyncStatus::Pending->value,
                Beds24SyncStatus::Pushing->value,
            ])
            ->where('created_at', '<=', now()->subHour())
            ->orderBy('created_at')
            ->get(['id', 'beds24_booking_id', 'amount_usd', 'status', 'created_at']);

        // Nothing to report
        if ($violations->isEmpty() && $unconfirmed->isEmpty() && $failed->isEmpty() && $stuckPending->isEmpty()) {
            $this->info('fx:nightly-report: nothing to report.');
            return self::SUCCESS;
        }

        $date    = now()->timezone($tz)->format('d.m.Y');
        $lines   = ["📊 <b>FX Daily Report — {$date}</b>"];

        // ── Violations ────────────────────────────────────────────────────────
        if ($violations->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "⚠️ <b>Нарушения ({$violations->count()})</b> — наличные записаны в Beds24 напрямую:";
            foreach ($violations as $v) {
                $time = $v->occurred_at->timezone($tz)->format('H:i');
                $lines[] = "  • {$time} | {$v->beds24_booking_id} | \${$v->amount} | {$v->payment_method} | " . ($v->guest_name ?: '—');
            }
        }

        // ── Unconfirmed syncs ─────────────────────────────────────────────────
        if ($unconfirmed->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "🔄 <b>Не подтверждены из Beds24 ({$unconfirmed->count()})</b> — вебхук не вернулся:";
            foreach ($unconfirmed as $u) {
                $pushed = $u->last_push_at?->timezone($tz)->format('H:i') ?? '?';
                $lines[] = "  • ID#{$u->id} | {$u->beds24_booking_id} | \${$u->amount_usd} | отправлено {$pushed}";
            }
        }

        // ── Failed syncs ──────────────────────────────────────────────────────
        if ($failed->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "❌ <b>Ошибки синхронизации ({$failed->count()})</b> — требуют внимания:";
            foreach ($failed as $f) {
                $err = mb_substr($f->last_error ?? 'unknown', 0, 80);
                $lines[] = "  • ID#{$f->id} | {$f->beds24_booking_id} | \${$f->amount_usd} | попыток: {$f->push_attempts} | {$err}";
            }
        }

        // ── Stuck pending/pushing ─────────────────────────────────────────────
        if ($stuckPending->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "⏳ <b>Зависшие синхронизации ({$stuckPending->count()})</b> — очередь не обработала за >1ч:";
            foreach ($stuckPending as $s) {
                $age  = $s->created_at->diffForHumans(now(), true);
                $lines[] = "  • ID#{$s->id} | {$s->beds24_booking_id} | \${$s->amount_usd} | {$s->status->value} | {$age}";
            }
            $lines[] = "  ℹ️ Запустите: <code>php artisan fx:repair-stuck-syncs</code>";
        }

        $message = implode("\n", $lines);

        if ($this->option('dry-run')) {
            $this->line($message);
            return self::SUCCESS;
        }

        $this->alertService->sendOpsAlert($message);

        Log::info('fx:nightly-report sent', [
            'violations'   => $violations->count(),
            'unconfirmed'  => $unconfirmed->count(),
            'failed'       => $failed->count(),
            'stuck_pending' => $stuckPending->count(),
        ]);

        return $violations->isNotEmpty() || $failed->isNotEmpty() ? self::FAILURE : self::SUCCESS;
    }
}
