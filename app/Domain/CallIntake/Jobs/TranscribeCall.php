<?php

namespace App\Domain\CallIntake\Jobs;

use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\Services\ProviderSelector;
use App\Domain\CallIntake\CallStatus;
use App\Domain\CallIntake\Models\Call;
use App\Domain\CallIntake\Services\CallPipeline;
use App\Domain\Transcription\Services\TranscriptionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Шаг «распознавание речи» (ТЗ, разд. 5 п. 5–6). */
final class TranscribeCall implements ShouldBeUnique, ShouldQueue
{
    use Queueable, RunsPipelineStep;

    public const STEP = 'transcribe';

    public int $tries;

    public int $timeout = 900;

    public function __construct(public readonly int $callId, public readonly ?int $triggeredBy = null)
    {
        $this->tries = (int) config('analysis.tries', 3);
        $this->onQueue('audio');
    }

    public function uniqueId(): string
    {
        return 'transcribe:'.$this->callId;
    }

    public function handle(ProviderSelector $selector, TranscriptionService $transcription, CallPipeline $pipeline): void
    {
        $call = Call::query()->with('normalized')->find($this->callId);
        if ($call === null || ! in_array($call->status, [CallStatus::AwaitingTranscription, CallStatus::Transcribing], true)) {
            return;
        }
        $config = $selector->forAttempt(ProviderKind::Asr, $this->attempts(), $this->tries);
        if ($config === null) {
            return; // Провайдер отключили, пока задача ждала очереди: звонок остаётся ждать распознавания.
        }

        $attempt = $this->startAttempt($call, self::STEP);
        $call->transitionTo(CallStatus::Transcribing);
        try {
            $transcription->transcribe($call, $config, $this->attempts());
        } catch (Throwable $e) {
            $this->handleFailure($call, $attempt, $e, CallStatus::AwaitingTranscription);

            return;
        }
        $this->succeed($attempt);
        $pipeline->afterTranscribed($call);
    }
}
