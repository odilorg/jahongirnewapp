<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Zayavka;
use Illuminate\Auth\Access\HandlesAuthorization;

class ZayavkaPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_zayavka');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Zayavka $zayavka): bool
    {
        return $user->can('view_zayavka');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('create_zayavka');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Zayavka $zayavka): bool
    {
        return $user->can('update_zayavka');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Zayavka $zayavka): bool
    {
        return $user->can('delete_zayavka');
    }

    /**
     * Determine whether the user can bulk delete.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_zayavka');
    }

    /**
     * Determine whether the user can permanently delete.
     */
    public function forceDelete(User $user, Zayavka $zayavka): bool
    {
        return $user->can('force_delete_zayavka');
    }

    /**
     * Determine whether the user can permanently bulk delete.
     */
    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_zayavka');
    }

    /**
     * Determine whether the user can restore.
     */
    public function restore(User $user, Zayavka $zayavka): bool
    {
        return $user->can('restore_zayavka');
    }

    /**
     * Determine whether the user can bulk restore.
     */
    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_zayavka');
    }

    /**
     * Determine whether the user can replicate.
     */
    public function replicate(User $user, Zayavka $zayavka): bool
    {
        return $user->can('replicate_zayavka');
    }

    /**
     * Determine whether the user can reorder.
     */
    public function reorder(User $user): bool
    {
        return $user->can('reorder_zayavka');
    }
}
