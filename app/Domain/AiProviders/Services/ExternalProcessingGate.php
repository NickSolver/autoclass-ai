<?php

namespace App\Domain\AiProviders\Services;

use App\Domain\AiProviders\Models\SystemSetting;

/**
 * Глобальный запрет передачи записей внешним провайдерам (ТЗ, разд. 12): по умолчанию выключено.
 * Включает ответственный администратор после юридической проверки — с причиной и аудитом.
 */
final class ExternalProcessingGate
{
    public const KEY = 'ai.external_processing_enabled';

    public function isAllowed(): bool
    {
        return (bool) (SystemSetting::get(self::KEY)['enabled'] ?? false);
    }

    /** @return array{enabled: bool, reason: ?string, changed_at: ?string, changed_by: ?int} */
    public function state(): array
    {
        $setting = SystemSetting::query()->find(self::KEY);
        $value = $setting->value ?? [];

        return [
            'enabled' => (bool) ($value['enabled'] ?? false),
            'reason' => $value['reason'] ?? null,
            'changed_at' => $setting?->updated_at?->toIso8601String(),
            'changed_by' => $setting?->updated_by,
        ];
    }
}
