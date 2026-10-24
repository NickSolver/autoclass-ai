<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\AnalysisRun;
use App\Domain\Evaluation\Models\CriterionResult;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Evaluation\Models\Evidence;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Review\Models\Appeal;
use App\Domain\Review\Models\ManualReview;
use App\Domain\Review\Models\ReviewDecision;
use App\Domain\Transcription\Models\TranscriptSegment;
use App\Domain\Transcription\Models\TranscriptVersion;
use App\Domain\Transcription\Services\TranscriptCorrectionService;
use App\Domain\Workflow\Graph\NodeTypes;
use App\Domain\Workflow\Models\WorkflowNodeRun;
use App\Domain\Workflow\Models\WorkflowRun;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReviewPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Расшифровка (transcript.view) и оценка (evaluation.view) звонка — в области пользователя. */
class CallAnalysisController extends Controller
{
    public function transcript(Request $request, Call $call): JsonResponse
    {
        abort_unless($call->isVisibleTo($request->user(), Permission::TranscriptView), 403, 'Нет права просматривать расшифровки.');

        $versions = TranscriptVersion::query()->where('call_id', $call->id)->orderByDesc('version')->get();
        $selected = $request->filled('version')
            ? $versions->firstWhere('version', $request->integer('version'))
            : $versions->first();

        return response()->json([
            'versions' => $versions->map(fn (TranscriptVersion $v) => [
                'version' => $v->version, 'source' => $v->source, 'is_synthetic' => $v->is_synthetic, 'created_at' => $v->created_at->toIso8601String(),
            ])->values(),
            'current' => $selected ? [
                'version' => $selected->version,
                'source' => $selected->source,
                'is_synthetic' => $selected->is_synthetic,
                'created_at' => $selected->created_at->toIso8601String(),
                'reason' => $selected->reason,
                'author' => $selected->created_by ? User::query()->whereKey($selected->created_by)->value('name') : null,
                'segments' => $selected->segments->map(fn (TranscriptSegment $s) => [
                    'id' => $s->id, 'start_ms' => $s->start_ms, 'end_ms' => $s->end_ms,
                    'speaker' => $s->speaker, 'speaker_role' => $s->speaker_role, 'text' => $s->text,
                    'confidence' => $s->confidence !== null ? (float) $s->confidence : null,
                ])->values(),
            ] : null,
        ]);
    }

    /** Исправление расшифровки: новая версия, прежняя сохраняется. */
    public function correctTranscript(Request $request, Call $call, TranscriptCorrectionService $corrections): JsonResponse
    {
        abort_unless($call->isVisibleTo($request->user(), Permission::TranscriptEdit), 403, 'Нет права исправлять расшифровки.');
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'segments' => ['required', 'array', 'min:1', 'max:1000'],
            'segments.*.id' => ['required', 'integer'],
            'segments.*.text' => ['sometimes', 'string', 'max:5000'],
            'segments.*.speaker_role' => ['sometimes', 'in:agent,client,unknown'],
        ]);
        $base = TranscriptVersion::query()->where('call_id', $call->id)->where('version', $data['version'])->firstOrFail();
        $version = $corrections->correct($request->user(), $call, $base, $data['segments'], $data['reason']);

        return response()->json(['version' => $version->version], 201);
    }

    public function analysis(Request $request, Call $call): JsonResponse
    {
        abort_unless($call->isVisibleTo($request->user(), Permission::EvaluationView), 403, 'Нет права просматривать оценки.');

        $runs = AnalysisRun::query()->where('call_id', $call->id)->orderByDesc('id')->get();
        $selected = $request->filled('run') ? $runs->firstWhere('id', $request->integer('run')) : $runs->first();
        $selected?->load(['results.checklistVersion.checklist', 'results.criteria.criterion', 'results.criteria.evidence', 'results.author:id,name']);
        $user = $request->user();
        $synthetic = $selected ? (bool) TranscriptVersion::query()->whereKey($selected->transcript_version_id)->value('is_synthetic') : false;

        return response()->json([
            'runs' => $runs->map(fn (AnalysisRun $r) => [
                'id' => $r->id, 'status' => $r->status, 'driver' => $r->driver, 'started_at' => $r->started_at->toIso8601String(),
            ])->values(),
            'current' => $selected ? [
                'id' => $selected->id,
                'status' => $selected->status,
                'driver' => $selected->driver,
                'prompt_version' => $selected->prompt_version,
                'routing' => $selected->routing,
                'started_at' => $selected->started_at->toIso8601String(),
                'finished_at' => $selected->finished_at?->toIso8601String(),
                'error_message' => $selected->error_message,
                'synthetic' => $synthetic,
                'is_current_run' => $selected->id === $call->current_analysis_run_id,
                'results' => $selected->results->where('is_current', true)->map(fn (EvaluationResult $e) => [
                    ...$this->result($e),
                    'history' => $this->history($selected->results, $e),
                ])->values(),
            ] : null,
            'workflow' => $this->workflow($call),
            'review' => $this->review($call, $user),
            'appeals' => $this->appeals($call, $user),
            'can' => [
                'request_review' => $call->isVisibleTo($user, Permission::ReviewAssign),
                'appeal' => $call->isVisibleTo($user, Permission::AppealCreate),
                'confirm' => $call->isVisibleTo($user, Permission::EvaluationFinalize),
                'correct' => $call->isVisibleTo($user, Permission::EvaluationOverride),
                'edit_transcript' => $call->isVisibleTo($user, Permission::TranscriptEdit),
            ],
        ]);
    }

    /**
     * Последний проход бизнес-процесса: версия процесса и пройденные узлы.
     *
     * @return array<string, mixed>|null
     */
    private function workflow(Call $call): ?array
    {
        $run = WorkflowRun::query()->where('call_id', $call->id)->with(['version.definition', 'nodeRuns'])->latest('id')->first();
        if ($run === null) {
            return null;
        }
        $types = NodeTypes::all();
        $graphNodes = collect($run->version->graph['nodes'] ?? [])->keyBy('id');

        return [
            'id' => $run->id,
            'status' => $run->status,
            'name' => $run->version?->definition->name,
            'version' => $run->version?->version,
            'fallback' => $run->workflow_version_id === null,
            'started_at' => $run->started_at->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'error_message' => $run->error_message,
            'steps' => $run->nodeRuns->map(fn (WorkflowNodeRun $n) => [
                'node_id' => $n->node_id,
                'type' => $n->node_type,
                'label' => $graphNodes->get($n->node_id)['data']['label'] ?? $types[$n->node_type]['label'] ?? $n->node_type,
                'branch' => $n->branch,
            ])->values(),
        ];
    }

    /**
     * Ревизии результата по чек-листу: ИИ и исправления людей, с решениями.
     *
     * @param  Collection<int, EvaluationResult>  $all
     * @return list<array<string, mixed>>
     */
    private function history(Collection $all, EvaluationResult $current): array
    {
        $chain = $all->where('checklist_version_id', $current->checklist_version_id)->sortBy('revision');
        $decisions = ReviewDecision::query()->whereIn('evaluation_result_id', $chain->pluck('id'))->with('decider:id,name')->orderBy('id')->get();

        return $chain->map(fn (EvaluationResult $r) => [
            'id' => $r->id, 'revision' => $r->revision, 'origin' => $r->origin, 'score' => $r->score,
            'business_status' => $r->business_status, 'approval_state' => $r->approval_state,
            'author' => $r->author?->name, 'reason' => $r->change_reason, 'created_at' => $r->created_at->toIso8601String(),
            'decisions' => $decisions->where('evaluation_result_id', $r->id)->map(fn (ReviewDecision $d) => [
                'decision' => $d->decision, 'reason' => $d->reason, 'changes' => $d->changes, 'source' => $d->appeal_id ? 'appeal' : 'review',
                'decider' => $d->decider->name, 'created_at' => $d->created_at->toIso8601String(),
            ])->values()->all(),
        ])->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function review(Call $call, User $user): ?array
    {
        $review = ManualReview::query()->where('call_id', $call->id)->with(['assignee:id,name', 'resolver:id,name'])->latest('id')->first();

        return $review ? ReviewPresenter::review($review, $user) : null;
    }

    /** @return list<array<string, mixed>> */
    private function appeals(Call $call, User $user): array
    {
        return Appeal::query()->where('call_id', $call->id)->with(['author:id,name', 'assignee:id,name', 'resolver:id,name', 'result.checklistVersion.checklist'])
            ->orderByDesc('id')->get()
            ->filter(fn (Appeal $a) => $a->isVisibleTo($user, Permission::AppealCreate) || $a->isVisibleTo($user, Permission::AppealResolve) || $a->isVisibleTo($user, Permission::ReviewAssign))
            ->map(fn (Appeal $a) => ReviewPresenter::appeal($a, $user))->values()->all();
    }

    /** @return array<string, mixed> */
    private function result(EvaluationResult $e): array
    {
        $version = $e->checklistVersion;

        return [
            'id' => $e->id,
            'role' => $e->role,
            'origin' => $e->origin,
            'revision' => $e->revision,
            'author' => $e->author?->name,
            'change_reason' => $e->change_reason,
            'created_at' => $e->created_at->toIso8601String(),
            'checklist' => ['name' => $version->checklist->name, 'version' => $version->version, 'is_demo' => $version->checklist->is_demo],
            'score' => $e->score,
            'provisional_score' => $e->provisional_score,
            'critical_violation' => $e->critical_violation,
            'business_status' => $e->business_status,
            'review_state' => $e->review_state,
            'approval_state' => $e->approval_state,
            'summary' => $e->summary,
            'recommendations' => $e->recommendations,
            'risks' => $e->risks,
            'issues' => $e->issues,
            'criteria' => $e->criteria->sortBy(fn (CriterionResult $c) => $c->criterion->position)->map(fn (CriterionResult $c) => [
                'id' => $c->id,
                'title' => $c->criterion->title,
                'weight' => (float) $c->criterion->weight,
                'required' => $c->criterion->is_required,
                'critical' => $c->criterion->is_critical,
                'status' => $c->status,
                'reason' => $c->reason,
                'origin' => $c->origin,
                'previous_status' => $c->previous_status,
                'confidence' => $c->confidence !== null ? (float) $c->confidence : null,
                'evidence_valid' => $c->evidence_valid,
                'issues' => $c->issues,
                'evidence' => $c->evidence->map(fn (Evidence $ev) => [
                    'type' => $ev->type, 'segment_id' => $ev->transcript_segment_id, 'quote' => $ev->quote,
                    'start_ms' => $ev->start_ms, 'end_ms' => $ev->end_ms, 'speaker' => $ev->speaker,
                    'is_valid' => $ev->is_valid, 'issue' => $ev->issue,
                ])->values(),
            ])->values(),
        ];
    }
}
