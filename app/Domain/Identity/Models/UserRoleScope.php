<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Access\AccessResolver;
use App\Domain\Identity\ScopeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Назначение роли пользователю в области. Выдача и отзыв пишутся в аудит явно (RoleAssignmentService).
 *
 * @property int $id
 * @property int $user_id
 * @property int $role_id
 * @property ScopeType $scope_type
 * @property int $scope_id
 * @property ?int $granted_by
 * @property-read User $user
 * @property-read Role $role
 */
class UserRoleScope extends Model
{
    protected $fillable = ['user_id', 'role_id', 'scope_type', 'scope_id', 'granted_by'];

    protected function casts(): array
    {
        return ['scope_type' => ScopeType::class, 'scope_id' => 'integer'];
    }

    protected static function booted(): void
    {
        $flush = fn (self $s) => app(AccessResolver::class)->flush($s->user_id);
        static::saved($flush);
        static::deleted($flush);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
