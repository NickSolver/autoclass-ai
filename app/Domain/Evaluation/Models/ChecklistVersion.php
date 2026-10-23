<?php

namespace App\Domain\Evaluation\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Версия чек-листа неизменяема после публикации (ТЗ, разд. 7): изменения — новая версия.
 *
 * @property int $id
 * @property int $checklist_id
 * @property int $version
 * @property string $status
 * @property string $scoring_rule
 * @property bool $zero_on_critical
 * @property ?Carbon $published_at
 * @property-read Checklist $checklist
 * @property-read Collection<int, ChecklistCriterion> $criteria
 */
class ChecklistVersion extends Model
{
    protected $fillable = ['checklist_id', 'version', 'status', 'scoring_rule', 'zero_on_critical', 'published_at', 'published_by'];

    protected function casts(): array
    {
        return ['zero_on_critical' => 'boolean', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<Checklist, $this> */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    /** @return HasMany<ChecklistCriterion, $this> */
    public function criteria(): HasMany
    {
        return $this->hasMany(ChecklistCriterion::class)->orderBy('position');
    }
}
