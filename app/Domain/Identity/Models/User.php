<?php

namespace App\Domain\Identity\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Access\AccessResolver;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Concerns\HasOrgScope;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\CompanyGroup;
use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\OrgPath;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Учётная запись портала. Домашнее подразделение (group_id … department_id) определяет,
 * кто управляет учётной записью; права определяются назначениями ролей (user_role_scopes).
 *
 * @property int $id
 * @property string $login
 * @property string $name
 * @property ?string $email
 * @property string $password
 * @property bool $is_active
 * @property bool $must_change_password
 * @property ?int $employee_id
 * @property int $group_id
 * @property ?int $company_id
 * @property ?int $dealership_id
 * @property ?int $department_id
 * @property ?string $mfa_secret
 * @property ?Carbon $mfa_enabled_at
 * @property ?int $mfa_last_timestep
 * @property ?Carbon $mfa_grace_started_at
 * @property ?Carbon $password_changed_at
 * @property ?Carbon $last_login_at
 * @property ?string $last_login_ip
 * @property ?Carbon $disabled_at
 * @property ?Carbon $created_at
 * @property-read ?Employee $employee
 * @property-read CompanyGroup $group
 * @property-read ?Company $company
 * @property-read ?Dealership $dealership
 * @property-read ?Department $department
 * @property-read Collection<int, UserRoleScope> $roleScopes
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, HasOrgScope, Notifiable;

    protected $fillable = [
        'login', 'name', 'email', 'password', 'is_active', 'must_change_password', 'employee_id',
        'group_id', 'company_id', 'dealership_id', 'department_id',
        'password_changed_at', 'last_login_at', 'last_login_ip', 'disabled_at',
    ];

    protected $hidden = ['password', 'remember_token', 'mfa_secret'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_enabled_at' => 'datetime',
            'mfa_last_timestep' => 'integer',
            'mfa_grace_started_at' => 'datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /** Логин хранится в нижнем регистре: вход не зависит от регистра. */
    protected function setLoginAttribute(string $value): void
    {
        $this->attributes['login'] = mb_strtolower(trim($value));
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<CompanyGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CompanyGroup::class, 'group_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Dealership, $this> */
    public function dealership(): BelongsTo
    {
        return $this->belongsTo(Dealership::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<UserRoleScope, $this> */
    public function roleScopes(): HasMany
    {
        return $this->hasMany(UserRoleScope::class);
    }

    public function hasPermission(Permission $permission): bool
    {
        return app(AccessResolver::class)->has($this, $permission);
    }

    public function orgPath(): OrgPath
    {
        return new OrgPath($this->group_id, $this->company_id, $this->dealership_id, $this->department_id);
    }

    protected function orgScopeColumns(): array
    {
        return ['group' => 'group_id', 'company' => 'company_id', 'dealership' => 'dealership_id', 'department' => 'department_id'];
    }

    protected function applyOwnCondition(Builder $query, User $user): void
    {
        $query->orWhere($this->qualifyColumn('id'), $user->id);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->id === $user->id;
    }

    protected function auditIgnored(): array
    {
        return ['created_at', 'updated_at', 'remember_token', 'last_login_at', 'last_login_ip', 'password_changed_at', 'mfa_last_timestep', 'mfa_grace_started_at'];
    }

    protected function auditSensitive(): array
    {
        return ['password', 'mfa_secret'];
    }
}
