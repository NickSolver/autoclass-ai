<?php

namespace App\Domain\Evaluation\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Результат по одному чек-листу. Основной и дополнительные хранятся раздельно (ТЗ, разд. 7.1).
 *
 * @property int $id
 * @property int $analysis_run_id
 * @property int $call_id
 * @property int $checklist_version_id
 * @property string $role
 * @property ?int $score
 * @property ?int $provisional_score
 * @property bool $critical_violation
 * @property string $business_status
 * @property string $review_state
 * @property string $approval_state
 * @property ?string $summary
 * @property list<string> $recommendations
 * @property list<string> $risks
 * @property list<string> $issues
 * @property-read ChecklistVersion $checklistVersion
 * @property-read Collection<int, CriterionResult> $criteria
 */
class EvaluationResult extends Model
{
    public const UPDATED_AT = null;

    public const FULL = 'full_assessment';

    public const INTERVENTION = 'human_intervention_required';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'critical_violation' => 'boolean',
            'recommendations' => 'array',
            'risks' => 'array',
            'issues' => 'array',
            'score' => 'integer',
            'provisional_score' => 'integer',
        ];
    }

    /** @return BelongsTo<ChecklistVersion, $this> */
    public function checklistVersion(): BelongsTo
    {
        return $this->belongsTo(ChecklistVersion::class);
    }

    /** @return HasMany<CriterionResult, $this> */
    public function criteria(): HasMany
    {
        return $this->hasMany(CriterionResult::class);
    }
}
