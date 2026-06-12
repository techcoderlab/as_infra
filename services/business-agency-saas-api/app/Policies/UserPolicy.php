<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return ! is_null($user->current_tenant_id) && $user->can('manage users');
    }

    public function view(User $user, User $target): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->current_tenant_id === $target->current_tenant_id && $user->can('manage users');
    }

    public function update(User $user, User $target): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->current_tenant_id === $target->current_tenant_id && $user->can('manage users');
    }

    public function delete(User $user, User $target): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        if ($user->id === $target->id) {
            return false;
        } // Prevent self-deletion

        return $user->current_tenant_id === $target->current_tenant_id && $user->can('manage users');
    }
}
