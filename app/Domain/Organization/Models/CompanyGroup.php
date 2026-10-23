<?php

namespace App\Domain\Organization\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Concerns\HasOrgScope;
use App\Domain\Organization\OrgPath;
use Database\Factories\CompanyGroupFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
#[UseFactory(CompanyGroupFactory::class)]
class CompanyGroup extends Model
{
    /** @use HasFactory<CompanyGroupFactory> */
    use Auditable, HasFactory, HasOrgScope;

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<Company, $this> */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class, 'group_id');
    }

    public function orgPath(): OrgPath
    {
        return new OrgPath($this->id);
    }

    protected function orgScopeColumns(): array
    {
        return ['group' => 'id'];
    }

    public function auditSubjectType(): string
    {
        return 'company_group';
    }
}
