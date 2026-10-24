<?php

namespace App\Domain\Workflow\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $call_id
 * @property ?int $workflow_version_id
 * @property string $trigger
 * @property string $status
 * @property array<string, mixed> $context
 * @property array<string, mixed> $plan
 * @property ?string $current_node_id
 * @property ?int $transcript_version_id
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?string $error_message
 * @property-read ?WorkflowVersion $version
 * @property-read Collection<int, WorkflowNodeRun> $nodeRuns
 */
class WorkflowRun extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['context' => 'array', 'plan' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** @return BelongsTo<WorkflowVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    /** @return HasMany<WorkflowNodeRun, $this> */
    public function nodeRuns(): HasMany
    {
        return $this->hasMany(WorkflowNodeRun::class)->orderBy('id');
    }
}
