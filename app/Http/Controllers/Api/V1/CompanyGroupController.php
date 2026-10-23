<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\CompanyGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CompanyGroupRequest;
use App\Http\Resources\CompanyGroupResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Группы компаний. Создание — только при первичной настройке (команда avtoklass:create-superadmin). */
class CompanyGroupController extends Controller
{
    /** Группы компаний (без пагинации). Ответ в едином формате {data: [...]}. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CompanyGroup::class);

        return response()->json([
            'data' => CompanyGroupResource::collection(CompanyGroup::query()->visibleTo($request->user(), Permission::OrganizationView)->orderBy('name')->get()),
        ]);
    }

    public function show(CompanyGroup $group): CompanyGroupResource
    {
        return new CompanyGroupResource($group);
    }

    public function update(CompanyGroupRequest $request, CompanyGroup $group): CompanyGroupResource
    {
        $this->authorize('update', $group);
        $group->update($request->validated());

        return new CompanyGroupResource($group);
    }
}
