<?php

namespace App\Domain\CallIntake\Jobs;

use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\Services\ProviderSelector;
use App\Domain\CallIntake\CallStatus;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Services\AnalysisService;
use App\Domain\Transcription\Models\TranscriptVersion;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Шаги «классификация» (выбор чек-листов) и «оценка» (ТЗ, разд. 5 п. 7–10). */
final class EvaluateCall implements ShouldBeUnique, ShouldQueue
{
    use Queueable, RunsPipelineStep;

    public const STEP = 'evaluate';

    public int $tries;

    public int $timeout = 900;

    public function __construct(public readonly int $callId, public readonly ?int $triggeredBy = null)
    {
        $this->tries = (int) config('analysis.tries', 3);
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'evaluate:'.$this->callId;
    }

    public function handle(ProviderSelector $selector, AnalysisService $analysis): void
    {
        $call = Call::query()->find($this->callId);
        if ($call === null || ! in_array($call->status, [CallStatus::AwaitingEvaluation, CallStatus::Classifying, CallStatus::Evaluating], true)) {
            return;
        }
        $transcript = TranscriptVersion::query()->where('call_id', $call->id)->orderByDesc('version')->first();
        $config = $selector->forAttempt(ProviderKind::Llm, $this->attempts(), $this->tries);
        if ($transcript === null || $config === null) {
            return;
        }

        $attempt = $this->startAttempt($call, self::STEP);
        $call->transitionTo(CallStatus::Classifying);
        try {
            $call->transitionTo(CallStatus::Evaluating);
            $analysis->analyze($call, $transcript, $config, $this->attempts());
        } catch (Throwable $e) {
            $this->handleFailure($call, $attempt, $e, CallStatus::AwaitingEvaluation);

            return;
        }
        $this->succeed($attempt);
        $call->transitionTo(CallStatus::Completed);
    }
}
