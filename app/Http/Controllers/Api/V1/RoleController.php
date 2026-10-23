<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Services\RoleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RoleRequest;
use App\Http\Resources\RoleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    /** Все роли (без пагинации — их немного). Ответ в едином формате {data: [...]}. */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        return response()->json([
            'data' => RoleResource::collection(Role::query()->with('permissions')->withCount('assignments')->orderBy('name')->get()),
        ]);
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $this->authorize('create', Role::class);
        $role = $this->roles->create($request->validated());

        return (new RoleResource($role->load('permissions')->loadCount('assignments')))->response()->setStatusCode(201);
    }

    public function show(Role $role): RoleResource
    {
        $this->authorize('view', $role);

        return new RoleResource($role->load('permissions')->loadCount('assignments'));
    }

    /** Изменение роли с оптимистической блокировкой по lock_version. */
    public function update(RoleRequest $request, Role $role): RoleResource
    {
        $this->authorize('update', $role);
        $role = $this->roles->update($role, $request->validated());

        return new RoleResource($role->load('permissions')->loadCount('assignments'));
    }

    public function destroy(Role $role): Response
    {
        $this->authorize('delete', $role);
        $this->roles->delete($role);

        return response()->noContent();
    }
}
