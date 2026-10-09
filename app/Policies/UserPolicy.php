<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    private function sameTenantOrSystem(User $actor, User $target): bool
    {
        if (is_null($actor->tenant_id)) {
            return true;
        }

        return ! is_null($target->tenant_id)
            && (int) $actor->tenant_id === (int) $target->tenant_id;
    }

    private function canManageRole(User $actor, mixed $role): bool
    {
        $role = (int) $role;

        if ($actor->isRootAdmin()) {
            return true;
        }

        if ($actor->isRoot() || $actor->isAdministrator()) {
            return in_array($role, [User::ROLE_ADMIN, User::ROLE_OPERATOR, User::ROLE_TECHNICIAN], true);
        }

        if ($actor->isOperator()) {
            return in_array($role, [User::ROLE_OPERATOR, User::ROLE_TECHNICIAN], true);
        }

        return false;
    }

    private function canManageTarget(User $actor, User $target): bool
    {
        if ($actor->isOperator()) {
            return $this->sameTenantOrSystem($actor, $target)
                && (
                    (int) $actor->id === (int) $target->id
                    || (int) $target->roles === User::ROLE_TECHNICIAN
                );
        }

        return $this->sameTenantOrSystem($actor, $target)
            && $this->canManageRole($actor, $target->roles);
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('users') || $user->hasPermission('users.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('users') || $user->hasPermission('users.create');
    }

    public function view(User $user, User $target): bool
    {
        if ((int) $user->id === (int) $target->id) {
            return true;
        }

        return $this->viewAny($user) && $this->canManageTarget($user, $target);
    }

    public function update(User $user, User $target): bool
    {
        if ((int) $user->id === (int) $target->id) {
            return true;
        }

        return ($user->hasPermission('users') || $user->hasPermission('users.update'))
            && $this->canManageTarget($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        return ($user->hasPermission('users') || $user->hasPermission('users.delete'))
            && $this->canManageTarget($user, $target);
    }

    public function assignRole(User $user, mixed $role): bool
    {
        return $this->canManageRole($user, $role);
    }
}
