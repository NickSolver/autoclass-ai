<?php

namespace App\Domain\Organization\Services;

use App\Domain\Identity\ScopeType;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\CompanyGroup;
use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\OrgPath;
use Illuminate\Validation\ValidationException;

/**
 * Строит и проверяет пути в оргструктуре из идентификаторов подразделений.
 */
final class OrgPathResolver
{
    /** Путь целевого подразделения области; null — подразделение не существует или тип own. */
    public function forScope(ScopeType $type, int $id): ?OrgPath
    {
        return match ($type) {
            ScopeType::Group => CompanyGroup::query()->find($id)?->orgPath(),
            ScopeType::Company => Company::query()->find($id)?->orgPath(),
            ScopeType::Dealership => Dealership::query()->find($id)?->orgPath(),
            ScopeType::Department => Department::query()->find($id)?->orgPath(),
            ScopeType::Own => null,
        };
    }

    public function labelFor(ScopeType $type, int $id): ?string
    {
        return match ($type) {
            ScopeType::Group => CompanyGroup::query()->find($id)?->name,
            ScopeType::Company => Company::query()->find($id)?->name,
            ScopeType::Dealership => ($d = Dealership::query()->with('company')->find($id)) ? "{$d->company->name} / {$d->name}" : null,
            ScopeType::Department => ($d = Department::query()->with(['company', 'dealership'])->find($id))
                ? implode(' / ', array_filter([$d->company->name, $d->dealership?->name, $d->name]))
                : null,
            ScopeType::Own => ScopeType::Own->label(),
        };
    }

    /**
     * Полный путь по самому глубокому указанному подразделению. Указанные родители
     * обязаны совпадать с фактическими, иначе — ошибка валидации.
     *
     * @throws ValidationException
     */
    public function fromUnits(?int $groupId, ?int $companyId, ?int $dealershipId, ?int $departmentId): OrgPath
    {
        if ($departmentId !== null) {
            $path = Department::query()->find($departmentId)?->orgPath()
                ?? throw ValidationException::withMessages(['department_id' => 'Отдел не найден.']);
        } elseif ($dealershipId !== null) {
            $path = Dealership::query()->find($dealershipId)?->orgPath()
                ?? throw ValidationException::withMessages(['dealership_id' => 'Дилерский центр не найден.']);
        } elseif ($companyId !== null) {
            $path = Company::query()->find($companyId)?->orgPath()
                ?? throw ValidationException::withMessages(['company_id' => 'Компания не найдена.']);
        } elseif ($groupId !== null) {
            $path = CompanyGroup::query()->find($groupId)?->orgPath()
                ?? throw ValidationException::withMessages(['group_id' => 'Группа компаний не найдена.']);
        } else {
            throw ValidationException::withMessages(['company_id' => 'Укажите подразделение.']);
        }

        $mismatch = [];
        if ($groupId !== null && $groupId !== $path->groupId) {
            $mismatch['group_id'] = 'Подразделение не относится к указанной группе компаний.';
        }
        if ($companyId !== null && $companyId !== $path->companyId) {
            $mismatch['company_id'] = 'Подразделение не относится к указанной компании.';
        }
        if ($dealershipId !== null && $dealershipId !== $path->dealershipId) {
            $mismatch['dealership_id'] = 'Отдел не относится к указанному дилерскому центру.';
        }
        if ($mismatch !== []) {
            throw ValidationException::withMessages($mismatch);
        }

        return $path;
    }
}
