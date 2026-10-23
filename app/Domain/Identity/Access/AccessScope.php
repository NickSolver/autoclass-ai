<?php

namespace App\Domain\Identity\Access;

use App\Domain\Organization\OrgPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Область, в которой пользователь обладает конкретным правом. Объединение всех назначений ролей.
 * Область наследуется вниз: компания покрывает свои центры и отделы.
 */
final readonly class AccessScope
{
    /**
     * @param  list<int>  $groupIds
     * @param  list<int>  $companyIds
     * @param  list<int>  $dealershipIds
     * @param  list<int>  $departmentIds
     */
    public function __construct(
        public array $groupIds = [],
        public array $companyIds = [],
        public array $dealershipIds = [],
        public array $departmentIds = [],
        public bool $own = false,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return ! $this->hasOrgUnits() && ! $this->own;
    }

    public function hasOrgUnits(): bool
    {
        return $this->groupIds !== [] || $this->companyIds !== []
            || $this->dealershipIds !== [] || $this->departmentIds !== [];
    }

    public function covers(?OrgPath $path): bool
    {
        if ($path === null) {
            return false;
        }

        return in_array($path->groupId, $this->groupIds, true)
            || ($path->companyId !== null && in_array($path->companyId, $this->companyIds, true))
            || ($path->dealershipId !== null && in_array($path->dealershipId, $this->dealershipIds, true))
            || ($path->departmentId !== null && in_array($path->departmentId, $this->departmentIds, true));
    }

    /**
     * Добавляет к запросу OR-условия по денормализованному пути. Вызывается внутри where(closure).
     *
     * @param  Builder<*>|QueryBuilder  $query
     * @param  array<'group'|'company'|'dealership'|'department', string>  $columns
     */
    public function applyOrConditions(Builder|QueryBuilder $query, array $columns): void
    {
        $map = [
            'group' => $this->groupIds,
            'company' => $this->companyIds,
            'dealership' => $this->dealershipIds,
            'department' => $this->departmentIds,
        ];

        foreach ($map as $level => $ids) {
            if ($ids !== [] && isset($columns[$level])) {
                $query->orWhereIn($columns[$level], $ids);
            }
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'group_ids' => $this->groupIds,
            'company_ids' => $this->companyIds,
            'dealership_ids' => $this->dealershipIds,
            'department_ids' => $this->departmentIds,
            'own' => $this->own,
        ];
    }
}
