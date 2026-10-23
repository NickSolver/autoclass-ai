<?php

namespace App\Domain\Organization\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\OrgPath;
use Database\Factories\EmployeeAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Назначение сотрудника в подразделение на период. История не переписывается:
 * при переводе старое назначение закрывается датой окончания, создаётся новое.
 *
 * @property int $id
 * @property int $employee_id
 * @property int $group_id
 * @property int $company_id
 * @property ?int $dealership_id
 * @property ?int $department_id
 * @property ?string $position
 * @property bool $is_primary
 * @property Carbon $starts_on
 * @property ?Carbon $ends_on
 * @property-read Employee $employee
 * @property-read Company $company
 * @property-read ?Dealership $dealership
 * @property-read ?Department $department
 */
#[UseFactory(EmployeeAssignmentFactory::class)]
class EmployeeAssignment extends Model
{
    /** @use HasFactory<EmployeeAssignmentFactory> */
    use Auditable, HasFactory;

    protected $fillable = [
        'employee_id', 'group_id', 'company_id', 'dealership_id', 'department_id',
        'position', 'is_primary', 'starts_on', 'ends_on',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Dealership, $this> */
    public function dealership(): BelongsTo
    {
        return $this->belongsTo(Dealership::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function orgPath(): OrgPath
    {
        return new OrgPath($this->group_id, $this->company_id, $this->dealership_id, $this->department_id);
    }

    public function isCurrent(?Carbon $on = null): bool
    {
        $on ??= Carbon::today();

        return $this->starts_on->lte($on) && ($this->ends_on === null || $this->ends_on->gte($on));
    }

    public function auditSubjectType(): string
    {
        return 'employee_assignment';
    }

    public function auditLabel(): ?string
    {
        return $this->position;
    }
}
