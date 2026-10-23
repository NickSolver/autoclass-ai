<?php

namespace App\Domain\Identity\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Permission;
use App\Domain\Identity\ScopeType;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Роль — именованный набор прав. Коды ролей в бизнес-логике не используются.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ?string $description
 * @property bool $is_system
 * @property list<string> $allowed_scope_types
 * @property int $lock_version
 * @property-read Collection<int, PermissionDefinition> $permissions
 */
#[UseFactory(RoleFactory::class)]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use Auditable, HasFactory;

    protected $fillable = ['code', 'name', 'description', 'is_system', 'allowed_scope_types', 'lock_version'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'allowed_scope_types' => 'array',
        ];
    }

    /** @return BelongsToMany<PermissionDefinition, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(PermissionDefinition::class, 'role_permission', 'role_id', 'permission_id');
    }

    /** @return HasMany<UserRoleScope, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(UserRoleScope::class);
    }

    /** @return list<Permission> */
    public function permissionEnums(): array
    {
        return $this->permissions
            ->map(fn (PermissionDefinition $p) => Permission::tryFrom($p->code))
            ->filter()
            ->values()
            ->all();
    }

    public function allowsScope(ScopeType $type): bool
    {
        return in_array($type->value, $this->allowed_scope_types, true);
    }

    protected function auditIgnored(): array
    {
        return ['created_at', 'updated_at', 'lock_version'];
    }
}
