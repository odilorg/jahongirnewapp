<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Enums\LedgerEntryDirection;
use App\Models\LedgerEntry;
use App\Models\Projections\CashDrawerBalance;
use App\Models\Projections\ShiftBalance;
use Illuminate\Support\Facades\DB;

/**
 * L-005 — single update path shared by:
 *
 *   - the synchronous event listener (UpdateBalanceProjections), run
 *     inside the ledger write transaction, so balances are in sync
 *     immediately after a ledger insert
 *
 *   - the rebuild command (`ledger:rebuild-projections`), which
 *     truncates projection tables and re-runs this logic over every
 *     historical entry to regenerate balances from scratch
 *
 * Keeping both paths in one class guarantees "live" and "rebuilt"
 * balances converge — the invariant the L-015 backfill + cutover gate
 * #3 depend on.
 */
final class BalanceProjectionUpdater
{
    /**
     * Apply a single ledger entry to all affected balance projections.
     *
     * Assumes the caller (listener or rebuild loop) already holds a
     * transaction context; MySQL row locks serialize concurrent updaters
     * for the same (drawer, currency) or (shift, currency) row.
     */
    public function apply(LedgerEntry $entry): void
    {
        if ($entry->cash_drawer_id !== null) {
            $this->applyToCashDrawer($entry);
        }

        if ($entry->cashier_shift_id !== null) {
            $this->applyToShift($entry);
        }
    }

    /**
     * Reset all balance projections and re-apply every historical ledger
     * row in occurred_at order. Called by the rebuild command.
     *
     * Uses DELETE rather than TRUNCATE so the operation stays inside any
     * outer transaction (MySQL TRUNCATE is DDL and auto-commits, which
     * would break test isolation under RefreshDatabase).
     */
    public function rebuildAll(): int
    {
        DB::table('cash_drawer_balances')->delete();
        DB::table('shift_balances')->delete();

        $count = 0;
        LedgerEntry::query()
            ->where(function ($q) {
                $q->whereNotNull('cash_drawer_id')
                  ->orWhereNotNull('cashier_shift_id');
            })
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->chunkById(1000, function ($entries) use (&$count) {
                foreach ($entries as $entry) {
                    DB::transaction(fn () => $this->apply($entry));
                    $count++;
                }
            });

        return $count;
    }

    // ---------------------------------------------------------------------

    private function applyToCashDrawer(LedgerEntry $entry): void
    {
        $signedAmount = (float) $entry->amount * $entry->direction->sign();
        $isIn         = $entry->direction === LedgerEntryDirection::In;

        $projection = CashDrawerBalance::query()
            ->where('cash_drawer_id', $entry->cash_drawer_id)
            ->where('currency', $entry->currency)
            ->lockForUpdate()
            ->first();

        if ($projection === null) {
            $projection = new CashDrawerBalance([
                'cash_drawer_id' => $entry->cash_drawer_id,
                'currency'       => $entry->currency,
                'balance'        => 0,
                'total_in'       => 0,
                'total_out'      => 0,
                'in_count'       => 0,
                'out_count'      => 0,
            ]);
        }

        $projection->balance   = (float) $projection->balance + $signedAmount;
        $projection->total_in  = (float) $projection->total_in  + ($isIn ? (float) $entry->amount : 0.0);
        $projection->total_out = (float) $projection->total_out + ($isIn ? 0.0 : (float) $entry->amount);
        $projection->in_count  = $projection->in_count  + ($isIn ? 1 : 0);
        $projection->out_count = $projection->out_count + ($isIn ? 0 : 1);
        $projection->last_entry_id = $entry->id;
        $projection->last_entry_at = $entry->occurred_at;
        $projection->save();
    }

    private function applyToShift(LedgerEntry $entry): void
    {
        $signedAmount = (float) $entry->amount * $entry->direction->sign();
        $isIn         = $entry->direction === LedgerEntryDirection::In;

        $projection = ShiftBalance::query()
            ->where('cashier_shift_id', $entry->cashier_shift_id)
            ->where('currency', $entry->currency)
            ->lockForUpdate()
            ->first();

        if ($projection === null) {
            $projection = new ShiftBalance([
                'cashier_shift_id' => $entry->cashier_shift_id,
                'currency'         => $entry->currency,
                'balance'          => 0,
                'total_in'         => 0,
                'total_out'        => 0,
                'in_count'         => 0,
                'out_count'        => 0,
            ]);
        }

        $projection->balance   = (float) $projection->balance + $signedAmount;
        $projection->total_in  = (float) $projection->total_in  + ($isIn ? (float) $entry->amount : 0.0);
        $projection->total_out = (float) $projection->total_out + ($isIn ? 0.0 : (float) $entry->amount);
        $projection->in_count  = $projection->in_count  + ($isIn ? 1 : 0);
        $projection->out_count = $projection->out_count + ($isIn ? 0 : 1);
        $projection->last_entry_id = $entry->id;
        $projection->last_entry_at = $entry->occurred_at;
        $projection->save();
    }
}
