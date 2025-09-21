<?php

namespace App\Policies;

use App\Models\CashierShift;
use App\Models\User;

class CashierShiftPolicy
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
    public function view(User $user, CashierShift $cashierShift): bool
    {
        // Users can view their own shifts, managers and admins can view all
        return $user->id === $cashierShift->user_id || 
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
     * Determine whether the user can start a shift.
     */
    public function start(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager', 'cashier']);
    }

    /**
     * Determine whether the user can close a shift.
     */
    public function close(User $user, CashierShift $cashierShift): bool
    {
        // Users can close their own shifts, managers and admins can close any
        return $user->id === $cashierShift->user_id || 
               $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CashierShift $cashierShift): bool
    {
        // Only managers and admins can update shifts
        return $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CashierShift $cashierShift): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CashierShift $cashierShift): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CashierShift $cashierShift): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can make manager adjustments.
     */
    public function managerAdjustment(User $user, CashierShift $cashierShift): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }
}
