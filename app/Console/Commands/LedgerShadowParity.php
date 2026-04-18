<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ledger\ShadowParityChecker;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * L-006.5 — human + cron runnable parity report.
 *
 *   php artisan ledger:shadow-parity
 *     default: last 7 days, beds24, summary output
 *
 *   php artisan ledger:shadow-parity --daily
 *     yesterday's 24-hour window, exits 1 on drift, compact output
 *     for nightly cron scheduling
 *
 *   php artisan ledger:shadow-parity --from=2026-04-11 --to=2026-04-18
 *     explicit window
 *
 *   php artisan ledger:shadow-parity --detailed
 *     print every drift row's legacy + ledger snapshot
 *
 *   php artisan ledger:shadow-parity --source=beds24
 *     default; octo / cashier will be added when L-008 / L-009 ship
 *
 * Exit codes:
 *   0  parity ok (zero drift) — or --no-exit-on-drift set
 *   1  drift detected
 *   2  invalid argument
 */
class LedgerShadowParity extends Command
{
    protected $signature = 'ledger:shadow-parity
                            {--from= : Start of window (inclusive), ISO date}
                            {--to= : End of window (inclusive), ISO date}
                            {--source=beds24 : Source to check}
                            {--daily : Shortcut for yesterday 00:00 → today 00:00}
                            {--detailed : Print each drift row}
                            {--no-exit-on-drift : Report drift but always exit 0}';

    protected $description = 'Compare legacy cash_transactions vs ledger_entries within a time window (L-006.5 shadow parity)';

    public function handle(ShadowParityChecker $checker): int
    {
        [$from, $to] = $this->resolveWindow();
        $source      = (string) $this->option('source');

        try {
            $report = $checker->check($from, $to, $source);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return 2;
        }

        $this->printSummary($report);

        if ($this->option('detailed') || $report->hasDrift()) {
            $this->printDetails($report);
        }

        // Always leave a structured log trail, so log aggregators can
        // track the parity outcome over time without parsing text.
        Log::info('ledger.shadow.parity.report', $report->toArray());

        if ($report->hasDrift() && ! $this->option('no-exit-on-drift')) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveWindow(): array
    {
        if ($this->option('daily')) {
            // Rolling 24 hours from now — operators scheduling this at any
            // hour of the night get a consistent window that covers all
            // writes since the previous run. For a calendar day use
            // --from / --to explicitly.
            $to   = Carbon::now();
            $from = $to->copy()->subDay();
            return [$from, $to];
        }

        $fromOpt = $this->option('from');
        $toOpt   = $this->option('to');

        $from = $fromOpt ? Carbon::parse((string) $fromOpt)->startOfDay() : Carbon::now()->subDays(7);
        $to   = $toOpt   ? Carbon::parse((string) $toOpt)->endOfDay()     : Carbon::now();

        return [$from, $to];
    }

    private function printSummary(\App\DTOs\Ledger\ShadowParityReport $report): void
    {
        $lines = [
            '=== Ledger shadow parity report ===',
            sprintf('Window:   %s → %s', $report->from->toDateTimeString(), $report->to->toDateTimeString()),
            sprintf('Source:   %s', $report->source),
            '',
            sprintf('Legacy rows (cash_transactions): %d', $report->legacyCount),
            sprintf('Ledger rows (ledger_entries):    %d', $report->ledgerCount),
            sprintf('Matched:                         %d   (%.2f%%)', $report->matchedCount(), $report->matchRate() * 100),
            '',
            sprintf('Missing from ledger:             %d', count($report->missingLedger)),
            sprintf('Extra in ledger:                 %d', count($report->extraLedger)),
            sprintf('Amount mismatches:               %d', count($report->amountMismatches)),
            sprintf('Method mismatches:               %d', count($report->methodMismatches)),
            sprintf('Currency mismatches:             %d', count($report->currencyMismatches)),
            sprintf('Unmatchable (no item_ref):       %d', count($report->unmatchableRows)),
            '',
            sprintf('Total drift:                     %d', $report->driftCount()),
        ];
        foreach ($lines as $line) {
            $this->line($line);
        }
    }

    private function printDetails(\App\DTOs\Ledger\ShadowParityReport $report): void
    {
        $this->line('');
        $this->line('--- DETAILS ---');

        if (! empty($report->missingLedger)) {
            $this->warn('Missing from ledger (legacy row has no ledger counterpart):');
            foreach ($report->missingLedger as $row) {
                $this->line("  [MISSING_LEDGER] " . $this->compactRow($row));
            }
        }

        if (! empty($report->extraLedger)) {
            $this->warn('Extra in ledger (ledger row has no legacy counterpart):');
            foreach ($report->extraLedger as $row) {
                $this->line("  [EXTRA_LEDGER] " . $this->compactRow($row));
            }
        }

        if (! empty($report->amountMismatches)) {
            $this->warn('Amount mismatches:');
            foreach ($report->amountMismatches as $row) {
                $this->line(sprintf(
                    '  [AMOUNT] booking=%s item=%s legacy=%s ledger=%s',
                    $row['booking_id'],
                    $row['item_ref'],
                    $row['legacy']['amount'] ?? '-',
                    $row['ledger']['amount'] ?? '-',
                ));
            }
        }

        if (! empty($report->methodMismatches)) {
            $this->warn('Method mismatches (after normalisation):');
            foreach ($report->methodMismatches as $row) {
                $this->line(sprintf(
                    '  [METHOD] booking=%s item=%s legacy_raw=%s normalised=%s ledger=%s',
                    $row['booking_id'],
                    $row['item_ref'],
                    $row['legacy']['raw'] ?? '-',
                    $row['legacy']['normalised'] ?? '-',
                    $row['ledger']['payment_method'] ?? '-',
                ));
            }
        }

        if (! empty($report->currencyMismatches)) {
            $this->warn('Currency mismatches:');
            foreach ($report->currencyMismatches as $row) {
                $this->line(sprintf(
                    '  [CURRENCY] booking=%s item=%s legacy=%s ledger=%s',
                    $row['booking_id'],
                    $row['item_ref'],
                    $row['legacy']['currency'] ?? '-',
                    $row['ledger']['currency'] ?? '-',
                ));
            }
        }

        if (! empty($report->unmatchableRows)) {
            $this->warn('Unmatchable rows (no stable item_ref on either side; manual review required):');
            foreach ($report->unmatchableRows as $row) {
                $this->line("  [{$row['marker']}] " . $this->compactRow($row));
            }
        }
    }

    private function compactRow(array $row): string
    {
        return sprintf(
            'booking=%s item=%s amount=%s currency=%s method=%s at=%s',
            $row['booking_id'] ?? '-',
            $row['item_ref'] ?? '-',
            $row['amount'] ?? '-',
            $row['currency'] ?? '-',
            $row['method'] ?? '-',
            $row['occurred_at'] ?? '-',
        );
    }
}
