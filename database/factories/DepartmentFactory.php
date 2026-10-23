<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            'dealership_id' => Dealership::factory(),
            'company_id' => fn (array $a) => Dealership::query()->findOrFail($a['dealership_id'])->company_id,
            'group_id' => fn (array $a) => Dealership::query()->findOrFail($a['dealership_id'])->group_id,
            'code' => 'dep-'.fake()->unique()->bothify('????##'),
            'name' => fake()->randomElement(['Отдел продаж', 'Сервис', 'Запчасти', 'Колл-центр']),
            'kind' => 'sales',
            'is_active' => true,
        ];
    }
}
