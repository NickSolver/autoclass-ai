<?php

namespace App\Domain\Audit\Models;

use App\Domain\Identity\Access\AccessScope;
use App\Domain\Organization\Concerns\HasOrgScope;
use App\Domain\Organization\OrgPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Запись журнала аудита. Только добавление: изменение и удаление из приложения запрещены.
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property ?int $actor_user_id
 * @property ?string $actor_login
 * @property string $action
 * @property ?string $subject_type
 * @property ?int $subject_id
 * @property ?string $subject_label
 * @property ?int $group_id
 * @property ?int $company_id
 * @property ?int $dealership_id
 * @property ?int $department_id
 * @property ?array<string, mixed> $changes
 * @property ?string $reason
 * @property ?string $ip_address
 * @property ?string $user_agent
 */
class AuditEvent extends Model
{
    use HasOrgScope;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'changes' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Записи аудита неизменяемы.'));
        static::deleting(fn () => throw new LogicException('Записи аудита неизменяемы.'));
    }

    public function orgPath(): ?OrgPath
    {
        return $this->group_id === null ? null : new OrgPath(
            $this->group_id, $this->company_id, $this->dealership_id, $this->department_id,
        );
    }

    protected function orgScopeColumns(): array
    {
        return ['group' => 'group_id', 'company' => 'company_id', 'dealership' => 'dealership_id', 'department' => 'department_id'];
    }

    /** События вне оргструктуры (например, неудачный вход с неизвестным логином) видны на уровне группы. */
    protected function extendVisibilityQuery(Builder $query, AccessScope $scope): void
    {
        if ($scope->groupIds !== []) {
            $query->orWhereNull($this->qualifyColumn('group_id'));
        }
    }

    protected function isVisibleThroughExtension(AccessScope $scope): bool
    {
        return $this->group_id === null && $scope->groupIds !== [];
    }
}
