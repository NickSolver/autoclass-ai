<?php

namespace App\Domain\Evaluation\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property bool $is_demo
 * @property-read Collection<int, ChecklistVersion> $versions
 */
class Checklist extends Model
{
    protected $fillable = ['code', 'name', 'description', 'is_demo'];

    protected function casts(): array
    {
        return ['is_demo' => 'boolean'];
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
