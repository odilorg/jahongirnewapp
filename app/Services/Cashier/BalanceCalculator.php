<?php

declare(strict_types=1);

namespace App\Services\Cashier;

use App\Models\BeginningSaldo;
use App\Models\CashierShift;
use App\Models\CashTransaction;

/**
 * Shared shift + balance lookup used by the cashier Telegram bot.
 *
 * Pure extraction from three CashierBotController inline helpers
 * (getShift, getBal, fmtBal). Behaviour must be byte-identical — the
 * controller still uses 1-line delegators so all 20 existing call sites
 * keep their signatures, and new Actions injected with this service
 * compute the same numbers the controller does.
 *
 * Stateless — every method takes its input and returns a value. No
 * caching and no session coupling; caching belongs in a later service
 * once the extraction arc is done.
 */
final class BalanceCalculator
{
    /** Default currency buckets in display order. */
    private const DEFAULT_BUCKETS = ['UZS' => 0, 'USD' => 0, 'EUR' => 0];

    /**
     * The currently open shift for this cashier, or null if none.
     *
     * Latest-opened wins if (somehow) more than one is open — preserves the
     * controller's original semantics; the drawer-singleton guard (B2) is
     * what actually prevents concurrent opens.
     */
    public function getShift(?int $userId): ?CashierShift
    {
        if (! $userId) {
            return null;
        }

        return CashierShift::where('user_id', $userId)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();
    }

    /**
     * Per-currency running balance for a shift.
     *
     * Sources:
     *  - BeginningSaldo rows (carried forward from the prior handover)
     *  - CashTransaction rows narrowed by the drawerTruth() scope which
     *    excludes beds24_external audit-only entries.
     *
     * type='in_out' (historical complex transactions) are skipped — they
     * are accounted for by their paired rows and would double-count here.
     *
     * @return array<string, float> amount per currency; always contains
     *                              UZS/USD/EUR keys, extra buckets added on
     *                              the fly if other currencies appear in
     *                              the rows.
     */
    public function getBal(CashierShift $shift): array
    {
        $b = self::DEFAULT_BUCKETS;

        foreach (BeginningSaldo::where('cashier_shift_id', $shift->id)->get() as $bs) {
            $c = is_string($bs->currency) ? $bs->currency : ($bs->currency->value ?? 'UZS');
            if (! isset($b[$c])) {
                $b[$c] = 0;
            }
            $b[$c] += $bs->amount;
        }

        foreach (CashTransaction::where('cashier_shift_id', $shift->id)->drawerTruth()->get() as $tx) {
            $c = is_string($tx->currency) ? $tx->currency : ($tx->currency->value ?? 'UZS');
            if (! isset($b[$c])) {
                $b[$c] = 0;
            }
            $typeVal = is_string($tx->type) ? $tx->type : ($tx->type->value ?? 'out');
            if ($typeVal === 'in_out') {
                continue;
            }
            $b[$c] += ($typeVal === 'in' ? $tx->amount : -$tx->amount);
        }

        return $b;
    }

    /**
     * Human-readable balance string: "100 000 UZS | 50 USD".
     *
     * Skips zero-amount buckets. Returns '0' when every bucket is zero so
     * callers can concatenate it without a null-guard.
     */
    public function fmtBal(array $b): string
    {
        $parts = [];
        foreach ($b as $c => $amount) {
            if ($amount != 0) {
                $parts[] = number_format($amount, 0) . " {$c}";
            }
        }

        return $parts ? implode(' | ', $parts) : '0';
    }
}
