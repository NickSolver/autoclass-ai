<?php

namespace App\Domain\Evaluation\Services;

use App\Domain\Evaluation\Models\CriterionResult;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Правка оценки человеком (ТЗ, разд. 11): новая ревизия результата, прежняя остаётся как была.
 * Итоговой считается последняя ревизия (is_current). Оценка пересчитывается тем же правилом v1.
 */
final class RevisionService
{
    public const STATUSES = ['pass', 'fail', 'not_applicable', 'insufficient_data'];

    public function __construct(private readonly ScoreCalculator $scores) {}

    /** Подтверждение без правок: ревизия не создаётся, отмечается решение человека. */
    public function confirm(EvaluationResult $current): void
    {
        if (! $current->is_current) {
            throw ValidationException::withMessages(['result' => 'Результат уже исправлен — обновите страницу.']);
        }
        $current->update(['review_state' => 'resolved', 'approval_state' => 'human_confirmed']);
    }

    /**
     * @param  list<array{criterion_result_id: int, status: string, comment: string}>  $changes
     * @return array{result: EvaluationResult, diff: list<array<string, mixed>>, before: ?int, after: ?int}
     */
    public function correct(User $actor, EvaluationResult $current, array $changes, string $reason): array
    {
        if (! $current->is_current) {
            throw ValidationException::withMessages(['result' => 'Результат уже исправлен — обновите страницу.']);
        }
        $current->loadMissing(['criteria.criterion', 'criteria.evidence', 'checklistVersion']);
        $byId = $current->criteria->keyBy('id');
        $changed = [];
        foreach ($changes as $change) {
            $criterion = $byId->get($change['criterion_result_id'])
                ?? throw ValidationException::withMessages(['changes' => 'Критерий не относится к этому результату.']);
            if (! in_array($change['status'], self::STATUSES, true)) {
                throw ValidationException::withMessages(['changes' => 'Недопустимый статус критерия.']);
            }
            if (trim($change['comment']) === '') {
                throw ValidationException::withMessages(['changes' => "Укажите причину изменения критерия «{$criterion->criterion->title}»."]);
            }
            if ($change['status'] !== $criterion->status) {
                $changed[$criterion->id] = $change;
            }
        }
        if ($changed === []) {
            throw ValidationException::withMessages(['changes' => 'Нет изменений: для подтверждения без правок выберите «Подтвердить».']);
        }

        return DB::transaction(function () use ($actor, $current, $changed, $reason) {
            // Защита от параллельной правки: снимаем признак итоговой только у ещё итоговой ревизии.
            if (EvaluationResult::query()->whereKey($current->id)->where('is_current', true)->update(['is_current' => false]) === 0) {
                throw ValidationException::withMessages(['result' => 'Результат уже исправлен — обновите страницу.']);
            }

            $items = [];
            $diff = [];
            foreach ($current->criteria as $c) {
                $change = $changed[$c->id] ?? null;
                $status = $change['status'] ?? $c->status;
                $items[] = [
                    'weight' => (float) $c->criterion->weight, 'status' => $status, 'required' => $c->criterion->is_required,
                    'critical' => $c->criterion->is_critical,
                    // Решение человека не требует цитаты модели.
                    'evidence_valid' => $change !== null ? true : $c->evidence_valid,
                ];
                if ($change !== null) {
                    $diff[] = ['code' => $c->criterion->code, 'title' => $c->criterion->title, 'from' => $c->status, 'to' => $status, 'comment' => $change['comment']];
                }
            }
            $score = $this->scores->calculate($items, $current->checklistVersion->zero_on_critical);

            $next = EvaluationResult::create([
                'analysis_run_id' => $current->analysis_run_id,
                'call_id' => $current->call_id,
                'checklist_version_id' => $current->checklist_version_id,
                'role' => $current->role,
                'origin' => 'human',
                'revision' => $current->revision + 1,
                'supersedes_id' => $current->id,
                'is_current' => true,
                'score' => $score['score'],
                'provisional_score' => $score['provisional_score'],
                'critical_violation' => $score['critical_violation'],
                'business_status' => $score['business_status'],
                'review_state' => 'resolved',
                'approval_state' => 'human_overridden',
                'summary' => $current->summary,
                'recommendations' => $current->recommendations,
                'risks' => $current->risks,
                'issues' => $score['notes'],
                'created_by' => $actor->id,
                'change_reason' => $reason,
                'created_at' => now(),
            ]);

            foreach ($current->criteria as $c) {
                $change = $changed[$c->id] ?? null;
                /** @var CriterionResult $copy */
                $copy = $next->criteria()->create([
                    'checklist_criterion_id' => $c->checklist_criterion_id,
                    'status' => $change['status'] ?? $c->status,
                    'origin' => $change !== null ? 'human' : $c->origin,
                    'previous_status' => $change !== null ? $c->status : $c->previous_status,
                    'reason' => $change['comment'] ?? $c->reason,
                    'confidence' => $change !== null ? null : $c->confidence,
                    'evidence_valid' => $change !== null ? true : $c->evidence_valid,
                    'issues' => $change !== null ? [] : $c->issues,
                ]);
                foreach ($c->evidence as $e) {
                    $copy->evidence()->create($e->only(['type', 'transcript_segment_id', 'quote', 'start_ms', 'end_ms', 'speaker', 'is_valid', 'issue']));
                }
            }

            $current->update(['review_state' => 'resolved', 'approval_state' => 'human_overridden']);

            return ['result' => $next->load('criteria'), 'diff' => $diff, 'before' => $current->score, 'after' => $next->score];
        });
    }
}
