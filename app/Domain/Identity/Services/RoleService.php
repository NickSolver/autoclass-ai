<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Access\AccessResolver;
use App\Domain\Identity\Models\PermissionDefinition;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RoleService
{
    public function __construct(
        private readonly RoleManagerGuard $guard,
        private readonly AccessResolver $resolver,
        private readonly AuditLogger $audit,
    ) {}

    /** Синхронизирует таблицу permissions с enum Permission. Возвращает число новых прав. */
    public function syncPermissionRegistry(): int
    {
        $created = 0;
        foreach (Permission::cases() as $permission) {
            $row = PermissionDefinition::query()->updateOrCreate(
                ['code' => $permission->value],
                ['category' => $permission->category(), 'name' => $permission->label()],
            );
            $created += $row->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /**
     * @param  array{code: string, name: string, description?: ?string, allowed_scope_types: list<string>, permissions: list<string>}  $data
     */
    public function create(array $data, bool $isSystem = false): Role
    {
        return DB::transaction(function () use ($data, $isSystem) {
            $role = Role::create([
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'allowed_scope_types' => $data['allowed_scope_types'],
                'is_system' => $isSystem,
            ]);
            $this->syncPermissions($role, $data['permissions']);

            return $role;
        });
    }

    /**
     * Оптимистическая блокировка: изменение отклоняется, если роль успели изменить.
     *
     * @param  array{name?: string, description?: ?string, allowed_scope_types?: list<string>, permissions?: list<string>, lock_version: int}  $data
     */
    public function update(Role $role, array $data): Role
    {
        return $this->guard->protect(function () use ($role, $data) {
            $updated = Role::query()
                ->whereKey($role->id)
                ->where('lock_version', $data['lock_version'])
                ->increment('lock_version');
            if ($updated === 0) {
                throw ValidationException::withMessages([
                    'lock_version' => 'Роль была изменена другим пользователем. Обновите страницу и повторите.',
                ]);
            }

            $role->refresh();
            $role->fill(array_intersect_key($data, array_flip(['name', 'description', 'allowed_scope_types'])));
            $role->save();

            if (array_key_exists('permissions', $data)) {
                $this->syncPermissions($role, $data['permissions']);
            }

            return $role->refresh();
        });
    }

    public function delete(Role $role): void
    {
        if ($role->assignments()->exists()) {
            throw ValidationException::withMessages(['role' => 'Роль назначена пользователям. Сначала отзовите назначения.']);
        }
        $role->delete();
    }

    /** @param list<string> $codes */
    private function syncPermissions(Role $role, array $codes): void
    {
        $before = $role->permissions()->pluck('code')->sort()->values()->all();
        $ids = PermissionDefinition::query()->whereIn('code', $codes)->pluck('id')->all();
        $role->permissions()->sync($ids);
        $after = $role->permissions()->pluck('code')->sort()->values()->all();
        $role->unsetRelation('permissions');
        $this->resolver->flush();

        if ($before !== $after) {
            $this->audit->record('role.permissions_changed', $role, [
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
            ]);
        }
    }
}
