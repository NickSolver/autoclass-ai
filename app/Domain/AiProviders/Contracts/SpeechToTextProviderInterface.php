<?php

namespace App\Domain\AiProviders\Contracts;

use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderException;

/** Распознавание речи с таймкодами и разделением говорящих (ТЗ, разд. 8). */
interface SpeechToTextProviderInterface
{
    /** @throws ProviderException */
    public function transcribe(ProviderConfig $config, AudioInput $audio): TranscriptionResult;

    /** @throws ProviderException */
    public function testConnection(ProviderConfig $config): void;

    /** Провайдер передаёт данные за пределы портала (требует разрешения внешней обработки). */
    public function isExternal(): bool;
}
