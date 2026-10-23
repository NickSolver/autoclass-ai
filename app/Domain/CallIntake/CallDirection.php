<?php

namespace App\Domain\CallIntake;

enum CallDirection: string
{
    case Incoming = 'incoming';
    case Outgoing = 'outgoing';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Incoming => 'Входящий',
            self::Outgoing => 'Исходящий',
            self::Unknown => 'Не указано',
        };
    }
}
