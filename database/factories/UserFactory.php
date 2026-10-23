<?php

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\CompanyGroup;
use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\OrgPath;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public const PASSWORD = 'Test-Password-123';

    public function definition(): array
    {
        return [
            'login' => fake()->unique()->userName(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => self::PASSWORD,
            'is_active' => true,
            'must_change_password' => false,
            'group_id' => CompanyGroup::factory(),
        ];
    }

    /** Домашнее подразделение пользователя. */
    public function homeAt(CompanyGroup|Company|Dealership|Department $unit): static
    {
        /** @var OrgPath $path */
        $path = $unit->orgPath();

        return $this->state(fn () => $path->toColumns());
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false, 'disabled_at' => now()]);
    }
}
