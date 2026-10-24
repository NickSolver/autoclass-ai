<?php

namespace App\Domain\Workflow\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workflow_run_id
 * @property string $node_id
 * @property string $node_type
 * @property ?string $branch
 * @property ?array<string, mixed> $output
 * @property string $status
 * @property ?string $error_message
 */
class WorkflowNodeRun extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['output' => 'array', 'created_at' => 'datetime'];
    }
}
