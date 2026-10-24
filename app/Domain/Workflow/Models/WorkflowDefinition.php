<?php

namespace App\Domain\Workflow\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Models\Company;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property ?int $company_id
 * @property string $trigger
 * @property int $priority
 * @property bool $is_archived
 * @property ?int $created_by
 * @property-read ?Company $company
 * @property-read Collection<int, WorkflowVersion> $versions
 */
class WorkflowDefinition extends Model
{
    use Auditable;

    protected $fillable = ['name', 'description', 'company_id', 'trigger', 'priority', 'is_archived', 'created_by'];

    protected function casts(): array
    {
        return ['is_archived' => 'boolean', 'priority' => 'integer'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<WorkflowVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class)->orderByDesc('version');
    }

    public function latestPublished(): ?WorkflowVersion
    {
        return $this->versions()->where('status', 'published')->first();
    }

    public function auditSubjectType(): string
    {
        return 'workflow';
    }
}
