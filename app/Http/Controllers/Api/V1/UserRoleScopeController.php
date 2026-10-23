<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRoleScope;
use App\Domain\Identity\ScopeType;
use App\Domain\Identity\Services\RoleAssignmentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RoleScopeRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/** Назначение ролей пользователю в области. Правила эскалации — в Authorizer::canGrantRole. */
class UserRoleScopeController extends Controller
{
    public function __construct(private readonly RoleAssignmentService $assignments) {}

    public function store(RoleScopeRequest $request, User $user): JsonResponse
    {
        $this->assignments->grant(
            $request->user(),
            $user,
            Role::query()->with('permissions')->findOrFail($request->integer('role_id')),
            ScopeType::from($request->string('scope_type')->toString()),
            $request->filled('scope_id') ? $request->integer('scope_id') : null,
        );

        return (new UserResource($user->load(['employee', 'group', 'company', 'dealership', 'department', 'roleScopes.role', 'roleScopes.user.roleScopes'])))
            ->response()->setStatusCode(201);
    }

    public function destroy(User $user, UserRoleScope $roleScope): UserResource
    {
        abort_unless($roleScope->user_id === $user->id, 404);
        $roleScope->load(['user.roleScopes', 'role.permissions']);
        $this->assignments->revoke(request()->user(), $roleScope);

        return new UserResource($user->load(['employee', 'group', 'company', 'dealership', 'department', 'roleScopes.role', 'roleScopes.user.roleScopes']));
    }
}
