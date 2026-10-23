<?php

namespace App\Domain\AiProviders\Services;

use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\ProviderRole;

/**
 * Выбор провайдера для шага конвейера: основной; на последней попытке — резервный, если задан.
 * Внешний провайдер не используется, пока внешняя обработка не разрешена.
 */
final class ProviderSelector
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly ExternalProcessingGate $gate,
    ) {}

    public function primary(ProviderKind $kind): ?ProviderConfig
    {
        return $this->usable(ProviderConfig::query()->where('kind', $kind)->where('role', ProviderRole::Primary)->first());
    }

    public function fallback(ProviderKind $kind): ?ProviderConfig
    {
        return $this->usable(ProviderConfig::query()->where('kind', $kind)->where('role', ProviderRole::Fallback)->first());
    }

    public function forAttempt(ProviderKind $kind, int $attempt, int $maxAttempts): ?ProviderConfig
    {
        if ($attempt >= $maxAttempts && $attempt > 1 && ($fallback = $this->fallback($kind)) !== null) {
            return $fallback;
        }

        return $this->primary($kind) ?? $this->fallback($kind);
    }

    public function isConfigured(ProviderKind $kind): bool
    {
        return $this->primary($kind) !== null || $this->fallback($kind) !== null;
    }

    private function usable(?ProviderConfig $config): ?ProviderConfig
    {
        if ($config === null || ! $this->drivers->exists($config->kind, $config->driver)) {
            return null;
        }
        if ($this->drivers->isExternal($config->driver) && ! $this->gate->isAllowed()) {
            return null;
        }

        return $config;
    }
}
