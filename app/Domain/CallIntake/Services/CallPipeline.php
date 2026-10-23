<?php

namespace App\Domain\CallIntake\Services;

use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\Services\ProviderSelector;
use App\Domain\CallIntake\CallStatus;
use App\Domain\CallIntake\Jobs\EvaluateCall;
use App\Domain\CallIntake\Jobs\TranscribeCall;
use App\Domain\CallIntake\Models\Call;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Переходы между шагами конвейера (ТЗ, разд. 5). Если провайдер следующего шага не включён,
 * звонок честно остаётся в статусе ожидания этого шага.
 */
final class CallPipeline
{
    public function __construct(private readonly ProviderSelector $providers) {}

    public function afterPrepared(Call $call): void
    {
        $call->transitionTo(CallStatus::AwaitingTranscription);
        if ($this->providers->isConfigured(ProviderKind::Asr)) {
            $this->dispatch(new TranscribeCall($call->id));
        }
    }

    public function afterTranscribed(Call $call): void
    {
        $call->transitionTo(CallStatus::AwaitingEvaluation);
        if ($this->providers->isConfigured(ProviderKind::Llm)) {
            $this->dispatch(new EvaluateCall($call->id));
        }
    }

    private function dispatch(object $job): void
    {
        try {
            dispatch($job);
        } catch (Throwable $e) {
            // Синхронная очередь пробрасывает ошибку шага; статус и причину уже сохранил сам шаг.
            Log::warning('Шаг конвейера не выполнен', ['job' => $job::class, 'error' => $e->getMessage()]);
        }
    }
}
