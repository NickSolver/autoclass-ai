<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\RoleTemplates;
use App\Domain\Identity\Services\RoleService;
use Illuminate\Database\Seeder;

/** Реестр прав и начальные шаблоны ролей. Существующие роли не перезаписываются. */
class RoleSeeder extends Seeder
{
    public function run(RoleService $roles): void
    {
        $roles->syncPermissionRegistry();

        foreach (RoleTemplates::all() as $code => $template) {
            if (! Role::query()->where('code', $code)->exists()) {
                $roles->create(['code' => $code, ...$template], isSystem: true);
            }
        }
    }
}
