<?php

namespace App\Domain\AiProviders\Drivers\OpenAiCompatible;

use App\Domain\AiProviders\Contracts\EvaluationProviderInterface;
use App\Domain\AiProviders\Contracts\EvaluationResponse;
use App\Domain\AiProviders\Drivers\Concerns\MapsHttpErrors;
use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Оценка через OpenAI-совместимый Chat Completions API (DeepSeek, OpenAI и др.):
 * POST {endpoint}/chat/completions, ключ в заголовке Authorization: Bearer,
 * ответ — choices[0].message.content, расход — usage.prompt_tokens / completion_tokens,
 * ошибки — {"error": {"message", "type", "code"}}. Схема сверена с API DeepSeek (ADR-0004).
 */
final class OpenAiCompatibleEvaluation implements EvaluationProviderInterface
{
    use MapsHttpErrors;

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

        $response = $this->send(fn (PendingRequest $http) => $http->post($this->url((string) $config->endpoint, '/chat/completions'), $body), $config);

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

    /**
     * Проверка ключа, адреса и модели: список моделей (GET /models). Линейки моделей меняются —
     * если указанной модели нет в списке, администратор сразу видит доступные.
     */
    public function testConnection(ProviderConfig $config): void
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get($this->url((string) $config->endpoint, '/models')), $config);
        $available = collect((array) $response->json('data'))->pluck('id')->filter()->values();
        if ($available->isNotEmpty() && ! $available->contains($config->model)) {
            throw new ProviderException(ProviderException::CONFIG_ERROR,
                "Ключ работает, но модели «{$config->model}» нет. Доступные: ".$available->implode(', ').'.');
        }
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

        return $this->guarded(fn () => $request($http));
    }
}
