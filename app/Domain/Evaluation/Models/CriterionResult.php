<?php

namespace App\Domain\Evaluation\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $evaluation_result_id
 * @property int $checklist_criterion_id
 * @property string $status
 * @property ?string $reason
 * @property ?string $confidence
 * @property bool $evidence_valid
 * @property list<string> $issues
 * @property-read ChecklistCriterion $criterion
 * @property-read Collection<int, Evidence> $evidence
 */
class CriterionResult extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence_valid' => 'boolean', 'issues' => 'array'];
    }

    /** @return BelongsTo<ChecklistCriterion, $this> */
    public function criterion(): BelongsTo
    {
        return $this->belongsTo(ChecklistCriterion::class, 'checklist_criterion_id');
    }

    /** @return HasMany<Evidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(Evidence::class);
    }
}
