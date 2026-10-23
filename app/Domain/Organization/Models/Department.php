<?php

namespace App\Domain\Organization\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Concerns\HasOrgScope;
use App\Domain\Organization\OrgPath;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $group_id
 * @property int $company_id
 * @property ?int $dealership_id
 * @property string $code
 * @property string $name
 * @property ?string $kind
 * @property bool $is_active
 * @property-read Company $company
 * @property-read ?Dealership $dealership
 */
#[UseFactory(DepartmentFactory::class)]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use Auditable, HasFactory, HasOrgScope;

    protected $fillable = ['group_id', 'company_id', 'dealership_id', 'code', 'name', 'kind', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Dealership, $this> */
    public function dealership(): BelongsTo
    {
        return $this->belongsTo(Dealership::class);
    }

    public function orgPath(): OrgPath
    {
        return new OrgPath($this->group_id, $this->company_id, $this->dealership_id, $this->id);
    }

    protected function orgScopeColumns(): array
    {
        return ['group' => 'group_id', 'company' => 'company_id', 'dealership' => 'dealership_id', 'department' => 'id'];
    }
}
