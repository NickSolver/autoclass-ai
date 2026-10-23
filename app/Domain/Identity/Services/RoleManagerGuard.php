<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Access\AccessResolver;
use App\Domain\Identity\Permission;
use App\Domain\Identity\ScopeType;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Не даёт системе остаться без активного пользователя с roles.manage на уровне группы.
 * Изменение выполняется в транзакции и откатывается, если инвариант нарушен.
 */
final class RoleManagerGuard
{
    public function __construct(private readonly AccessResolver $resolver) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     */
    public function protect(Closure $change): mixed
    {
        return DB::transaction(function () use ($change) {
            // Инвариант проверяется, только если он выполнялся до изменения
            // (до первичной настройки администратора ещё нет — блокировать нечего).
            $before = $this->hasActiveRoleManager();
            $result = $change();
            $this->resolver->flush();

            if ($before && ! $this->hasActiveRoleManager()) {
                throw ValidationException::withMessages([
                    'role' => 'Операция оставит систему без активного администратора с правом управления ролями на уровне группы.',
                ]);
            }

            return $result;
        });
    }

    public function hasActiveRoleManager(): bool
    {
        return DB::table('users as u')
            ->join('user_role_scopes as urs', 'urs.user_id', '=', 'u.id')
            ->join('role_permission as rp', 'rp.role_id', '=', 'urs.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('u.is_active', true)
            ->where('urs.scope_type', ScopeType::Group->value)
            ->where('p.code', Permission::RolesManage->value)
            ->lockForUpdate()
            ->exists();
    }
}
