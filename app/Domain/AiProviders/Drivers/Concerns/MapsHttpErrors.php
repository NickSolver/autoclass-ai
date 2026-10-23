<?php

namespace App\Domain\AiProviders\Drivers\Concerns;

use App\Domain\AiProviders\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * Единая классификация ответов внешних провайдеров: 429 — повтор через Retry-After,
 * 5xx и сетевые сбои — повтор, 401/403/402/4xx — ошибка настройки без повторов.
 * Сообщения провайдера очищаются от фрагментов ключей.
 */
trait MapsHttpErrors
{
    /** @param callable(): Response $request */
    private function guarded(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw new ProviderException(ProviderException::TIMEOUT, 'Провайдер недоступен или не ответил вовремя.');
        }

        if ($response->successful()) {
            return $response;
        }

        $message = $this->safeMessage((string) ($response->json('error.message') ?? $response->json('message') ?? $response->json('error') ?? $response->reason()));
        throw match (true) {
            $response->status() === 429 => new ProviderException(
                ProviderException::RATE_LIMITED, "Превышен лимит запросов (429): {$message}",
                is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : 30,
            ),
            $response->status() >= 500 => new ProviderException(ProviderException::SERVER_ERROR, "Ошибка провайдера ({$response->status()}): {$message}"),
            $response->status() === 402 => new ProviderException(ProviderException::CONFIG_ERROR, "Недостаточно средств на счёте провайдера (402): {$message}"),
            in_array($response->status(), [401, 403], true) => new ProviderException(ProviderException::CONFIG_ERROR, "Ключ или права доступа отклонены ({$response->status()}): {$message}"),
            default => new ProviderException(ProviderException::CONFIG_ERROR, "Провайдер отклонил запрос ({$response->status()}): {$message}"),
        };
    }

    /** Сообщение провайдера без фрагментов ключей (DeepSeek, например, повторяет хвост ключа). */
    private function safeMessage(string $message): string
    {
        $message = (string) preg_replace('/(sk-[A-Za-z0-9_\-]{4,}|\*{2,}\S*|AQVN[A-Za-z0-9_\-]+|Api-Key \S+|Bearer \S+)/', '***', $message);

        return Str::limit($message, 200);
    }

    private function url(string $base, string $path): string
    {
        return rtrim($base, '/').$path;
    }
}
