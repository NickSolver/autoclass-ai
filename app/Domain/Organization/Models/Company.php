<?php

namespace App\Domain\Organization\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Concerns\HasOrgScope;
use App\Domain\Organization\OrgPath;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $group_id
 * @property string $code
 * @property string $name
 * @property ?string $legal_name
 * @property bool $is_active
 * @property-read CompanyGroup $group
 */
#[UseFactory(CompanyFactory::class)]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use Auditable, HasFactory, HasOrgScope;

    protected $fillable = ['group_id', 'code', 'name', 'legal_name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<CompanyGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(CompanyGroup::class, 'group_id');
    }

    /** @return HasMany<Dealership, $this> */
    public function dealerships(): HasMany
    {
        return $this->hasMany(Dealership::class);
    }

    /** @return HasMany<Department, $this> */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function orgPath(): OrgPath
    {
        return new OrgPath($this->group_id, $this->id);
    }

    protected function orgScopeColumns(): array
    {
        return ['group' => 'group_id', 'company' => 'id'];
    }
}
