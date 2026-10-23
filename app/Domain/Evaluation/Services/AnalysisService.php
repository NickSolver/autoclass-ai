<?php

namespace App\Domain\Evaluation\Services;

use App\Domain\AiProviders\Contracts\EvaluationResponse;
use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderException;
use App\Domain\AiProviders\Services\DriverRegistry;
use App\Domain\AiProviders\Services\ProviderInvoker;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\AnalysisRun;
use App\Domain\Evaluation\Models\ChecklistVersion;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Transcription\Models\TranscriptVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Анализ звонка: маршрут чек-листов → запрос к модели по каждому → серверная проверка → оценка.
 * Ответ, не прошедший структурную проверку, запрашивается повторно (контролируемый повтор);
 * повторный провал — ошибка шага, звонок и запись не теряются.
 */
final class AnalysisService
{
    /** Повторов запроса к модели при структурно неверном ответе. */
    private const SCHEMA_RETRIES = 1;

    public function __construct(
        private readonly ChecklistRouter $router,
        private readonly PromptBuilder $prompts,
        private readonly ResponseValidator $validator,
        private readonly ScoreCalculator $scores,
        private readonly DriverRegistry $drivers,
        private readonly ProviderInvoker $invoker,
    ) {}

    /** @throws ProviderException */
    public function analyze(Call $call, TranscriptVersion $transcript, ProviderConfig $config, int $attempt): AnalysisRun
    {
        $transcript->loadMissing('segments');
        $routes = $this->router->route($call);
        $run = AnalysisRun::create([
            'call_id' => $call->id,
            'transcript_version_id' => $transcript->id,
            'provider_config_id' => $config->id,
            'driver' => $config->driver,
            'status' => 'running',
            'prompt_version' => (string) config('analysis.prompt_version'),
            'routing' => array_map(fn (array $r) => [
                'role' => $r['role'], 'checklist' => $r['version']->checklist->name,
                'checklist_version_id' => $r['version']->id, 'version' => $r['version']->version, 'reason' => $r['reason'],
            ], $routes),
            'started_at' => now(),
            'created_at' => now(),
        ]);

        try {
            $evaluated = [];
            foreach ($routes as $route) {
                $evaluated[] = [$route, $this->evaluateChecklist($call, $transcript, $config, $route['version'], $attempt)];
            }
        } catch (ProviderException $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => Str::limit($e->getMessage(), 490)]);
            throw $e;
        }

        DB::transaction(function () use ($run, $call, $evaluated) {
            foreach ($evaluated as [$route, $checked]) {
                $this->store($run, $call, $route['role'], $route['version'], $checked);
            }
            $run->update(['status' => 'succeeded', 'finished_at' => now()]);
            $call->forceFill(['review_required' => $run->results()->where('business_status', EvaluationResult::INTERVENTION)->exists()])->save();
        });

        return $run->load('results');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    private function evaluateChecklist(Call $call, TranscriptVersion $transcript, ProviderConfig $config, ChecklistVersion $version, int $attempt): array
    {
        $prompt = $this->prompts->build($call, $version, $transcript);
        $driver = $this->drivers->evaluation($config->driver);
        $lastError = null;

        for ($try = 0; $try <= self::SCHEMA_RETRIES; $try++) {
            /** @var EvaluationResponse $response */
            $response = $this->invoker->invoke(
                $config, 'evaluate',
                fn () => $driver->evaluate($config, $prompt),
                fn (EvaluationResponse $r) => ['tokens_in' => $r->tokensIn, 'tokens_out' => $r->tokensOut],
                ['call_id' => $call->id, 'prompt_version' => config('analysis.prompt_version'), 'input_hash' => $prompt['hash'], 'attempt' => $attempt],
            );
            try {
                return $this->validator->validate($response->rawJson, $version, $transcript->segments, (int) $call->duration_ms);
            } catch (InvalidEvaluationResponse $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new ProviderException(ProviderException::BAD_RESPONSE, "Модель вернула ответ не по схеме: {$lastError}");
    }

    /** @param array<string, mixed> $checked */
    private function store(AnalysisRun $run, Call $call, string $role, ChecklistVersion $version, array $checked): void
    {
        $score = $this->scores->calculate(array_map(fn (array $c) => [
            'weight' => (float) $c['criterion']->weight,
            'status' => $c['status'],
            'required' => $c['criterion']->is_required,
            'critical' => $c['criterion']->is_critical,
            'evidence_valid' => $c['evidence_valid'],
        ], $checked['criteria']), $version->zero_on_critical);

        $issues = $score['notes'];
        foreach ($checked['criteria'] as $c) {
            foreach ($c['issues'] as $issue) {
                $issues[] = "{$c['criterion']->title}: {$issue}";
            }
        }

        $result = EvaluationResult::create([
            'analysis_run_id' => $run->id,
            'call_id' => $call->id,
            'checklist_version_id' => $version->id,
            'role' => $role,
            'score' => $score['score'],
            'provisional_score' => $score['provisional_score'],
            'critical_violation' => $score['critical_violation'],
            'business_status' => $score['business_status'],
            'review_state' => $score['business_status'] === EvaluationResult::FULL ? 'not_required' : 'pending',
            'approval_state' => 'unreviewed',
            'summary' => $checked['summary'],
            'recommendations' => $checked['recommendations'],
            'risks' => $checked['risks'],
            'issues' => $issues,
            'created_at' => now(),
        ]);

        foreach ($checked['criteria'] as $c) {
            $criterionResult = $result->criteria()->create([
                'checklist_criterion_id' => $c['criterion']->id,
                'status' => $c['status'],
                'reason' => $c['reason'],
                'confidence' => $c['confidence'],
                'evidence_valid' => $c['evidence_valid'],
                'issues' => $c['issues'],
            ]);
            foreach ($c['evidence'] as $e) {
                $criterionResult->evidence()->create($e);
            }
        }
    }
}
