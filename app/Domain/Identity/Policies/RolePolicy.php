<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;

/**
 * Роли глобальны: изменять их может только обладатель roles.manage на уровне группы.
 * Просматривать — также те, кто назначает роли пользователям.
 */
final class RolePolicy
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::RolesManage)
            || $actor->hasPermission(Permission::UsersCreate)
            || $actor->hasPermission(Permission::UsersUpdate);
    }

    public function view(User $actor, Role $role): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $this->authorizer->hasGroupLevel($actor, Permission::RolesManage);
    }

    public function update(User $actor, Role $role): bool
    {
        return $this->authorizer->hasGroupLevel($actor, Permission::RolesManage);
    }

    public function delete(User $actor, Role $role): bool
    {
        return ! $role->is_system && $this->authorizer->hasGroupLevel($actor, Permission::RolesManage);
    }
}
