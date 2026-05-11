<?php

declare(strict_types=1);

namespace App\Actions\Cashier;

use App\Enums\DrawerTruthExcludedReason;
use App\Models\CashTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Manager-audited flip of `counts_as_drawer_truth = true` on a
 * beds24_external CashTransaction row that the webhook guards
 * excluded.
 *
 * Phase 1, 2026-05-11. Invoked from
 * `CashTransactionResource`'s "Учесть в кассе" per-row action.
 * Lives here (not in the closure) per CLAUDE.md hard line:
 *   "No business logic embedded inside Filament
 *    ->action(function () { … }) closures past ~10 LOC"
 *
 * Writes flipped_by / flipped_at / note to the row and emits a
 * `Log::info` audit line capturing the previous excluded reason,
 * the actor (id + name), and the manager's stated reason.
 *
 * Idempotency: the action is a no-op on a row that is already
 * `counts_as_drawer_truth=true`. Callers should usually guard at
 * the UI level (action `visible` predicate) but the no-op
 * defends against double-clicks / racing tabs.
 */
final class FlipDrawerTruthAction
{
    /**
     * @param  CashTransaction  $row  Row to flip. Must be loaded.
     * @param  User  $actor  Manager performing the flip.
     * @param  string  $note  One-line reasoning ≤255 chars.
     */
    public function execute(CashTransaction $row, User $actor, string $note): void
    {
        if ($row->counts_as_drawer_truth) {
            Log::info('FlipDrawerTruthAction: row already drawer-truth, no-op', [
                'cash_transaction_id' => $row->id,
                'actor_user_id' => $actor->id,
            ]);

            return;
        }

        $previousReason = $row->drawer_truth_excluded_reason instanceof DrawerTruthExcludedReason
            ? $row->drawer_truth_excluded_reason->value
            : $row->drawer_truth_excluded_reason;

        // forceFill + save: belt-and-suspenders against future
        // $fillable tightening (see feedback_no_mass_assign_for_system_state).
        // The four audit columns are in $fillable today, but a silent
        // drop on a system-state write is exactly the failure class we
        // want to be immune to here.
        $row->forceFill([
            'counts_as_drawer_truth' => true,
            'drawer_truth_flipped_by_user_id' => $actor->id,
            'drawer_truth_flipped_at' => now(),
            'drawer_truth_flip_note' => $note,
        ])->save();

        Log::info('CashTransaction drawer_truth flipped by manager', [
            'cash_transaction_id' => $row->id,
            'beds24_booking_id' => $row->beds24_booking_id,
            'amount' => (float) $row->amount,
            'previous_excluded_reason' => $previousReason,
            'flipped_by_user_id' => $actor->id,
            'flipped_by_user_name' => $actor->name,
            'flipped_at' => now()->toIso8601String(),
            'note' => $note,
        ]);
    }
}
