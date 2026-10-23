<?php

namespace App\Domain\Identity\Access;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Identity\ScopeType;
use Illuminate\Support\Facades\DB;

/**
 * Вычисляет области действия прав пользователя по его назначениям ролей.
 * Результат кешируется в пределах процесса; кеш сбрасывается при изменении назначений и ролей.
 */
final class AccessResolver
{
    /** @var array<int, array<string, AccessScope>> */
    private array $cache = [];

    public function scopeFor(User $user, Permission $permission): AccessScope
    {
        return $this->scopesOf($user)[$permission->value] ?? AccessScope::none();
    }

    public function has(User $user, Permission $permission): bool
    {
        return ! $this->scopeFor($user, $permission)->isEmpty();
    }

    /**
     * Все права пользователя с областями. Неактивный пользователь прав не имеет.
     *
     * @return array<string, AccessScope>
     */
    public function scopesOf(User $user): array
    {
        if (! $user->is_active || ! $user->exists) {
            return [];
        }

        return $this->cache[$user->id] ??= $this->load($user->id);
    }

    public function flush(?int $userId = null): void
    {
        if ($userId === null) {
            $this->cache = [];
        } else {
            unset($this->cache[$userId]);
        }
    }

    /** @return array<string, AccessScope> */
    private function load(int $userId): array
    {
        $rows = DB::table('user_role_scopes as urs')
            ->join('role_permission as rp', 'rp.role_id', '=', 'urs.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('urs.user_id', $userId)
            ->get(['p.code', 'urs.scope_type', 'urs.scope_id']);

        /** @var array<string, array{group: list<int>, company: list<int>, dealership: list<int>, department: list<int>, own: bool}> $acc */
        $acc = [];
        foreach ($rows as $row) {
            if (Permission::tryFrom($row->code) === null) {
                continue; // Право удалено из реестра — игнорируем (запрет по умолчанию).
            }
            $acc[$row->code] ??= ['group' => [], 'company' => [], 'dealership' => [], 'department' => [], 'own' => false];
            $type = ScopeType::tryFrom($row->scope_type);
            if ($type === ScopeType::Own) {
                $acc[$row->code]['own'] = true;
            } elseif ($type !== null) {
                $acc[$row->code][$type->value][] = (int) $row->scope_id;
            }
        }

        return array_map(fn (array $a) => new AccessScope(
            groupIds: array_values(array_unique($a['group'])),
            companyIds: array_values(array_unique($a['company'])),
            dealershipIds: array_values(array_unique($a['dealership'])),
            departmentIds: array_values(array_unique($a['department'])),
            own: $a['own'],
        ), $acc);
    }
}
