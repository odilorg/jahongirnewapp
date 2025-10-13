<?php

namespace App\Policies;

use App\Models\CashTransaction;
use App\Models\User;

class CashTransactionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager', 'cashier']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, CashTransaction $cashTransaction): bool
    {
        // Users can view transactions from their shifts, managers and admins can view all
        return $user->id === $cashTransaction->shift->user_id || 
               $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager', 'cashier']);
    }

    /**
     * Determine whether the user can create a transaction for a specific shift.
     */
    public function createForShift(User $user, $shiftId): bool
    {
        // Check if user has an open shift or is manager/admin
        if ($user->hasAnyRole(['super_admin', 'admin', 'manager'])) {
            return true;
        }

        $shift = \App\Models\CashierShift::find($shiftId);
        return $shift && $shift->user_id === $user->id && $shift->isOpen();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CashTransaction $cashTransaction): bool
    {
        // Managers and admins can always edit
        if ($user->hasAnyRole(['super_admin', 'admin', 'manager'])) {
            return true;
        }

        // Cashiers can edit their own transactions only if:
        // 1. They created the transaction
        // 2. The shift is still open
        if ($user->id === $cashTransaction->created_by && $cashTransaction->shift->isOpen()) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CashTransaction $cashTransaction): bool
    {
        // Users can delete their own transactions if shift is still open
        if ($user->id === $cashTransaction->created_by && $cashTransaction->shift->isOpen()) {
            return true;
        }

        return $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CashTransaction $cashTransaction): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CashTransaction $cashTransaction): bool
    {
        return $user->hasRole('super_admin');
    }
}
