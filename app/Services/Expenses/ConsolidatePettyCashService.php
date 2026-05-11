<?php

declare(strict_types=1);

namespace App\Services\Expenses;

use App\Models\CashExpense;
use App\Models\Expense;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Monthly petty-cash → main hotel-ops expenses consolidation, and per-row
 * reversal of mistakenly-posted rows.
 *
 * v1 scope (locked):
 *   - UZS rows only. USD/EUR/RUB rows are silently skipped and counted —
 *     manual handling for FX moves to Phase 2 along with audit fields
 *     (original_amount / fx_rate / fx_rate_date).
 *   - All-or-nothing transaction per consolidation run.
 *   - Idempotency via WHERE consolidated_at IS NULL gate.
 *   - Per-row Unpost: soft-deletes the linked expense and clears the source
 *     pointer. Re-consolidating after unpost creates a NEW expenses row;
 *     the soft-deleted prior row stays as audit trail.
 *
 * Rejected cash_expenses (rejected_at IS NOT NULL) are skipped — these were
 * declined by the owner via the Telegram approval flow and must NOT post.
 * Rows missing expense_category_id are skipped defensively.
 */
class ConsolidatePettyCashService
{
    /**
     * Consolidate one month's UZS petty-cash rows into main expenses.
     *
     * @param  CarbonInterface|string  $month  Carbon date inside the target month, or 'YYYY-MM' string.
     * @param  int  $hotelId  Operator-selected. Required — expenses.hotel_id is NOT NULL.
     * @param  int  $actorUserId  Stamps expenses.created_by for audit attribution.
     * @return array{posted: int, skipped_fx: int, skipped_invalid: int}
     */
    public function consolidateMonth(CarbonInterface|string $month, int $hotelId, int $actorUserId): array
    {
        $period = $month instanceof CarbonInterface
            ? $month->copy()->startOfMonth()
            : Carbon::createFromFormat('Y-m', $month)->startOfMonth();

        $start = $period->copy()->startOfMonth();
        $end = $period->copy()->endOfMonth();

        return DB::transaction(function () use ($start, $end, $hotelId, $actorUserId): array {
            $eligible = CashExpense::query()
                ->whereNull('consolidated_at')
                ->whereNull('rejected_at')
                ->whereBetween('occurred_at', [$start, $end])
                ->lockForUpdate()
                ->get();

            $posted = 0;
            $skippedFx = 0;
            $skippedInvalid = 0;

            foreach ($eligible as $cash) {
                $currency = strtoupper((string) ($cash->currency ?? 'UZS'));

                if ($currency !== 'UZS') {
                    // FX rows out of v1 scope. Operator will handle manually.
                    // No consolidated_at stamp → these rows remain eligible next time.
                    $skippedFx++;

                    continue;
                }

                if ($cash->expense_category_id === null) {
                    // Defensive: every cash_expense should have a category from the bot
                    // (the schema requires it). Belt-and-suspenders skip if not.
                    $skippedInvalid++;

                    continue;
                }

                $expense = Expense::create([
                    'expense_category_id' => $cash->expense_category_id,
                    'name' => $this->buildName($cash),
                    'expense_date' => $cash->occurred_at->toDateString(),
                    'amount' => (float) $cash->amount,
                    'hotel_id' => $hotelId,
                    'payment_type' => 'naqd',
                    'created_by' => $actorUserId,
                    'cash_expense_id' => $cash->id,
                ]);

                // Per feedback_no_mass_assign_for_system_state: never $model->update()
                // for system-state writes. forceFill->save bypasses fillable silently-fail.
                $cash->forceFill([
                    'consolidated_at' => now(),
                    'consolidated_expense_id' => $expense->id,
                    // Clear any prior unpost trail — if this row was unposted then
                    // re-consolidated, the new posting is fresh state.
                    'consolidation_unposted_at' => null,
                    'consolidation_unposted_reason' => null,
                ])->save();

                $posted++;
            }

            return [
                'posted' => $posted,
                'skipped_fx' => $skippedFx,
                'skipped_invalid' => $skippedInvalid,
            ];
        });
    }

    /**
     * Reverse a single consolidated petty-cash posting.
     *
     * Soft-deletes the linked Expense row, clears the source pointer on the
     * cash_expense, and stamps when/why the unpost happened. The Expense row
     * remains queryable via withTrashed() for audit; re-consolidating the same
     * cash_expense produces a NEW Expense row, not a restore of the trashed one.
     *
     * @param  int  $expenseId  The consolidated expense to unpost.
     * @param  string  $reason  Required ≥ 5 chars — written to cash_expenses.
     *
     * @throws \DomainException When the expense isn't a consolidated petty-cash row,
     *                          or its source link is missing/broken.
     */
    public function unpostExpense(int $expenseId, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new \DomainException('Unpost reason must be at least 5 characters.');
        }

        DB::transaction(function () use ($expenseId, $reason): void {
            // Only allow unpost on consolidated petty-cash rows. Reject any direct
            // ops/owner expenses that operator might try to unpost by mistake.
            $expense = Expense::query()
                ->whereNotNull('cash_expense_id')
                ->lockForUpdate()
                ->findOrFail($expenseId);

            $cash = CashExpense::query()
                ->where('id', $expense->cash_expense_id)
                ->lockForUpdate()
                ->first();

            if ($cash === null) {
                // FK ON DELETE SET NULL means this can happen if cash_expense was
                // hard-deleted post-consolidation. Block unpost — operator needs to
                // investigate via tinker.
                throw new \DomainException(
                    "Cannot unpost expense #{$expenseId}: linked cash_expense missing. "
                    .'Investigate manually before retrying.'
                );
            }

            // Clear the source pointer BEFORE soft-deleting the expense to avoid
            // any transient state where both rows reference each other but the
            // expense is trashed.
            $cash->forceFill([
                'consolidated_at' => null,
                'consolidated_expense_id' => null,
                'consolidation_unposted_at' => now(),
                'consolidation_unposted_reason' => $reason,
            ])->save();

            $expense->delete(); // soft delete via SoftDeletes trait on Expense model
        });
    }

    /**
     * Human-readable name on the consolidated expenses row.
     * Truncated to fit expenses.name (VARCHAR — no explicit length on existing
     * schema; Laravel default 255).
     */
    private function buildName(CashExpense $cash): string
    {
        $prefix = '[Petty cash] ';
        $body = trim((string) $cash->description);
        $name = $prefix.($body !== '' ? $body : 'expense');

        return mb_substr($name, 0, 255);
    }
}
