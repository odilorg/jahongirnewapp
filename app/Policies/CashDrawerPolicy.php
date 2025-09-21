<?php

namespace App\Policies;

use App\Models\CashDrawer;
use App\Models\User;

class CashDrawerPolicy
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
    public function view(User $user, CashDrawer $cashDrawer): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager', 'cashier']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CashDrawer $cashDrawer): bool
    {
        return $user->hasAnyRole(['super_admin', 'admin', 'manager']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CashDrawer $cashDrawer): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CashDrawer $cashDrawer): bool
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CashDrawer $cashDrawer): bool
    {
        return $user->hasRole('super_admin');
    }
}
