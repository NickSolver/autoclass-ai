<?php

namespace App\Domain\Identity\Policies;

use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;

final class UserPolicy
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::UsersView);
    }

    public function view(User $actor, User $target): bool
    {
        return $target->isVisibleTo($actor, Permission::UsersView);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->authorizer->canManageUser($actor, $target, Permission::UsersUpdate);
    }

    /** Сброс MFA — только чужой, в пределах области управления. */
    public function resetMfa(User $actor, User $target): bool
    {
        return $actor->id !== $target->id
            && $this->authorizer->canManageUser($actor, $target, Permission::UsersUpdate);
    }

    /** Отключить себя нельзя: это защищает от случайной потери доступа. */
    public function disable(User $actor, User $target): bool
    {
        return $actor->id !== $target->id
            && $this->authorizer->canManageUser($actor, $target, Permission::UsersDisable);
    }
}
