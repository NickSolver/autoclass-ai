<?php

namespace App\Domain\Evaluation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $checklist_version_id
 * @property string $code
 * @property int $position
 * @property string $title
 * @property ?string $description
 * @property ?string $ai_hint
 * @property string $weight
 * @property bool $is_required
 * @property bool $is_critical
 * @property string $type
 * @property ?string $applicability
 */
class ChecklistCriterion extends Model
{
    public $timestamps = false;

    protected $table = 'checklist_criteria';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'is_critical' => 'boolean', 'position' => 'integer'];
    }
}
