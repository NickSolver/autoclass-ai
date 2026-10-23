<?php

namespace Database\Factories;

use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\Dealership;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Dealership> */
class DealershipFactory extends Factory
{
    protected $model = Dealership::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'group_id' => fn (array $a) => Company::query()->findOrFail($a['company_id'])->group_id,
            'code' => 'dc-'.fake()->unique()->bothify('????##'),
            'name' => 'ДЦ '.fake()->unique()->city(),
            'city' => fake()->city(),
            'address' => null,
            'is_active' => true,
        ];
    }
}
