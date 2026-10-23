<?php

namespace App\Domain\AiProviders\Contracts;

/** Сырой ответ модели (JSON-строка) — проверяется сервером одинаково для любого провайдера. */
final readonly class EvaluationResponse
{
    public function __construct(
        public string $rawJson,
        public int $tokensIn,
        public int $tokensOut,
    ) {}
}
