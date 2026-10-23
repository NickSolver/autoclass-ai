<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Models\EmployeeAssignment;
use App\Domain\Organization\OrgPath;
use App\Domain\Organization\Services\OrgPathResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\EmployeeAssignmentRequest;
use App\Http\Resources\EmployeeAssignmentResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Назначения сотрудника. Нужны права и на карточку сотрудника, и на подразделение назначения.
 */
class EmployeeAssignmentController extends Controller
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function store(EmployeeAssignmentRequest $request, Employee $employee, OrgPathResolver $paths): JsonResponse
    {
        $data = $request->validated();
        $path = $paths->fromUnits(null, $data['company_id'] ?? null, $data['dealership_id'] ?? null, $data['department_id'] ?? null);
        $this->authorizeAssignment($request, $employee, $path);

        $assignment = DB::transaction(function () use ($employee, $data, $path) {
            if ($data['is_primary'] ?? false) {
                $employee->assignments()->update(['is_primary' => false]);
            }

            return $employee->assignments()->create([
                ...$path->toColumns(),
                'position' => $data['position'] ?? null,
                'is_primary' => $data['is_primary'] ?? false,
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'] ?? null,
            ]);
        });

        return (new EmployeeAssignmentResource($assignment->load(['company', 'dealership', 'department'])))
            ->response()->setStatusCode(201);
    }

    public function update(EmployeeAssignmentRequest $request, Employee $employee, EmployeeAssignment $assignment): EmployeeAssignmentResource
    {
        $this->authorizeAssignment($request, $employee, $assignment->orgPath());

        DB::transaction(function () use ($request, $employee, $assignment) {
            if ($request->boolean('is_primary')) {
                $employee->assignments()->whereKeyNot($assignment->id)->update(['is_primary' => false]);
            }
            $assignment->update($request->validated());
        });

        return new EmployeeAssignmentResource($assignment->load(['company', 'dealership', 'department']));
    }

    /** Удаление — для ошибочно созданных назначений. Перевод оформляется датой окончания. */
    public function destroy(Request $request, Employee $employee, EmployeeAssignment $assignment): Response
    {
        $this->authorizeAssignment($request, $employee, $assignment->orgPath());
        $assignment->delete();

        return response()->noContent();
    }

    private function authorizeAssignment(Request $request, Employee $employee, OrgPath $path): void
    {
        $this->authorize('update', $employee);
        if (! $this->authorizer->covers($request->user(), Permission::EmployeesManage, $path)) {
            throw new AuthorizationException('Нет права назначать сотрудников в это подразделение.');
        }
    }
}
