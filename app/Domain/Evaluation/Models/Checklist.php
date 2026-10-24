<?php

namespace App\Domain\Evaluation\Models;

use App\Domain\Organization\Models\Company;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property bool $is_demo
 * @property ?int $company_id
 * @property bool $is_archived
 * @property-read ?Company $company
 * @property-read Collection<int, ChecklistVersion> $versions
 */
class Checklist extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_demo', 'company_id', 'is_archived', 'created_by'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean', 'is_archived' => 'boolean'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<ChecklistVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ChecklistVersion::class);
    }

    public function latestPublished(): ?ChecklistVersion
    {
        return $this->versions()->where('status', 'published')->orderByDesc('version')->first();
    }
}
