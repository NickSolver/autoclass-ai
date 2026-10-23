<?php

namespace Database\Factories;

use App\Domain\Organization\Models\CompanyGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanyGroup> */
class CompanyGroupFactory extends Factory
{
    protected $model = CompanyGroup::class;

    public function definition(): array
    {
        return [
            'code' => 'grp-'.fake()->unique()->bothify('????##'),
            'name' => 'Группа '.fake()->unique()->company(),
            'is_active' => true,
        ];
    }
}
