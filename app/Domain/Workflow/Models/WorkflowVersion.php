<?php

namespace App\Domain\Workflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Версия процесса. Опубликованная неизменяема: изменение — новый черновик.
 *
 * @property int $id
 * @property int $workflow_definition_id
 * @property int $version
 * @property string $status
 * @property array<string, mixed> $graph
 * @property int $schema_version
 * @property ?int $created_by
 * @property ?int $published_by
 * @property ?Carbon $published_at
 * @property int $lock_version
 * @property ?Carbon $updated_at
 * @property-read WorkflowDefinition $definition
 */
class WorkflowVersion extends Model
{
    protected $fillable = ['workflow_definition_id', 'version', 'status', 'graph', 'schema_version', 'created_by', 'published_by', 'published_at'];

    protected function casts(): array
    {
        return ['graph' => 'array', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $v) {
            if ($v->getOriginal('status') === 'published' && $v->isDirty('graph')) {
                throw new \LogicException('Опубликованная версия процесса неизменяема.');
            }
        });
    }

    /** @return BelongsTo<WorkflowDefinition, $this> */
    public function definition(): BelongsTo
    {
        return $this->belongsTo(WorkflowDefinition::class, 'workflow_definition_id');
    }
}
