<?php

namespace App\Http\Resources;

use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRoleScope;
use App\Domain\Identity\Permission;
use App\Domain\Identity\ScopeType;
use App\Domain\Organization\Services\OrgPathResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var User $actor */
        $actor = $request->user();

        return [
            'id' => $this->id,
            'login' => $this->login,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'employee' => $this->whenLoaded('employee', fn () => $this->employee
                ? ['id' => $this->employee->id, 'full_name' => $this->employee->fullName()]
                : null),
            'home' => [
                'group' => $this->whenLoaded('group', fn () => ['id' => $this->group->id, 'name' => $this->group->name]),
                'company' => $this->whenLoaded('company', fn () => $this->company ? ['id' => $this->company->id, 'name' => $this->company->name] : null),
                'dealership' => $this->whenLoaded('dealership', fn () => $this->dealership ? ['id' => $this->dealership->id, 'name' => $this->dealership->name] : null),
                'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            ],
            'role_scopes' => $this->whenLoaded('roleScopes', fn () => $this->roleScopes
                ->map(fn (UserRoleScope $s) => $this->presentRoleScope($s, $actor))->values()),
            'mfa_enabled' => $this->mfa_enabled_at !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'update' => $actor->can('update', $this->resource),
                'disable' => $actor->can('disable', $this->resource),
                'reset_mfa' => $actor->can('resetMfa', $this->resource),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function presentRoleScope(UserRoleScope $scope, User $actor): array
    {
        $authorizer = app(Authorizer::class);
        $paths = app(OrgPathResolver::class);

        // Название чужого подразделения не раскрывается.
        $visible = $scope->scope_type === ScopeType::Own
            || $authorizer->covers($actor, Permission::UsersView, $paths->forScope($scope->scope_type, $scope->scope_id))
            || $authorizer->covers($actor, Permission::OrganizationView, $paths->forScope($scope->scope_type, $scope->scope_id));

        return [
            'id' => $scope->id,
            'role' => ['id' => $scope->role->id, 'name' => $scope->role->name],
            'scope_type' => $scope->scope_type->value,
            'scope_id' => $scope->scope_id,
            'scope_label' => $visible ? $paths->labelFor($scope->scope_type, $scope->scope_id) : 'Вне вашей области',
            'can_revoke' => $authorizer->canRevokeRole($actor, $scope),
        ];
    }
}
