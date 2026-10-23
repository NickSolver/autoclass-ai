<?php

namespace App\Domain\AiProviders\Contracts;

use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderException;

/** Оценка разговора по чек-листу; ответ — строгий JSON по схеме PromptBuilder (ТЗ, разд. 8). */
interface EvaluationProviderInterface
{
    /**
     * @param  array{system: string, user: string, schema: array<string, mixed>, payload: array<string, mixed>}  $prompt
     *
     * @throws ProviderException
     */
    public function evaluate(ProviderConfig $config, array $prompt): EvaluationResponse;

    /** @throws ProviderException */
    public function testConnection(ProviderConfig $config): void;

    public function isExternal(): bool;
}
