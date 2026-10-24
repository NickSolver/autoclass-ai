<?php

namespace App\Domain\Sources\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\CallIntake\Models\CallSource;
use App\Domain\Organization\Concerns\HasOrgScope;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\OrgPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Подключение источника звонков: адаптер, область (компания и подразделение по умолчанию), настройки,
 * секрет (зашифрован), адрес приёма событий, курсор синхронизации.
 *
 * @property int $id
 * @property int $source_id
 * @property string $name
 * @property int $group_id
 * @property int $company_id
 * @property ?int $dealership_id
 * @property ?int $department_id
 * @property array<string, mixed> $settings
 * @property ?string $secret
 * @property ?Carbon $secret_set_at
 * @property string $webhook_token
 * @property ?string $webhook_secret
 * @property bool $is_enabled
 * @property int $sync_interval_minutes
 * @property ?string $cursor
 * @property ?Carbon $last_sync_at
 * @property ?Carbon $last_success_at
 * @property ?string $last_error
 * @property ?int $created_by
 * @property int $lock_version
 * @property-read CallSource $source
 * @property-read Company $company
 */
class SourceConnection extends Model
{
    use Auditable, HasOrgScope;

    protected $guarded = ['id'];

    protected $hidden = ['secret', 'webhook_secret', 'webhook_token'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'secret' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'is_enabled' => 'boolean',
            'secret_set_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CallSource, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(CallSource::class, 'source_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<SourceSyncRun, $this> */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SourceSyncRun::class);
    }

    /** @return HasMany<SourceEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SourceEvent::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function orgPath(): OrgPath
    {
        return new OrgPath($this->group_id, $this->company_id, $this->dealership_id, $this->department_id);
    }

    protected function orgScopeColumns(): array
    {
        return ['group' => 'group_id', 'company' => 'company_id', 'dealership' => 'dealership_id', 'department' => 'department_id'];
    }

    public function auditSubjectType(): string
    {
        return 'source_connection';
    }

    protected function auditIgnored(): array
    {
        return ['created_at', 'updated_at', 'lock_version', 'secret_set_at', 'cursor', 'last_sync_at', 'last_success_at', 'last_error'];
    }

    protected function auditSensitive(): array
    {
        return ['secret', 'webhook_secret', 'webhook_token'];
    }
}
