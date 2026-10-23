<?php

namespace App\Domain\AiProviders;

/** Основной и резервный провайдер на каждый вид; остальные конфигурации неактивны. */
enum ProviderRole: string
{
    case Primary = 'primary';
    case Fallback = 'fallback';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Primary => 'Основной',
            self::Fallback => 'Резервный',
            self::Inactive => 'Не используется',
        };
    }
}
