<?php

namespace App\Domain\Organization\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Access\AccessScope;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Concerns\HasOrgScope;
use App\Domain\Organization\OrgPath;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Сотрудник. Не учётная запись: связь с User опциональна (users.employee_id).
 * Видимость: по основной компании либо по любому назначению в области действия права.
 *
 * @property int $id
 * @property int $group_id
 * @property int $company_id
 * @property string $last_name
 * @property string $first_name
 * @property ?string $middle_name
 * @property ?string $personnel_number
 * @property ?string $email
 * @property ?string $work_phone
 * @property ?string $phone_extension
 * @property bool $is_active
 * @property-read Company $company
 * @property-read Collection<int, EmployeeAssignment> $assignments
 * @property-read ?User $user
 */
#[UseFactory(EmployeeFactory::class)]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use Auditable, HasFactory, HasOrgScope;

    protected $fillable = [
        'group_id', 'company_id', 'last_name', 'first_name', 'middle_name',
        'personnel_number', 'email', 'work_phone', 'phone_extension', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<EmployeeAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeAssignment::class);
    }

    /** @return HasOne<User, $this> */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function fullName(): string
    {
        return trim("{$this->last_name} {$this->first_name} {$this->middle_name}");
    }

    public function auditLabel(): string
    {
        return $this->fullName();
    }

    public function orgPath(): OrgPath
    {
        return new OrgPath($this->group_id, $this->company_id);
    }

    protected function orgScopeColumns(): array
    {
        return ['group' => 'group_id', 'company' => 'company_id'];
    }

    protected function extendVisibilityQuery(Builder $query, AccessScope $scope): void
    {
        $query->orWhereHas('assignments', function (Builder $a) use ($scope) {
            $a->where(function (Builder $w) use ($scope) {
                $w->whereRaw('1 = 0');
                $scope->applyOrConditions($w, [
                    'group' => 'employee_assignments.group_id',
                    'company' => 'employee_assignments.company_id',
                    'dealership' => 'employee_assignments.dealership_id',
                    'department' => 'employee_assignments.department_id',
                ]);
            });
        });
    }

    protected function isVisibleThroughExtension(AccessScope $scope): bool
    {
        return $this->assignments->contains(fn (EmployeeAssignment $a) => $scope->covers($a->orgPath()));
    }

    protected function applyOwnCondition(Builder $query, User $user): void
    {
        if ($user->employee_id !== null) {
            $query->orWhere($this->qualifyColumn('id'), $user->employee_id);
        }
    }

    public function isOwnedBy(User $user): bool
    {
        return $user->employee_id !== null && $user->employee_id === $this->id;
    }
}
