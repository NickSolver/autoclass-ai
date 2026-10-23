<?php

namespace App\Domain\AiProviders\Models;

use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\ProviderRole;
use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Конфигурация провайдера ИИ. Секрет хранится зашифрованным и наружу не отдаётся.
 *
 * @property int $id
 * @property ProviderKind $kind
 * @property string $driver
 * @property string $name
 * @property ?string $model
 * @property ?string $endpoint
 * @property array<string, mixed> $settings
 * @property ?string $secret
 * @property ?Carbon $secret_set_at
 * @property ProviderRole $role
 * @property int $lock_version
 * @property ?int $created_by
 */
class ProviderConfig extends Model
{
    use Auditable;

    protected $fillable = ['kind', 'driver', 'name', 'model', 'endpoint', 'settings', 'role', 'created_by'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'kind' => ProviderKind::class,
            'role' => ProviderRole::class,
            'settings' => 'array',
            'secret' => 'encrypted',
            'secret_set_at' => 'datetime',
        ];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function auditSubjectType(): string
    {
        return 'provider_config';
    }

    protected function auditIgnored(): array
    {
        return ['created_at', 'updated_at', 'lock_version', 'secret_set_at'];
    }

    protected function auditSensitive(): array
    {
        return ['secret'];
    }
}
