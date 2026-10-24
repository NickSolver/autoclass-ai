<?php

namespace App\Domain\Review\Models;

use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Concerns\HasOrgScope;
use App\Domain\Organization\OrgPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Апелляция на оценку (ТЗ, разд. 11). Путь оргструктуры — копия пути звонка.
 * В области own видны апелляции, поданные пользователем, и апелляции по его звонкам.
 *
 * @property int $id
 * @property int $call_id
 * @property int $evaluation_result_id
 * @property int $group_id
 * @property int $company_id
 * @property ?int $dealership_id
 * @property ?int $department_id
 * @property ?int $employee_id
 * @property int $created_by
 * @property string $reason
 * @property string $status
 * @property ?int $assignee_id
 * @property ?int $assigned_by
 * @property ?int $resolved_by
 * @property ?Carbon $resolved_at
 * @property ?string $resolution_comment
 * @property int $lock_version
 * @property Carbon $created_at
 * @property-read Call $call
 * @property-read EvaluationResult $result
 * @property-read User $author
 * @property-read ?User $assignee
 * @property-read ?User $resolver
 * @property-read Collection<int, ReviewDecision> $decisions
 */
class Appeal extends Model
{
    use HasOrgScope;

    public const OPEN = ['open', 'in_review'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<Call, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    /** @return BelongsTo<EvaluationResult, $this> */
    public function result(): BelongsTo
    {
        return $this->belongsTo(EvaluationResult::class, 'evaluation_result_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return HasMany<ReviewDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(ReviewDecision::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
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
        $query->orWhere($this->qualifyColumn('created_by'), $user->id);
        if ($user->employee_id !== null) {
            $query->orWhere($this->qualifyColumn('employee_id'), $user->employee_id);
        }
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id || ($user->employee_id !== null && $this->employee_id === $user->employee_id);
    }
}
