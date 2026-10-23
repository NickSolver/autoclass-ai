<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Models\EmployeeAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeAssignment> */
class EmployeeAssignmentFactory extends Factory
{
    protected $model = EmployeeAssignment::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'group_id' => 0,
            'company_id' => 0,
            'position' => 'Менеджер',
            'is_primary' => true,
            'starts_on' => now()->subYear()->toDateString(),
            'ends_on' => null,
        ];
    }

    public function inDepartment(Department $department): static
    {
        return $this->state(fn () => [
            'group_id' => $department->group_id,
            'company_id' => $department->company_id,
            'dealership_id' => $department->dealership_id,
            'department_id' => $department->id,
        ]);
    }
}
