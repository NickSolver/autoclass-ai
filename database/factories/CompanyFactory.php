<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\CompanyGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Company> */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'group_id' => CompanyGroup::factory(),
            'code' => 'co-'.fake()->unique()->bothify('????##'),
            'name' => fake()->unique()->company(),
            'legal_name' => null,
            'is_active' => true,
        ];
    }
}
