<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Services\OrgPathResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class UserService
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly OrgPathResolver $paths,
        private readonly SessionService $sessions,
        private readonly RoleManagerGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{login: string, name: string, email?: ?string, password: string, employee_id?: ?int,
     *     group_id?: ?int, company_id?: ?int, dealership_id?: ?int, department_id?: ?int}  $data
     */
    public function create(User $actor, array $data): User
    {
        $path = $this->paths->fromUnits(
            $data['group_id'] ?? null, $data['company_id'] ?? null, $data['dealership_id'] ?? null, $data['department_id'] ?? null,
        );
        if (! $this->authorizer->covers($actor, Permission::UsersCreate, $path)) {
            throw new AuthorizationException('Нет права создавать пользователей в этом подразделении.');
        }
        $employeeId = $this->resolveEmployeeLink($actor, $data['employee_id'] ?? null, null);

        return DB::transaction(fn () => User::create([
            'login' => $data['login'],
            'name' => $data['name'],
            'email' => $data['email'] ?? null,
            'password' => $data['password'],
            // Пароль, заданный администратором, временный: пользователь сменит его при первом входе.
            'must_change_password' => true,
            'employee_id' => $employeeId,
            'is_active' => true,
            ...$path->toColumns(),
        ]));
    }

    /**
     * @param  array{login?: string, name?: string, email?: ?string, employee_id?: ?int,
     *     group_id?: ?int, company_id?: ?int, dealership_id?: ?int, department_id?: ?int}  $data
     */
    public function update(User $actor, User $target, array $data): User
    {
        $attributes = array_intersect_key($data, array_flip(['login', 'name', 'email']));

        $unitKeys = ['group_id', 'company_id', 'dealership_id', 'department_id'];
        if (array_intersect_key($data, array_flip($unitKeys)) !== []) {
            $path = $this->paths->fromUnits(
                $data['group_id'] ?? null, $data['company_id'] ?? null, $data['dealership_id'] ?? null, $data['department_id'] ?? null,
            );
            if (! $this->authorizer->covers($actor, Permission::UsersUpdate, $path)) {
                throw new AuthorizationException('Нет права переносить пользователя в это подразделение.');
            }
            $attributes += $path->toColumns();
        }

        if (array_key_exists('employee_id', $data)) {
            $attributes['employee_id'] = $this->resolveEmployeeLink($actor, $data['employee_id'], $target);
            if ($attributes['employee_id'] === null && $target->roleScopes()->where('scope_type', 'own')->exists()) {
                throw ValidationException::withMessages([
                    'employee_id' => 'У пользователя есть роль с областью «Собственные записи»; сначала отзовите её.',
                ]);
            }
        }

        $target->fill($attributes)->save();

        return $target;
    }

    public function setActive(User $actor, User $target, bool $active, ?string $reason): User
    {
        if ($target->is_active === $active) {
            return $target;
        }

        $this->guard->protect(function () use ($target, $active) {
            $target->forceFill(['is_active' => $active, 'disabled_at' => $active ? null : now()])->saveQuietly();
        });

        if (! $active) {
            $this->sessions->terminateAll($target, audit: false);
        }
        $this->audit->record($active ? 'user.enabled' : 'user.disabled', $target, reason: $reason);

        return $target;
    }

    /** Временный пароль от администратора: все сессии пользователя завершаются, при входе потребуется смена. */
    public function setTemporaryPassword(User $actor, User $target, string $password): void
    {
        $target->forceFill([
            'password' => $password,
            'must_change_password' => true,
            'password_changed_at' => now(),
        ])->saveQuietly();
        $this->sessions->terminateAll($target, audit: false);
        $this->audit->record('user.password_set_by_admin', $target);
    }

    public function changeOwnPassword(User $user, string $current, string $new, ?string $currentSessionId): void
    {
        if (! Hash::check($current, $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Текущий пароль указан неверно.']);
        }
        if (Hash::check($new, $user->password)) {
            throw ValidationException::withMessages(['password' => 'Новый пароль должен отличаться от текущего.']);
        }

        $user->forceFill([
            'password' => $new,
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->saveQuietly();
        $this->sessions->terminateAll($user, $currentSessionId, audit: false);
        $this->audit->record('auth.password_changed', $user);
    }

    private function resolveEmployeeLink(User $actor, ?int $employeeId, ?User $target): ?int
    {
        if ($employeeId === null) {
            return null;
        }

        $employee = Employee::query()->visibleTo($actor, Permission::EmployeesView)->find($employeeId);
        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'Сотрудник не найден.']);
        }

        $linked = User::query()->where('employee_id', $employeeId)
            ->when($target !== null, fn ($q) => $q->whereKeyNot($target->id))
            ->exists();
        if ($linked) {
            throw ValidationException::withMessages(['employee_id' => 'Сотрудник уже связан с другой учётной записью.']);
        }

        return $employee->id;
    }
}
