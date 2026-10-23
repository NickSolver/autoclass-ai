<?php

namespace Database\Factories;

use App\Domain\Identity\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Role> */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'code' => 'role_'.fake()->unique()->lexify('??????'),
            'name' => 'Роль '.fake()->unique()->word(),
            'description' => null,
            'is_system' => false,
            'allowed_scope_types' => ['group', 'company', 'dealership', 'department', 'own'],
        ];
    }
}
