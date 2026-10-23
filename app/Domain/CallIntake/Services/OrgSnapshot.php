<?php

namespace App\Domain\CallIntake\Services;

use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\CompanyGroup;
use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Models\EmployeeAssignment;
use App\Domain\Organization\OrgPath;

/**
 * Исторический снимок оргструктуры на момент приёма звонка (ТЗ, разд. 3): названия подразделений,
 * сотрудник и его должность. Последующие переименования и переводы снимок не меняют.
 */
final class OrgSnapshot
{
    /** @return array<string, mixed> */
    public function capture(OrgPath $path, ?Employee $employee): array
    {
        $assignment = $employee?->assignments()
            ->where('company_id', $path->companyId)
            ->where('starts_on', '<=', now()->toDateString())
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', now()->toDateString()))
            ->orderByDesc('is_primary')
            ->first();

        return [
            'captured_at' => now()->toIso8601String(),
            'group' => CompanyGroup::query()->find($path->groupId)?->only(['id', 'name']),
            'company' => Company::query()->find($path->companyId)?->only(['id', 'name']),
            'dealership' => $path->dealershipId ? Dealership::query()->find($path->dealershipId)?->only(['id', 'name']) : null,
            'department' => $path->departmentId ? Department::query()->find($path->departmentId)?->only(['id', 'name']) : null,
            'employee' => $employee ? [
                'id' => $employee->id,
                'full_name' => $employee->fullName(),
                'position' => $assignment instanceof EmployeeAssignment ? $assignment->position : null,
                'phone_extension' => $employee->phone_extension,
            ] : null,
        ];
    }
}
