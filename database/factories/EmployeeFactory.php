<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'group_id' => fn (array $a) => Company::query()->findOrFail($a['company_id'])->group_id,
            'last_name' => fake()->lastName(),
            'first_name' => fake()->firstName(),
            'middle_name' => null,
            'personnel_number' => fake()->unique()->numerify('T-#####'),
            'email' => null,
            'work_phone' => null,
            'phone_extension' => fake()->numerify('###'),
            'is_active' => true,
        ];
    }
}
