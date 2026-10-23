<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\Employee;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EmployeeRequest;
use App\Http\Resources\EmployeeResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class EmployeeController extends Controller
{
    private const RELATIONS = ['company', 'user', 'assignments.company', 'assignments.dealership', 'assignments.department'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Employee::class);
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company_id' => ['sometimes', 'integer'],
            'dealership_id' => ['sometimes', 'integer'],
            'department_id' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $employees = Employee::query()
            ->visibleTo($request->user(), Permission::EmployeesView)
            ->with(self::RELATIONS)
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('last_name', 'like', $this->like($s))
                ->orWhere('first_name', 'like', $this->like($s))
                ->orWhere('personnel_number', 'like', $this->like($s))
                ->orWhere('phone_extension', $s)))
            ->when($filters['company_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w
                ->where('company_id', $id)
                ->orWhereHas('assignments', fn ($a) => $a->where('company_id', $id))))
            ->when($filters['dealership_id'] ?? null, fn ($q, $id) => $q->whereHas('assignments', fn ($a) => $a->where('dealership_id', $id)))
            ->when($filters['department_id'] ?? null, fn ($q, $id) => $q->whereHas('assignments', fn ($a) => $a->where('department_id', $id)))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('last_name')->orderBy('first_name')
            ->paginate($this->perPage($request));

        return EmployeeResource::collection($employees);
    }

    public function store(EmployeeRequest $request, Authorizer $authorizer): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $company = Company::query()->find($data['company_id']);

        if ($company === null || ! ($company->isVisibleTo($user, Permission::OrganizationView) || $authorizer->covers($user, Permission::EmployeesView, $company->orgPath()))) {
            throw ValidationException::withMessages(['company_id' => 'Компания не найдена.']);
        }
        if (! $authorizer->covers($user, Permission::EmployeesManage, $company->orgPath())) {
            throw new AuthorizationException('Нет права добавлять сотрудников в эту компанию.');
        }

        $employee = Employee::create($data + ['group_id' => $company->group_id]);

        return (new EmployeeResource($employee->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(Employee $employee): EmployeeResource
    {
        return new EmployeeResource($employee->load(self::RELATIONS));
    }

    public function update(EmployeeRequest $request, Employee $employee): EmployeeResource
    {
        $this->authorize('update', $employee);
        $employee->update($request->validated());

        return new EmployeeResource($employee->load(self::RELATIONS));
    }
}
