<?php

namespace App\Domain\Workflow\Services;

use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\AnalysisRun;
use App\Domain\Evaluation\Models\Checklist;
use App\Domain\Evaluation\Models\ChecklistVersion;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Organization\Models\Department;
use App\Domain\Transcription\Models\TranscriptVersion;
use App\Domain\Workflow\Engine\Plan;
use App\Domain\Workflow\Engine\WorkflowEngine;
use App\Domain\Workflow\Graph\Graph;
use App\Domain\Workflow\Models\WorkflowDefinition;
use App\Domain\Workflow\Models\WorkflowRun;
use App\Domain\Workflow\Models\WorkflowVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Выполнение бизнес-процесса для звонка (ТЗ, разд. 6.2). Экземпляр закрепляется за версией процесса
 * и за версиями чек-листов на старте: публикация новых версий не меняет уже запущенные экземпляры.
 * Если опубликованного процесса нет — правило по умолчанию из config/analysis.php.
 */
final class WorkflowRuntime
{
    public const TRIGGER = 'transcription_completed';

    public function __construct(private readonly WorkflowEngine $engine) {}

    /** Процесс для звонка: компания важнее группы, затем приоритет. */
    public function versionFor(Call $call): ?WorkflowVersion
    {
        $definitions = WorkflowDefinition::query()
            ->where('trigger', self::TRIGGER)
            ->where('is_archived', false)
            ->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $call->company_id))
            ->orderByRaw('company_id is null')
            ->orderBy('priority')->orderBy('id')
            ->get();

        foreach ($definitions as $definition) {
            if ($version = $definition->latestPublished()) {
                return $version;
            }
        }

        return null;
    }

    /** Активный экземпляр, ожидающий анализа этой расшифровки (закреплённая версия). */
    public function pending(Call $call, TranscriptVersion $transcript): ?WorkflowRun
    {
        return WorkflowRun::query()->where('call_id', $call->id)->where('transcript_version_id', $transcript->id)
            ->where('status', 'waiting_analysis')->latest('id')->first();
    }

    /** Первая фаза: до «Запустить анализ» или до конца. */
    public function start(Call $call, TranscriptVersion $transcript): WorkflowRun
    {
        $version = $this->versionFor($call);
        $context = $this->context($call, $transcript);
        $plan = new Plan;

        $run = WorkflowRun::create([
            'call_id' => $call->id, 'workflow_version_id' => $version?->id, 'trigger' => self::TRIGGER,
            'status' => 'running', 'context' => $context, 'plan' => [], 'transcript_version_id' => $transcript->id,
            'started_at' => now(), 'created_at' => now(),
        ]);

        // Новый проход процесса — новые решения: прежние метки и задачи этого звонка снимаются.
        $call->forceFill(['labels' => null, 'priority' => 'normal', 'review_required' => false, 'review_reason' => null])->save();

        try {
            if ($version === null) {
                $this->fallback($call, $plan);
                $result = ['status' => 'paused', 'node_id' => null, 'trace' => []];
            } else {
                $result = $this->engine->run(new Graph($version->graph), null, $context, $plan);
            }
            $this->pinChecklists($plan);
        } catch (RuntimeException $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => $e->getMessage()]);
            throw $e;
        }

        $this->record($run, $result['trace']);
        $this->apply($call, $plan);
        $run->update([
            'plan' => $plan->toArray(),
            'current_node_id' => $result['node_id'],
            'status' => $result['status'] === 'paused' && $plan->analysisRequested ? 'waiting_analysis' : 'completed',
            'finished_at' => $result['status'] === 'completed' ? now() : null,
        ]);

        return $run->refresh();
    }

    /**
     * Маршрут оценки из плана — версии чек-листов, закреплённые на старте экземпляра.
     *
     * @return list<array{role: string, version: ChecklistVersion, reason: string}>
     */
    public function routes(WorkflowRun $run): array
    {
        $plan = Plan::fromArray($run->plan);
        $reason = $run->workflow_version_id ? 'Выбран бизнес-процессом.' : 'Правило по умолчанию (опубликованного процесса нет).';
        $load = fn (int $checklistId) => ChecklistVersion::query()->with(['checklist', 'criteria'])
            ->findOrFail($plan->pinnedVersions[$checklistId] ?? throw new RuntimeException("Версия чек-листа #{$checklistId} не закреплена."));

        $routes = [['role' => 'primary', 'version' => $load((int) $plan->primaryChecklistId), 'reason' => $reason]];
        foreach ($plan->additionalChecklistIds as $id) {
            $routes[] = ['role' => 'additional', 'version' => $load($id), 'reason' => $reason];
        }

        return $routes;
    }

    /** Вторая фаза: после анализа, с полями результата, по той же версии процесса. */
    public function finish(WorkflowRun $run, Call $call, AnalysisRun $analysis): WorkflowRun
    {
        $plan = Plan::fromArray($run->plan);
        $context = $run->context + $this->resultContext($analysis);

        if ($run->workflow_version_id !== null && $run->current_node_id !== null) {
            $version = WorkflowVersion::query()->findOrFail($run->workflow_version_id);
            try {
                $result = $this->engine->resume(new Graph($version->graph), $run->current_node_id, $context, $plan);
            } catch (RuntimeException $e) {
                $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => $e->getMessage()]);
                throw $e;
            }
            $this->record($run, $result['trace']);
        }

        $this->apply($call, $plan);
        $run->update(['plan' => $plan->toArray(), 'context' => $context, 'status' => 'completed', 'current_node_id' => null, 'finished_at' => now()]);

        return $run;
    }

    /** @return array<string, mixed> */
    public function context(Call $call, ?TranscriptVersion $transcript): array
    {
        $segments = $transcript?->segments()->get();
        $confidences = $segments?->pluck('confidence')->filter(fn ($c) => $c !== null);

        return [
            'direction' => $call->direction->value,
            'source' => $call->source()->value('code'),
            'company_id' => $call->company_id,
            'dealership_id' => $call->dealership_id,
            'department_id' => $call->department_id,
            'department_kind' => $call->department_id ? Department::query()->whereKey($call->department_id)->value('kind') : null,
            'duration_seconds' => $call->duration_ms !== null ? (int) round($call->duration_ms / 1000) : null,
            'employee_assigned' => $call->employee_id !== null,
            'transcript_synthetic' => (bool) $transcript?->is_synthetic,
            'transcript_confidence' => $confidences && $confidences->isNotEmpty() ? round((float) $confidences->avg(), 3) : null,
            'speakers_count' => $segments?->pluck('speaker')->unique()->count() ?? 0,
        ];
    }

    /** @return array<string, mixed> */
    public function resultContext(AnalysisRun $analysis): array
    {
        $results = $analysis->results()->get();
        $primary = $results->firstWhere('role', 'primary');

        return [
            'business_status' => $primary?->business_status,
            'primary_score' => $primary?->score,
            'critical_violation' => $results->contains(fn (EvaluationResult $r) => $r->critical_violation),
            'any_intervention' => $results->contains(fn (EvaluationResult $r) => $r->business_status === EvaluationResult::INTERVENTION),
        ];
    }

    /** Правило по умолчанию: направление звонка → чек-листы (config/analysis.php). */
    private function fallback(Call $call, Plan $plan): void
    {
        $byCode = fn (string $code) => Checklist::query()->where('code', $code)->value('id')
            ?? throw new RuntimeException("Нет чек-листа «{$code}» для правила по умолчанию.");
        $plan->primaryChecklistId = (int) $byCode((string) config("analysis.routing.primary.{$call->direction->value}"));
        foreach ((array) config('analysis.routing.additional', []) as $code) {
            $plan->additionalChecklistIds[] = (int) $byCode((string) $code);
        }
        $plan->analysisRequested = true;
    }

    /** Закрепление опубликованных версий чек-листов на момент выбора маршрута. */
    private function pinChecklists(Plan $plan): void
    {
        foreach (array_filter([$plan->primaryChecklistId, ...$plan->additionalChecklistIds]) as $id) {
            $version = Checklist::query()->find($id)?->latestPublished()
                ?? throw new RuntimeException("У чек-листа #{$id} нет опубликованной версии.");
            $plan->pinnedVersions[(int) $id] = $version->id;
        }
    }

    /** @param list<array{node_id: string, node_type: string, branch: ?string, output: array<string, mixed>}> $trace */
    private function record(WorkflowRun $run, array $trace): void
    {
        foreach ($trace as $step) {
            $run->nodeRuns()->create([
                'node_id' => $step['node_id'], 'node_type' => $step['node_type'], 'branch' => $step['branch'],
                'output' => $step['output'], 'status' => 'succeeded', 'created_at' => now(),
            ]);
        }
    }

    /** Действия процесса над звонком: метки, приоритет, задача на проверку. */
    private function apply(Call $call, Plan $plan): void
    {
        DB::transaction(function () use ($call, $plan) {
            $call->refresh();
            $attributes = ['labels' => $plan->labels ?: null, 'priority' => $plan->priority ?? 'normal'];
            if ($plan->reviewReasons !== []) {
                $attributes['review_required'] = true;
                $attributes['review_reason'] = mb_substr(implode('; ', $plan->reviewReasons), 0, 500);
            }
            $call->forceFill($attributes)->save();
        });
    }
}
