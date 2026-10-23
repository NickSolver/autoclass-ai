<?php

namespace App\Domain\AiProviders\Drivers\OpenAiCompatible;

use App\Domain\AiProviders\Contracts\EvaluationProviderInterface;
use App\Domain\AiProviders\Contracts\EvaluationResponse;
use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Оценка через OpenAI-совместимый Chat Completions API (DeepSeek, OpenAI и др.):
 * POST {endpoint}/chat/completions, ключ в заголовке Authorization: Bearer,
 * ответ — choices[0].message.content, расход — usage.prompt_tokens / completion_tokens,
 * ошибки — {"error": {"message", "type", "code"}}. Схема сверена с API DeepSeek (ADR-0004).
 */
final class OpenAiCompatibleEvaluation implements EvaluationProviderInterface
{
    public function evaluate(ProviderConfig $config, array $prompt): EvaluationResponse
    {
        $body = [
            'model' => $config->model ?: throw new ProviderException(ProviderException::CONFIG_ERROR, 'Не указана модель.'),
            'messages' => [
                ['role' => 'system', 'content' => $prompt['system']],
                ['role' => 'user', 'content' => $prompt['user']],
            ],
            'temperature' => (float) $config->setting('temperature', 0),
            'stream' => false,
        ];
        if ($config->setting('json_mode', true)) {
            // JSON-режим: модель обязана вернуть JSON-объект (у DeepSeek требует слова «json» в запросе — оно есть).
            $body['response_format'] = ['type' => 'json_object'];
        }
        if ($max = $config->setting('max_tokens')) {
            $body['max_tokens'] = (int) $max;
        }

        $response = $this->send(fn (PendingRequest $http) => $http->post($this->url($config, '/chat/completions'), $body), $config);

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            $reason = (string) $response->json('choices.0.finish_reason', '');
            throw new ProviderException(ProviderException::BAD_RESPONSE, 'Модель вернула пустой ответ'.($reason !== '' ? " (finish_reason: {$reason})" : '').'.');
        }

        return new EvaluationResponse(
            $content,
            (int) $response->json('usage.prompt_tokens', 0),
            (int) $response->json('usage.completion_tokens', 0),
        );
    }

    /** Проверка ключа и адреса: список моделей (GET /models). */
    public function testConnection(ProviderConfig $config): void
    {
        $this->send(fn (PendingRequest $http) => $http->get($this->url($config, '/models')), $config);
    }

    public function isExternal(): bool
    {
        return true;
    }

    /** @param callable(PendingRequest): Response $request */
    private function send(callable $request, ProviderConfig $config): Response
    {
        $secret = $config->secret ?: throw new ProviderException(ProviderException::CONFIG_ERROR, 'Не задан ключ API.');
        $http = Http::withToken($secret)
            ->acceptJson()
            ->timeout((int) $config->setting('timeout_seconds', 120))
            ->connectTimeout(10)
            ->withOptions(['allow_redirects' => false]);

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            throw new ProviderException(ProviderException::TIMEOUT, 'Провайдер недоступен или не ответил вовремя.');
        }

        if ($response->successful()) {
            return $response;
        }

        $message = $this->safeMessage((string) ($response->json('error.message') ?? $response->reason()));
        throw match (true) {
            $response->status() === 429 => new ProviderException(
                ProviderException::RATE_LIMITED, "Превышен лимит запросов (429): {$message}",
                is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : 30,
            ),
            $response->status() >= 500 => new ProviderException(ProviderException::SERVER_ERROR, "Ошибка провайдера ({$response->status()}): {$message}"),
            $response->status() === 402 => new ProviderException(ProviderException::CONFIG_ERROR, "Недостаточно средств на счёте провайдера (402): {$message}"),
            in_array($response->status(), [401, 403], true) => new ProviderException(ProviderException::CONFIG_ERROR, "Ключ API отклонён ({$response->status()})."),
            default => new ProviderException(ProviderException::CONFIG_ERROR, "Провайдер отклонил запрос ({$response->status()}): {$message}"),
        };
    }

    private function url(ProviderConfig $config, string $path): string
    {
        return rtrim((string) $config->endpoint, '/').$path;
    }

    /** Сообщение провайдера без фрагментов ключа (DeepSeek, например, повторяет хвост ключа). */
    private function safeMessage(string $message): string
    {
        $message = (string) preg_replace('/(sk-[A-Za-z0-9_\-]{4,}|\*{2,}\S*)/', '***', $message);

        return Str::limit($message, 200);
    }
}
