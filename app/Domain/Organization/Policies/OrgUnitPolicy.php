<?php

namespace App\Domain\Organization\Policies;

use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\CompanyGroup;
use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;

/**
 * Общая политика для подразделений: группа, компания, дилерский центр, отдел.
 * Создание дочернего подразделения проверяется по пути родителя (Authorizer::covers).
 */
final class OrgUnitPolicy
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::OrganizationView);
    }

    public function view(User $user, CompanyGroup|Company|Dealership|Department $unit): bool
    {
        return $unit->isVisibleTo($user, Permission::OrganizationView);
    }

    public function update(User $user, CompanyGroup|Company|Dealership|Department $unit): bool
    {
        return $this->authorizer->covers($user, Permission::OrganizationManage, $unit->orgPath());
    }
}
