<?php

namespace App\Domain\Organization\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Concerns\HasOrgScope;
use App\Domain\Organization\OrgPath;
use Database\Factories\DealershipFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $group_id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property ?string $city
 * @property ?string $address
 * @property bool $is_active
 * @property-read Company $company
 */
#[UseFactory(DealershipFactory::class)]
class Dealership extends Model
{
    /** @use HasFactory<DealershipFactory> */
    use Auditable, HasFactory, HasOrgScope;

    protected $fillable = ['group_id', 'company_id', 'code', 'name', 'city', 'address', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<Department, $this> */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function orgPath(): OrgPath
    {
        return new OrgPath($this->group_id, $this->company_id, $this->id);
    }

    protected function orgScopeColumns(): array
    {
        return ['group' => 'group_id', 'company' => 'company_id', 'dealership' => 'id'];
    }
}
