<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Services\OrgPathResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\DepartmentRequest;
use App\Http\Resources\DepartmentResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class DepartmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Department::class);
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company_id' => ['sometimes', 'integer'],
            'dealership_id' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $departments = Department::query()
            ->visibleTo($request->user(), Permission::OrganizationView)
            ->with(['company', 'dealership'])
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', $this->like($s))->orWhere('code', 'like', $this->like($s))))
            ->when($filters['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($filters['dealership_id'] ?? null, fn ($q, $id) => $q->where('dealership_id', $id))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return DepartmentResource::collection($departments);
    }

    public function store(DepartmentRequest $request, Authorizer $authorizer, OrgPathResolver $paths): JsonResponse
    {
        $data = $request->validated();
        $parent = $paths->fromUnits(null, $data['company_id'], $data['dealership_id'] ?? null, null);

        if (! $authorizer->covers($request->user(), Permission::OrganizationView, $parent)) {
            throw ValidationException::withMessages(['company_id' => 'Подразделение не найдено.']);
        }
        if (! $authorizer->covers($request->user(), Permission::OrganizationManage, $parent)) {
            throw new AuthorizationException('Нет права создавать отделы в этом подразделении.');
        }

        $department = Department::create($data + ['group_id' => $parent->groupId]);

        return (new DepartmentResource($department->load(['company', 'dealership'])))->response()->setStatusCode(201);
    }

    public function show(Department $department): DepartmentResource
    {
        return new DepartmentResource($department->load(['company', 'dealership']));
    }

    public function update(DepartmentRequest $request, Department $department): DepartmentResource
    {
        $this->authorize('update', $department);
        $department->update($request->validated());

        return new DepartmentResource($department->load(['company', 'dealership']));
    }
}
