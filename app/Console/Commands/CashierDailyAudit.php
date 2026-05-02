<?php

namespace App\Console\Commands;

use App\Enums\CashTransactionSource;
use App\Models\CashTransaction;
use App\Models\CashierShift;
use App\Models\DailyExchangeRate;
use App\Services\OwnerAlertService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Daily cashier-domain anomaly audit.
 *
 * Runs after the daily cash report (which fires at 23:00) and audits
 * the same day's data for integrity regressions, silent failures, and
 * operational drift. Sends a single Telegram summary to the owner
 * channel via OwnerAlertService and writes a permanent log line.
 *
 * Replayable for any past date:
 *   php artisan cash:audit-daily --date=2026-05-02
 *
 * Default date = yesterday (Asia/Tashkent), so the 07:00 schedule
 * audits the day that just closed.
 *
 * Exit codes are meaningful for cron-driven escalation:
 *   0 — PASS  (no anomalies)
 *   1 — WARN  (data oddities, review)
 *   2 — ALERT (immediate action required)
 */
class CashierDailyAudit extends Command
{
    protected $signature   = 'cash:audit-daily {--date= : Date in Y-m-d (Asia/Tashkent), defaults to yesterday}';
    protected $description = 'Anomaly-aware daily cashier audit; sends summary to owner Telegram';

    private const TZ = 'Asia/Tashkent';
    private const FX_STALE_WARN_DAYS  = 7;
    private const FX_STALE_ALERT_DAYS = 14;

    public function __construct(private readonly OwnerAlertService $ownerAlert)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), self::TZ)
            : now(self::TZ)->subDay();

        $start = $date->copy()->startOfDay();
        $end   = $date->copy()->endOfDay();

        $this->info("Auditing {$date->format('Y-m-d')} ({$start} → {$end} {$start->timezone->getName()})");

        $findings = [];   // each: ['severity'=>'WARN'|'ALERT', 'msg'=>string]
        $sections = [];   // human-readable data blocks for the message

        // ── Section 1: drawer-truth integrity ──────────────────────────
        // After today's fix, card/transfer rows must NEVER appear in
        // drawerTruth. If they do, the fix regressed.
        $drawerTruthLeak = CashTransaction::query()
            ->whereBetween('occurred_at', [$start, $end])
            ->drawerTruth()
            ->whereIn('payment_method', ['card', 'transfer'])
            ->count();
        if ($drawerTruthLeak > 0) {
            $findings[] = ['severity' => 'ALERT',
                'msg' => "Drawer-truth leak: {$drawerTruthLeak} card/transfer rows counted as drawer cash. Drawer-truth scope regression."];
        }

        // ── Section 2: revenue split ────────────────────────────────────
        // Per category × currency × payment_method (drawerTruth only).
        $cats = DB::table('cash_transactions')
            ->whereBetween('occurred_at', [$start, $end])
            ->where('source_trigger', CashTransactionSource::CashierBot->value)
            ->whereNull('deleted_at')
            ->selectRaw('category, type, currency, payment_method, COUNT(*) as cnt, ROUND(SUM(amount),2) as total')
            ->groupBy('category', 'type', 'currency', 'payment_method')
            ->get();

        $sales    = $cats->where('category', 'sale');
        $expenses = $cats->where('category', 'expense');
        $exchange = $cats->where('category', 'exchange');
        $other    = $cats->whereNotIn('category', ['sale', 'expense', 'exchange']);

        $sectSales = $this->fmtRows('💰 Sales', $sales, 'in');
        $sectExp   = $this->fmtRows('🧾 Expenses', $expenses, 'out');
        $sectExch  = $this->fmtRows('🔄 Exchange (NOT income)', $exchange, null);
        $sections[] = $sectSales;
        $sections[] = $sectExp;
        if ($exchange->isNotEmpty()) {
            $sections[] = $sectExch;
            // Daily report currently mixes exchange into income — flag.
            $findings[] = ['severity' => 'WARN',
                'msg' => 'Daily report mixes exchange rows into "ПРИХОД". Exchange volume above will appear inflated as revenue.'];
        }
        if ($other->isNotEmpty()) {
            $sections[] = $this->fmtRows('❓ Other categories', $other, null);
            $findings[] = ['severity' => 'WARN',
                'msg' => 'Unexpected category found in cashier_bot rows. Review.'];
        }

        // ── Section 3: shifts ──────────────────────────────────────────
        $shifts = CashierShift::query()
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('opened_at', [$start, $end])
                  ->orWhere(function ($qq) use ($end) {
                      $qq->where('status', 'open')->where('opened_at', '<=', $end);
                  });
            })
            ->with(['user', 'cashDrawer'])
            ->get();
        $shiftLines = ['👥 Shifts:'];
        foreach ($shifts as $s) {
            $name   = optional($s->user)->name ?? 'unknown';
            $drawer = optional($s->cashDrawer)->name ?? '?';
            $status = is_object($s->status) ? $s->status->value : (string) $s->status;
            $openedFmt = optional($s->opened_at)->format('H:i') ?? '—';
            $shiftLines[] = "  • {$name} @ {$drawer} — {$status}, opened {$openedFmt}";
        }
        // status is an enum-cast, so filter via the model helper.
        $openAtEnd = $shifts->filter(fn ($s) => $s->isOpen());
        if ($openAtEnd->isNotEmpty() && $date->isYesterday()) {
            $stale = $openAtEnd->count();
            $findings[] = ['severity' => 'WARN',
                'msg' => "{$stale} shift(s) still OPEN at end of day — possible forgotten close (handover-close planned)."];
        }
        $sections[] = implode("\n", $shiftLines);

        // ── Section 4: FX staleness ─────────────────────────────────────
        // daily_exchange_rates is single-row-per-day with per-currency columns.
        // We check the most recent row and its rate_date age vs audit date.
        $fxLines = ['💱 FX rates:'];
        $latestFx = DailyExchangeRate::query()->orderByDesc('rate_date')->first();
        if (!$latestFx) {
            $fxLines[] = '  ❌ no DailyExchangeRate row ever recorded';
            $findings[] = ['severity' => 'ALERT', 'msg' => 'No DailyExchangeRate row ever — FX is unusable.'];
        } else {
            $rateDate = Carbon::parse($latestFx->rate_date);
            $ageDays  = abs((int) $rateDate->diffInDays($date->copy()->startOfDay(), false));
            $fxLines[] = "  Latest: {$rateDate->format('Y-m-d')} (age {$ageDays}d)";
            $fxLines[] = "  • USD/UZS: " . number_format((float) $latestFx->usd_uzs_rate, 2, '.', ' ');
            $fxLines[] = "  • EUR/UZS: " . number_format((float) ($latestFx->eur_effective_rate ?? $latestFx->eur_uzs_cbu_rate ?? 0), 2, '.', ' ');
            $fxLines[] = "  • RUB/UZS: " . number_format((float) ($latestFx->rub_effective_rate ?? $latestFx->rub_uzs_cbu_rate ?? 0), 2, '.', ' ');
            if ($ageDays >= self::FX_STALE_ALERT_DAYS) {
                $findings[] = ['severity' => 'ALERT', 'msg' => "FX rates are {$ageDays} days old. Manager-tier escalation will misfire on non-UZS closes."];
            } elseif ($ageDays >= self::FX_STALE_WARN_DAYS) {
                $findings[] = ['severity' => 'WARN', 'msg' => "FX rates are {$ageDays} days old (threshold " . self::FX_STALE_WARN_DAYS . 'd).'];
            }
        }
        $sections[] = implode("\n", $fxLines);

        // ── Section 5: Beds24 push status ──────────────────────────────
        $syncRows = DB::table('booking_fx_syncs')
            ->whereBetween('created_at', [$start, $end])
            ->select('push_status', DB::raw('COUNT(*) as cnt'))
            ->groupBy('push_status')->get();
        $pushLines = ['🔁 Beds24 push status:'];
        $pushFailed = 0;
        foreach ($syncRows as $r) {
            $status = $r->push_status ?: 'NULL';
            $pushLines[] = "  • {$status}: {$r->cnt}";
            if (in_array($status, ['failed', 'NULL', 'error', 'pending'], true) && $r->push_status !== 'pushed') {
                if ($status === 'failed' || $status === 'error') {
                    $pushFailed += (int) $r->cnt;
                }
            }
        }
        if ($pushFailed > 0) {
            $findings[] = ['severity' => 'ALERT', 'msg' => "{$pushFailed} Beds24 push failure(s) on this date — payments not synced upstream."];
        }
        $sections[] = implode("\n", $pushLines);

        // ── Section 6: silent-corruption damage scan ───────────────────
        // UZS expenses with foreign-currency keywords in description.
        $suspect = DB::table('cash_expenses')
            ->whereBetween('occurred_at', [$start, $end])
            ->where('currency', 'UZS')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                foreach (['%евро%', '%EUR%', '%€%', '%USD%', '%долл%', '%бакс%', '%RUB%', '%руб%'] as $pat) {
                    $q->orWhere('description', 'LIKE', $pat);
                }
            })->count();
        if ($suspect > 0) {
            $findings[] = ['severity' => 'WARN',
                'msg' => "{$suspect} UZS expense(s) with foreign-currency keyword in description — possible silent mis-record (parser hardened today)."];
        }

        // ── Determine overall severity ─────────────────────────────────
        $severity = 'PASS';
        $sevLabel = '✅ PASS';
        $exit     = self::SUCCESS;
        if (collect($findings)->contains(fn ($f) => $f['severity'] === 'ALERT')) {
            $severity = 'ALERT';
            $sevLabel = '🚨 ALERT';
            $exit     = 2;
        } elseif (collect($findings)->contains(fn ($f) => $f['severity'] === 'WARN')) {
            $severity = 'WARN';
            $sevLabel = '⚠️ WARN';
            $exit     = 1;
        }

        // ── Build message ──────────────────────────────────────────────
        $header = "📊 <b>Cashier Daily Audit — {$sevLabel}</b>\n"
                . "Date: {$date->format('Y-m-d')} (Asia/Tashkent)\n"
                . "—————————————————";
        $findingsBlock = '';
        if (!empty($findings)) {
            $findingsBlock = "\n🔎 <b>Findings:</b>";
            foreach ($findings as $f) {
                $icon = $f['severity'] === 'ALERT' ? '🚨' : '⚠️';
                $findingsBlock .= "\n  {$icon} {$f['msg']}";
            }
        }

        $body = $header
              . $findingsBlock
              . "\n\n" . implode("\n\n", $sections)
              . "\n\n⏰ Generated: " . now(self::TZ)->format('d.m.Y H:i');

        // Telegram cap at 4000; trim conservatively.
        if (mb_strlen($body) > 3900) {
            $body = mb_substr($body, 0, 3850) . "\n\n…(truncated)";
        }

        // ── Dispatch + log ─────────────────────────────────────────────
        try {
            $this->ownerAlert->sendOpsAlert($body);
            $this->info('Audit summary dispatched.');
        } catch (\Throwable $e) {
            Log::warning('CashierDailyAudit: dispatch failed', ['e' => $e->getMessage()]);
            $this->error("Dispatch failed: {$e->getMessage()}");
        }

        Log::channel('daily')->info('cashier-daily-audit', [
            'date'      => $date->format('Y-m-d'),
            'severity'  => $severity,
            'finding_count' => count($findings),
            'findings'  => array_map(fn ($f) => $f['severity'] . ': ' . $f['msg'], $findings),
        ]);

        $this->info("Severity: {$severity} (exit {$exit})");
        return $exit;
    }

    /**
     * Format a category block for inclusion in the Telegram message.
     * $rows is a Collection of {category,type,currency,payment_method,cnt,total}.
     */
    private function fmtRows(string $title, $rows, ?string $expectedType): string
    {
        if ($rows->isEmpty()) {
            return $title . "\n  — нет";
        }

        $lines = [$title . ':'];
        $byCur = $rows->groupBy('currency');
        foreach ($byCur as $cur => $group) {
            $sum = $group->sum('total');
            $lines[] = "  <b>{$cur}:</b> " . number_format($sum, 0, '.', ' ');
            foreach ($group as $r) {
                $method = $r->payment_method ?: '—';
                $tlabel = $expectedType === null ? "{$r->type}/" : '';
                $lines[] = "    • {$tlabel}{$method}: " . number_format($r->total, 0, '.', ' ') . " (×{$r->cnt})";
            }
        }
        return implode("\n", $lines);
    }
}
