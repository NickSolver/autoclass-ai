<?php

namespace App\Domain\Organization\Policies;

use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Employee;

final class EmployeePolicy
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::EmployeesView);
    }

    public function view(User $user, Employee $employee): bool
    {
        return $employee->isVisibleTo($user, Permission::EmployeesView);
    }

    /** Карточкой сотрудника управляет область его основной компании. */
    public function update(User $user, Employee $employee): bool
    {
        return $this->authorizer->covers($user, Permission::EmployeesManage, $employee->orgPath());
    }
}
