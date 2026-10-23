<?php

namespace App\Domain\AiProviders\Services;

use App\Domain\AiProviders\Models\ProviderCall;
use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderException;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * Вызов провайдера с записью в журнал: время, объём (секунды аудио / токены), стоимость по тарифу
 * конфигурации, ошибка. Содержимое запросов и ответов в журнал не пишется (ТЗ, разд. 8).
 */
final class ProviderInvoker
{
    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @param  Closure(T): array{audio_seconds?: float, tokens_in?: int, tokens_out?: int}  $usage
     * @param  array{call_id?: ?int, prompt_version?: ?string, input_hash?: ?string, attempt?: int}  $context
     * @return T
     */
    public function invoke(ProviderConfig $config, string $operation, Closure $call, Closure $usage, array $context = []): mixed
    {
        $started = now();
        $t0 = hrtime(true);
        $log = [
            'provider_config_id' => $config->id,
            'call_id' => $context['call_id'] ?? null,
            'kind' => $config->kind->value,
            'driver' => $config->driver,
            'model' => $config->model,
            'operation' => $operation,
            'prompt_version' => $context['prompt_version'] ?? null,
            'input_hash' => $context['input_hash'] ?? null,
            'started_at' => $started,
            'attempt' => $context['attempt'] ?? 1,
            'currency' => (string) $config->setting('currency', 'RUB'),
        ];

        try {
            $result = $call();
        } catch (Throwable $e) {
            ProviderCall::create($log + [
                'duration_ms' => (int) ((hrtime(true) - $t0) / 1_000_000),
                'status' => 'error',
                'error_code' => $e instanceof ProviderException ? $e->errorCode : 'exception',
                'error_message' => Str::limit($e->getMessage(), 290),
            ]);
            throw $e;
        }

        $u = $usage($result);
        ProviderCall::create($log + [
            'duration_ms' => (int) ((hrtime(true) - $t0) / 1_000_000),
            'status' => 'ok',
            'audio_seconds' => $u['audio_seconds'] ?? null,
            'tokens_in' => $u['tokens_in'] ?? null,
            'tokens_out' => $u['tokens_out'] ?? null,
            'cost' => $this->cost($config, $u),
        ]);

        return $result;
    }

    /** @param array{audio_seconds?: float, tokens_in?: int, tokens_out?: int} $usage */
    private function cost(ProviderConfig $config, array $usage): ?float
    {
        $perMinute = $config->setting('price_per_minute');
        $perInK = $config->setting('price_per_1k_tokens_in');
        $perOutK = $config->setting('price_per_1k_tokens_out');

        $cost = null;
        if (isset($usage['audio_seconds']) && is_numeric($perMinute)) {
            $cost = ($usage['audio_seconds'] / 60) * (float) $perMinute;
        }
        if (isset($usage['tokens_in']) && is_numeric($perInK)) {
            $cost = ($cost ?? 0) + $usage['tokens_in'] / 1000 * (float) $perInK;
        }
        if (isset($usage['tokens_out']) && is_numeric($perOutK)) {
            $cost = ($cost ?? 0) + $usage['tokens_out'] / 1000 * (float) $perOutK;
        }

        return $cost === null ? null : round($cost, 4);
    }
}
