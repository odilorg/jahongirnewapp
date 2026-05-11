<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Expense;
use Illuminate\Support\Facades\Auth;

/**
 * Audit-attribution for hotel-ops expense rows.
 *
 * Filament admin form does NOT set created_by explicitly; we stamp it
 * from the authenticated user at creating-time. CLI/seeder/tinker writes
 * (no auth context) leave created_by NULL, which is fine — the column is
 * nullable and an unattributed row is still useful as ledger data.
 *
 * Pattern mirrors what cash_expenses does, but cash_expenses sets
 * `created_by` explicitly in the service. Here the Filament form is the
 * sole creation path so the observer is the cleanest hook.
 */
class ExpenseObserver
{
    public function creating(Expense $expense): void
    {
        if ($expense->created_by === null && Auth::check()) {
            $expense->created_by = Auth::id();
        }
    }
}
