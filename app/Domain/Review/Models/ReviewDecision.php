<?php

namespace App\Domain\Review\Models;

use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Решение человека по результату: подтверждение, исправление (новая ревизия) или отклонение апелляции.
 * Неизменяемо.
 *
 * @property int $id
 * @property ?int $manual_review_id
 * @property ?int $appeal_id
 * @property int $evaluation_result_id
 * @property ?int $new_evaluation_result_id
 * @property string $decision
 * @property string $reason
 * @property list<array<string, mixed>> $changes
 * @property int $decided_by
 * @property Carbon $created_at
 * @property-read EvaluationResult $result
 * @property-read User $decider
 */
class ReviewDecision extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    /** @return BelongsTo<EvaluationResult, $this> */
    public function result(): BelongsTo
    {
        return $this->belongsTo(EvaluationResult::class, 'evaluation_result_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
