<?php

namespace App\Domain\AiProviders\Drivers\Mock;

use App\Domain\AiProviders\ProviderException;

/** Общая имитация сетевых сбоев для тестового провайдера. */
trait MockFailures
{
    private function simulateFailure(MockScenario $scenario): void
    {
        match ($scenario) {
            MockScenario::Timeout => throw new ProviderException(ProviderException::TIMEOUT, 'Провайдер не ответил за отведённое время (имитация).'),
            MockScenario::RateLimited => throw new ProviderException(ProviderException::RATE_LIMITED, 'Превышен лимит запросов провайдера (имитация 429).', 5),
            MockScenario::ServerError => throw new ProviderException(ProviderException::SERVER_ERROR, 'Ошибка на стороне провайдера (имитация 500).'),
            default => null,
        };
    }
}
