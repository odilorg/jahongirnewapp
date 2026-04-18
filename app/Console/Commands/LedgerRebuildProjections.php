<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Projections\CashDrawerBalance;
use App\Models\Projections\ShiftBalance;
use App\Services\Ledger\BalanceProjectionUpdater;
use Illuminate\Console\Command;

/**
 * L-005 — recompute ledger balance projections from scratch.
 *
 * Usage:
 *   php artisan ledger:rebuild-projections           # truncate + re-apply all
 *   php artisan ledger:rebuild-projections --verify  # rebuild + diff against live
 *
 * The rebuild invariant is one of the cutover gates (REFACTOR_PLAN §5
 * gate #3): `ledger:rebuild-projections --verify` must report zero
 * drift for 7 consecutive days before legacy tables are frozen.
 */
class LedgerRebuildProjections extends Command
{
    protected $signature = 'ledger:rebuild-projections
                            {--verify : Compare rebuilt projections with the live state; non-zero exit on drift}';

    protected $description = 'Rebuild ledger_entries-derived balance projections from scratch';

    public function handle(BalanceProjectionUpdater $updater): int
    {
        if ($this->option('verify')) {
            return $this->runVerify($updater);
        }

        return $this->runRebuild($updater);
    }

    private function runRebuild(BalanceProjectionUpdater $updater): int
    {
        $this->info('Rebuilding ledger balance projections...');
        $started = microtime(true);

        $processed = $updater->rebuildAll();
        $elapsed   = round(microtime(true) - $started, 2);

        $this->info("Processed {$processed} ledger entries in {$elapsed}s.");
        $this->info(sprintf('cash_drawer_balances: %d rows', CashDrawerBalance::count()));
        $this->info(sprintf('shift_balances:       %d rows', ShiftBalance::count()));

        return self::SUCCESS;
    }

    private function runVerify(BalanceProjectionUpdater $updater): int
    {
        $this->info('Snapshotting live projections...');
        $liveDrawer = CashDrawerBalance::all()
            ->keyBy(fn ($row) => $row->cash_drawer_id . '|' . $row->currency)
            ->map(fn ($row) => [
                'balance'   => (string) $row->balance,
                'total_in'  => (string) $row->total_in,
                'total_out' => (string) $row->total_out,
            ])
            ->toArray();
        $liveShift = ShiftBalance::all()
            ->keyBy(fn ($row) => $row->cashier_shift_id . '|' . $row->currency)
            ->map(fn ($row) => [
                'balance'   => (string) $row->balance,
                'total_in'  => (string) $row->total_in,
                'total_out' => (string) $row->total_out,
            ])
            ->toArray();

        $this->info('Rebuilding into projection tables...');
        $updater->rebuildAll();

        $drift = 0;
        $drift += $this->diffProjection(
            'cash_drawer_balances',
            $liveDrawer,
            CashDrawerBalance::all()
                ->keyBy(fn ($row) => $row->cash_drawer_id . '|' . $row->currency)
                ->map(fn ($row) => [
                    'balance'   => (string) $row->balance,
                    'total_in'  => (string) $row->total_in,
                    'total_out' => (string) $row->total_out,
                ])
                ->toArray(),
        );
        $drift += $this->diffProjection(
            'shift_balances',
            $liveShift,
            ShiftBalance::all()
                ->keyBy(fn ($row) => $row->cashier_shift_id . '|' . $row->currency)
                ->map(fn ($row) => [
                    'balance'   => (string) $row->balance,
                    'total_in'  => (string) $row->total_in,
                    'total_out' => (string) $row->total_out,
                ])
                ->toArray(),
        );

        if ($drift === 0) {
            $this->info('Verify: zero drift.');
            return self::SUCCESS;
        }

        $this->error("Verify: {$drift} drift row(s) detected — see output above.");
        return self::FAILURE;
    }

    private function diffProjection(string $label, array $live, array $rebuilt): int
    {
        $drift = 0;
        $keys = array_unique(array_merge(array_keys($live), array_keys($rebuilt)));
        foreach ($keys as $key) {
            if (($live[$key] ?? null) !== ($rebuilt[$key] ?? null)) {
                $drift++;
                $this->warn("drift [{$label}] {$key}: live=" . json_encode($live[$key] ?? null)
                    . ' rebuilt=' . json_encode($rebuilt[$key] ?? null));
            }
        }
        return $drift;
    }
}
