<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRoleScope;
use App\Domain\Identity\ScopeType;
use App\Domain\Organization\Services\OrgPathResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class RoleAssignmentService
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly OrgPathResolver $paths,
        private readonly RoleManagerGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function grant(User $actor, User $target, Role $role, ScopeType $type, ?int $scopeId): UserRoleScope
    {
        $scopeId = $type === ScopeType::Own ? 0 : (int) $scopeId;

        if (! $role->allowsScope($type)) {
            throw ValidationException::withMessages(['scope_type' => 'Эту роль нельзя назначить на выбранный уровень.']);
        }
        if ($type !== ScopeType::Own && $this->paths->forScope($type, $scopeId) === null) {
            throw ValidationException::withMessages(['scope_id' => 'Подразделение не найдено.']);
        }
        if ($type === ScopeType::Own && $target->employee_id === null) {
            throw ValidationException::withMessages(['scope_type' => 'Область «Собственные записи» требует связи учётной записи с сотрудником.']);
        }
        if (! $this->authorizer->canGrantRole($actor, $target, $role, $type, $scopeId)) {
            throw new AuthorizationException('Недостаточно прав для назначения этой роли в выбранной области.');
        }

        $exists = $target->roleScopes()
            ->where(['role_id' => $role->id, 'scope_type' => $type->value, 'scope_id' => $scopeId])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['role_id' => 'Такое назначение уже существует.']);
        }

        $assignment = $target->roleScopes()->create([
            'role_id' => $role->id,
            'scope_type' => $type,
            'scope_id' => $scopeId,
            'granted_by' => $actor->id,
        ]);

        $this->audit->record('user.role_granted', $target, [
            'role' => ['old' => null, 'new' => $role->name],
            'scope' => ['old' => null, 'new' => $type->value.':'.$scopeId],
        ]);
        $target->unsetRelation('roleScopes');

        return $assignment;
    }

    public function revoke(User $actor, UserRoleScope $assignment): void
    {
        if (! $this->authorizer->canRevokeRole($actor, $assignment)) {
            throw new AuthorizationException('Недостаточно прав для отзыва этого назначения.');
        }

        $this->guard->protect(fn () => $assignment->delete());

        $this->audit->record('user.role_revoked', $assignment->user, [
            'role' => ['old' => $assignment->role->name, 'new' => null],
            'scope' => ['old' => $assignment->scope_type->value.':'.$assignment->scope_id, 'new' => null],
        ]);
    }
}
