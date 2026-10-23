<?php

namespace App\Domain\AiProviders;

enum ProviderKind: string
{
    /** Распознавание речи. */
    case Asr = 'asr';
    /** Оценка разговора по чек-листу. */
    case Llm = 'llm';

    public function label(): string
    {
        return match ($this) {
            self::Asr => 'Распознавание речи',
            self::Llm => 'Оценка разговора',
        };
    }
}
