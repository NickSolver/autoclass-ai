<?php

namespace App\Domain\Identity\Access;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRoleScope;
use App\Domain\Identity\Permission;
use App\Domain\Identity\ScopeType;
use App\Domain\Organization\OrgPath;
use App\Domain\Organization\Services\OrgPathResolver;

/**
 * Проверки «покрывает ли область действующего пользователя объект/назначение».
 * Используется политиками и сервисами; единственное место правил эскалации (ADR-0001, п. 5).
 */
final class Authorizer
{
    public function __construct(
        private readonly AccessResolver $resolver,
        private readonly OrgPathResolver $paths,
    ) {}

    public function covers(User $actor, Permission $permission, ?OrgPath $path): bool
    {
        return $this->resolver->scopeFor($actor, $permission)->covers($path);
    }

    /** Право есть на уровне группы, к которой относится путь (глобальные операции). */
    public function hasGroupLevel(User $actor, Permission $permission, ?int $groupId = null): bool
    {
        $groups = $this->resolver->scopeFor($actor, $permission)->groupIds;

        return $groupId === null ? $groups !== [] : in_array($groupId, $groups, true);
    }

    /**
     * Действующий может управлять учётной записью, только если её домашнее подразделение
     * и все её назначения ролей лежат в его области для данного права.
     */
    public function canManageUser(User $actor, User $target, Permission $permission): bool
    {
        if (! $this->covers($actor, $permission, $target->orgPath())) {
            return false;
        }

        foreach ($target->roleScopes as $assignment) {
            if (! $this->coversAssignment($actor, $permission, $target, $assignment->scope_type, $assignment->scope_id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Выдать или отозвать роль в области можно, если действующий управляет пользователем
     * и обладает каждым правом роли в области, покрывающей назначение (защита от эскалации).
     */
    public function canGrantRole(User $actor, User $target, Role $role, ScopeType $type, int $scopeId): bool
    {
        if (! $this->canManageUser($actor, $target, Permission::UsersUpdate)) {
            return false;
        }

        if (! $this->coversAssignment($actor, Permission::UsersUpdate, $target, $type, $scopeId)) {
            return false;
        }

        foreach ($role->permissionEnums() as $permission) {
            if (! $this->coversAssignment($actor, $permission, $target, $type, $scopeId)) {
                return false;
            }
        }

        return true;
    }

    public function canRevokeRole(User $actor, UserRoleScope $assignment): bool
    {
        return $this->canGrantRole($actor, $assignment->user, $assignment->role, $assignment->scope_type, $assignment->scope_id);
    }

    private function coversAssignment(User $actor, Permission $permission, User $target, ScopeType $type, int $scopeId): bool
    {
        // Область own относится к записям самого пользователя — её путь равен домашнему подразделению.
        $path = $type === ScopeType::Own ? $target->orgPath() : $this->paths->forScope($type, $scopeId);

        return $this->covers($actor, $permission, $path);
    }
}
