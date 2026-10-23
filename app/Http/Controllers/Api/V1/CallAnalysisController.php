<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\AnalysisRun;
use App\Domain\Evaluation\Models\CriterionResult;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Evaluation\Models\Evidence;
use App\Domain\Identity\Permission;
use App\Domain\Transcription\Models\TranscriptSegment;
use App\Domain\Transcription\Models\TranscriptVersion;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
                'segments' => $selected->segments->map(fn (TranscriptSegment $s) => [
                    'id' => $s->id, 'start_ms' => $s->start_ms, 'end_ms' => $s->end_ms,
                    'speaker' => $s->speaker, 'speaker_role' => $s->speaker_role, 'text' => $s->text,
                    'confidence' => $s->confidence !== null ? (float) $s->confidence : null,
                ])->values(),
            ] : null,
        ]);
    }

    public function analysis(Request $request, Call $call): JsonResponse
    {
        abort_unless($call->isVisibleTo($request->user(), Permission::EvaluationView), 403, 'Нет права просматривать оценки.');

        $runs = AnalysisRun::query()->where('call_id', $call->id)->orderByDesc('id')->get();
        $selected = $request->filled('run') ? $runs->firstWhere('id', $request->integer('run')) : $runs->first();
        $selected?->load(['results.checklistVersion.checklist', 'results.criteria.criterion', 'results.criteria.evidence']);
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
                'results' => $selected->results->map(fn (EvaluationResult $e) => $this->result($e))->values(),
            ] : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function result(EvaluationResult $e): array
    {
        $version = $e->checklistVersion;

        return [
            'id' => $e->id,
            'role' => $e->role,
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
