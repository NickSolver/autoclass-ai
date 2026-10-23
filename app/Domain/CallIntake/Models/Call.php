<?php

namespace App\Domain\CallIntake\Models;

use App\Domain\Audio\Models\AudioFile;
use App\Domain\CallIntake\AttributionStatus;
use App\Domain\CallIntake\CallDirection;
use App\Domain\CallIntake\CallStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Concerns\HasOrgScope;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\OrgPath;
use Database\Factories\CallFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Звонок. Путь в оргструктуре фиксируется при приёме; org_snapshot хранит названия
 * подразделений и сотрудника на тот момент — перевод сотрудника не меняет старые звонки.
 *
 * @property int $id
 * @property string $uuid
 * @property int $source_id
 * @property string $external_id
 * @property CallDirection $direction
 * @property ?Carbon $started_at
 * @property ?string $started_at_tz
 * @property ?int $duration_ms
 * @property int $group_id
 * @property int $company_id
 * @property ?int $dealership_id
 * @property ?int $department_id
 * @property ?int $employee_id
 * @property AttributionStatus $attribution_status
 * @property array<string, mixed> $org_snapshot
 * @property ?string $note
 * @property CallStatus $status
 * @property ?Carbon $status_changed_at
 * @property ?string $last_error
 * @property ?string $excluded_reason
 * @property ?Carbon $excluded_at
 * @property ?int $excluded_by
 * @property ?int $duplicate_of_call_id
 * @property ?int $uploaded_by
 * @property Carbon $occurred_at
 * @property ?Carbon $created_at
 * @property-read CallSource $source
 * @property-read ?Employee $employee
 * @property-read ?User $uploader
 * @property-read ?AudioFile $original
 * @property-read ?AudioFile $normalized
 * @property-read Collection<int, CallProcessingAttempt> $attempts
 */
#[UseFactory(CallFactory::class)]
class Call extends Model
{
    /** @use HasFactory<CallFactory> */
    use HasFactory, HasOrgScope;

    protected $fillable = [
        'uuid', 'source_id', 'external_id', 'direction', 'started_at', 'started_at_tz', 'duration_ms',
        'group_id', 'company_id', 'dealership_id', 'department_id', 'employee_id',
        'attribution_status', 'org_snapshot', 'note', 'status', 'status_changed_at', 'last_error',
        'excluded_reason', 'excluded_at', 'excluded_by', 'duplicate_of_call_id', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'direction' => CallDirection::class,
            'attribution_status' => AttributionStatus::class,
            'status' => CallStatus::class,
            'org_snapshot' => 'array',
            'started_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'excluded_at' => 'datetime',
            'occurred_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<CallSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CallSource::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<self, $this> */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_call_id');
    }

    /** @return HasMany<AudioFile, $this> */
    public function audioFiles(): HasMany
    {
        return $this->hasMany(AudioFile::class);
    }

    /** @return HasOne<AudioFile, $this> */
    public function original(): HasOne
    {
        return $this->hasOne(AudioFile::class)->where('kind', AudioFile::ORIGINAL);
    }

    /** @return HasOne<AudioFile, $this> */
    public function normalized(): HasOne
    {
        return $this->hasOne(AudioFile::class)->where('kind', AudioFile::NORMALIZED);
    }

    /** @return HasMany<CallProcessingAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(CallProcessingAttempt::class)->orderByDesc('id');
    }

    public function transitionTo(CallStatus $status, ?string $error = null): void
    {
        $this->forceFill(['status' => $status, 'status_changed_at' => now(), 'last_error' => $error])->save();
    }

    public function orgPath(): OrgPath
    {
        return new OrgPath($this->group_id, $this->company_id, $this->dealership_id, $this->department_id);
    }

    protected function orgScopeColumns(): array
    {
        return ['group' => 'group_id', 'company' => 'company_id', 'dealership' => 'dealership_id', 'department' => 'department_id'];
    }

    /** Область «собственные записи»: звонки, где пользователь — сотрудник звонка. */
    protected function applyOwnCondition(Builder $query, User $user): void
    {
        if ($user->employee_id !== null) {
            $query->orWhere($this->qualifyColumn('employee_id'), $user->employee_id);
        }
    }

    public function isOwnedBy(User $user): bool
    {
        return $user->employee_id !== null && $this->employee_id === $user->employee_id;
    }

    public function auditSubjectType(): string
    {
        return 'call';
    }

    public function auditLabel(): string
    {
        return "Звонок #{$this->id}";
    }
}
