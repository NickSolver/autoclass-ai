<?php

namespace App\Domain\Organization\Concerns;

use App\Domain\Identity\Access\AccessResolver;
use App\Domain\Identity\Access\AccessScope;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Organization\OrgPath;
use Illuminate\Database\Eloquent\Builder;

/**
 * Фильтрация и проверка объектов по области действия права (ADR-0001).
 * Запрет по умолчанию: пустая область даёт пустую выборку.
 *
 * @method static Builder<static> visibleTo(User $user, Permission $permission)
 */
trait HasOrgScope
{
    abstract public function orgPath(): ?OrgPath;

    /**
     * Колонки денормализованного пути для каждого уровня.
     *
     * @return array<'group'|'company'|'dealership'|'department', string>
     */
    abstract protected function orgScopeColumns(): array;

    /** @param Builder<static> $query */
    public function scopeVisibleTo(Builder $query, User $user, Permission $permission): void
    {
        $scope = app(AccessResolver::class)->scopeFor($user, $permission);

        if ($scope->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $columns = array_map(fn (string $c) => $this->qualifyColumn($c), $this->orgScopeColumns());

        $query->where(function (Builder $w) use ($scope, $columns, $user) {
            $w->whereRaw('1 = 0');
            $scope->applyOrConditions($w, $columns);
            $this->extendVisibilityQuery($w, $scope);
            if ($scope->own) {
                $this->applyOwnCondition($w, $user);
            }
        });
    }

    public function isVisibleTo(User $user, Permission $permission): bool
    {
        $scope = app(AccessResolver::class)->scopeFor($user, $permission);

        return $scope->covers($this->orgPath())
            || $this->isVisibleThroughExtension($scope)
            || ($scope->own && $this->isOwnedBy($user));
    }

    /** Дополнительные OR-условия видимости (например, через назначения сотрудника). */
    protected function extendVisibilityQuery(Builder $query, AccessScope $scope): void {}

    protected function isVisibleThroughExtension(AccessScope $scope): bool
    {
        return false;
    }

    /** OR-условие для области own. По умолчанию объект никому не «принадлежит». */
    protected function applyOwnCondition(Builder $query, User $user): void {}

    public function isOwnedBy(User $user): bool
    {
        return false;
    }
}
