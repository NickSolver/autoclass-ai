<?php

namespace App\Domain\CallIntake\Jobs;

use App\Domain\AiProviders\ProviderException;
use App\Domain\CallIntake\CallStatus;
use App\Domain\CallIntake\Models\Call;
use App\Domain\CallIntake\Models\CallProcessingAttempt;
use Illuminate\Support\Str;
use Throwable;

/**
 * Общая обработка шага конвейера с провайдером ИИ: запись попытки, повтор временных сбоев,
 * окончательная ошибка — статус «Ошибка обработки» с причиной; звонок и запись не теряются.
 */
trait RunsPipelineStep
{
    private function startAttempt(Call $call, string $step): CallProcessingAttempt
    {
        return $call->attempts()->create([
            'step' => $step,
            'attempt' => $call->attempts()->where('step', $step)->count() + 1,
            'status' => CallProcessingAttempt::RUNNING,
            'started_at' => now(),
            'triggered_by' => $this->triggeredBy ?? null,
        ]);
    }

    private function succeed(CallProcessingAttempt $attempt): void
    {
        $attempt->update(['status' => CallProcessingAttempt::SUCCEEDED, 'finished_at' => now()]);
    }

    /**
     * @param  CallStatus  $waiting  статус, в котором звонок ждёт повтора шага
     */
    private function handleFailure(Call $call, CallProcessingAttempt $attempt, Throwable $e, CallStatus $waiting): void
    {
        $code = $e instanceof ProviderException ? $e->errorCode : 'processing_error';
        $message = Str::limit($e->getMessage(), 490);
        $attempt->update(['status' => CallProcessingAttempt::FAILED, 'finished_at' => now(), 'error_code' => $code, 'error_message' => $message]);

        $retryable = ! ($e instanceof ProviderException) || $e->isRetryable();
        if (! $retryable || $this->attempts() >= $this->tries) {
            $call->transitionTo(CallStatus::Failed, $message);
            $retryable ? throw $e : $this->fail($e);

            return;
        }

        $call->transitionTo($waiting, 'Временный сбой, будет повтор: '.$message);
        if ($e instanceof ProviderException && $e->retryAfterSeconds !== null) {
            // 429: провайдер сам сказал, когда повторить.
            $this->release($e->retryAfterSeconds);

            return;
        }
        throw $e;
    }

    public function failed(?Throwable $e): void
    {
        $call = Call::query()->find($this->callId);
        if ($call !== null && ! in_array($call->status, [CallStatus::Failed, CallStatus::Excluded], true)) {
            $call->transitionTo(CallStatus::Failed, Str::limit($e?->getMessage() ?? 'Задача не выполнена.', 490));
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return array_map('intval', (array) config('analysis.backoff', [20, 60]));
    }
}
