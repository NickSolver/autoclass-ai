<?php

namespace App\Http\Resources;

use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\Services\DriverRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Конфигурация провайдера. Ключ API не возвращается никогда — только факт и дата установки.
 *
 * @mixin ProviderConfig
 */
class ProviderConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'driver' => $this->driver,
            'external' => app(DriverRegistry::class)->isExternal($this->driver),
            'name' => $this->name,
            'model' => $this->model,
            'endpoint' => $this->endpoint,
            'settings' => $this->settings,
            'role' => $this->role->value,
            'secret_set' => $this->secret_set_at !== null,
            'secret_set_at' => $this->secret_set_at?->toIso8601String(),
            'lock_version' => $this->lock_version,
            'stats' => $this->when($this->resource->getAttribute('stats') !== null, fn () => $this->resource->getAttribute('stats')),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
