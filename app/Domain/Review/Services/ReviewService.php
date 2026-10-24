<?php

namespace App\Domain\Review\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\AnalysisRun;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Evaluation\Services\RevisionService;
use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Organization\OrgPath;
use App\Domain\Review\Models\Appeal;
use App\Domain\Review\Models\ManualReview;
use App\Domain\Review\Models\ReviewDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ручная проверка (ТЗ, разд. 11): задача создаётся автоматически («Требуется вмешательство человека»
 * или задача бизнес-процесса) либо вручную; контролёр берёт её, подтверждает или исправляет результаты.
 * Исправление — новая ревизия; результат ИИ не изменяется.
 */
final class ReviewService
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly RevisionService $revisions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * После обработки звонка: открытые задачи по прежним анализам закрываются,
     * при необходимости проверки создаётся задача по текущему.
     */
    public function syncAfterProcessing(Call $call, ?AnalysisRun $run): ?ManualReview
    {
        return DB::transaction(function () use ($call, $run) {
            $call->refresh();
            ManualReview::query()->where('call_id', $call->id)->whereIn('status', ManualReview::OPEN)
                ->where(fn ($q) => $q->whereNull('analysis_run_id')->orWhere('analysis_run_id', '!=', $run->id ?? 0))
                ->get()
                ->each(fn (ManualReview $r) => $r->update([
                    'status' => 'cancelled', 'resolution' => 'cancelled', 'resolved_at' => now(),
                    'resolution_comment' => 'Звонок обработан повторно — проверяется новый результат.',
                ]));

            if (! $call->review_required) {
                return null;
            }
            $existing = ManualReview::query()->where('call_id', $call->id)->whereIn('status', ManualReview::OPEN)->first();
            if ($existing !== null) {
                return $existing;
            }
            $intervention = $run !== null && $run->results()->where('business_status', EvaluationResult::INTERVENTION)->exists();

            return $this->open($call, $run, $intervention ? 'ai' : 'workflow', (string) ($call->review_reason ?? 'Требуется проверка.'), null);
        });
    }

    /** Закрытие открытых проверок звонка (например, при исключении из обработки). */
    public function cancelOpen(Call $call, string $reason, ?User $actor): void
    {
        DB::transaction(function () use ($call, $reason, $actor) {
            ManualReview::query()->where('call_id', $call->id)->whereIn('status', ManualReview::OPEN)->get()
                ->each(function (ManualReview $r) use ($reason, $actor) {
                    $r->update(['status' => 'cancelled', 'resolution' => 'cancelled', 'resolved_by' => $actor?->id, 'resolved_at' => now(), 'resolution_comment' => mb_substr($reason, 0, 1000)]);
                    $this->audit->record('review.cancelled', $r, null, $reason, $r->orgPath(), $actor);
                });
            $call->forceFill(['review_required' => false])->save();
        });
    }

    /** Перенос звонка в другое подразделение: проверки и апелляции получают новый путь. */
    public function followCall(Call $call): void
    {
        $columns = [
            'group_id' => $call->group_id, 'company_id' => $call->company_id, 'dealership_id' => $call->dealership_id,
            'department_id' => $call->department_id, 'employee_id' => $call->employee_id,
        ];
        ManualReview::query()->where('call_id', $call->id)->update($columns);
        Appeal::query()->where('call_id', $call->id)->update($columns);
    }

    /** Отправка на проверку вручную (кнопка в карточке звонка). */
    public function request(User $actor, Call $call, string $reason): ManualReview
    {
        $this->authorize($actor, Permission::ReviewAssign, $call->orgPath());
        if (ManualReview::query()->where('call_id', $call->id)->whereIn('status', ManualReview::OPEN)->exists()) {
            throw ValidationException::withMessages(['reason' => 'По звонку уже есть открытая проверка.']);
        }
        $runId = $call->current_analysis_run_id;

        return DB::transaction(function () use ($actor, $call, $reason, $runId) {
            $call->forceFill(['review_required' => true, 'review_reason' => mb_substr($reason, 0, 500)])->save();

            return $this->open($call, $runId ? AnalysisRun::query()->find($runId) : null, 'manual', $reason, $actor);
        });
    }

    public function assign(User $actor, ManualReview $review, User $assignee, int $lockVersion): ManualReview
    {
        $this->authorize($actor, Permission::ReviewAssign, $review->orgPath());
        $this->assertOpen($review);
        if (! $assignee->is_active || ! $this->authorizer->covers($assignee, Permission::ReviewResolve, $review->orgPath())) {
            throw ValidationException::withMessages(['assignee_id' => 'У пользователя нет права решать проверки в этом подразделении.']);
        }
        $old = $review->assignee_id;
        $this->locked($review, $lockVersion, [
            'assignee_id' => $assignee->id, 'assigned_by' => $actor->id, 'assigned_at' => now(),
            // Переназначение возвращает задачу в ожидание: новый исполнитель берёт её сам.
            'status' => 'pending',
        ]);
        $this->audit->record('review.assigned', $review, ['assignee_id' => ['old' => $old, 'new' => $assignee->id]], path: $review->orgPath());

        return $review->refresh();
    }

    public function take(User $actor, ManualReview $review, int $lockVersion): ManualReview
    {
        $this->authorize($actor, Permission::ReviewResolve, $review->orgPath());
        $this->assertOpen($review);
        if ($review->assignee_id !== null && $review->assignee_id !== $actor->id) {
            throw ValidationException::withMessages(['review' => 'Проверка назначена другому пользователю.']);
        }
        $this->locked($review, $lockVersion, ['status' => 'in_progress', 'assignee_id' => $actor->id, 'assigned_at' => $review->assigned_at ?? now()]);
        $review->call->currentResults()->update(['review_state' => 'in_progress']);
        $this->audit->record('review.taken', $review, path: $review->orgPath());

        return $review->refresh();
    }

    /**
     * Решение по каждому итоговому результату анализа: подтвердить или исправить критерии.
     *
     * @param  list<array{result_id: int, decision: string, changes?: list<array{criterion_result_id: int, status: string, comment: string}>}>  $decisions
     */
    public function resolve(User $actor, ManualReview $review, array $decisions, string $comment, int $lockVersion): ManualReview
    {
        $this->authorize($actor, Permission::ReviewResolve, $review->orgPath());
        if ($review->status !== 'in_progress' || $review->assignee_id !== $actor->id) {
            throw ValidationException::withMessages(['review' => 'Сначала возьмите проверку в работу.']);
        }
        $results = $review->call->currentResults()->get()->keyBy('id');
        $byResult = collect($decisions)->keyBy('result_id');
        if ($results->isNotEmpty() && $byResult->keys()->sort()->values()->all() !== $results->keys()->sort()->values()->all()) {
            throw ValidationException::withMessages(['decisions' => 'Нужно решение по каждому чек-листу звонка.']);
        }

        DB::transaction(function () use ($actor, $review, $results, $byResult, $comment, $lockVersion) {
            $this->locked($review, $lockVersion, []);
            $corrected = false;
            foreach ($results as $result) {
                $d = $byResult->get($result->id);
                $corrected = $this->decide($actor, $result, (string) $d['decision'], $d['changes'] ?? [], $comment, ['manual_review_id' => $review->id]) || $corrected;
            }
            $review->update([
                'status' => 'resolved', 'resolution' => $corrected ? 'corrected' : 'confirmed',
                'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_comment' => $comment,
            ]);
            $this->closeCallFlag($review->call);
        });
        $this->audit->record('review.resolved', $review, ['resolution' => ['old' => null, 'new' => $review->resolution]], $comment, $review->orgPath());

        return $review->refresh();
    }

    public function cancel(User $actor, ManualReview $review, string $reason, int $lockVersion): ManualReview
    {
        $this->authorize($actor, Permission::ReviewAssign, $review->orgPath());
        $this->assertOpen($review);
        DB::transaction(function () use ($actor, $review, $reason, $lockVersion) {
            $this->locked($review, $lockVersion, [
                'status' => 'cancelled', 'resolution' => 'cancelled', 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_comment' => $reason,
            ]);
            $this->closeCallFlag($review->call);
        });
        $this->audit->record('review.cancelled', $review, null, $reason, $review->orgPath());

        return $review->refresh();
    }

    /**
     * Подтверждение или исправление одного результата; общий шаг для проверок и апелляций.
     *
     * @param  list<array{criterion_result_id: int, status: string, comment: string}>  $changes
     * @param  array<string, int>  $link  manual_review_id или appeal_id
     * @return bool было ли исправление
     */
    public function decide(User $actor, EvaluationResult $result, string $decision, array $changes, string $reason, array $link): bool
    {
        $path = $result->call()->firstOrFail()->orgPath();
        if ($decision === 'confirmed') {
            $this->authorize($actor, Permission::EvaluationFinalize, $path);
            $this->revisions->confirm($result);
            ReviewDecision::create([...$link, 'evaluation_result_id' => $result->id, 'decision' => 'confirmed', 'reason' => $reason, 'changes' => [], 'decided_by' => $actor->id, 'created_at' => now()]);
            $this->audit->record('evaluation.confirmed', $result, null, $reason, $path);

            return false;
        }
        if ($decision !== 'corrected') {
            throw ValidationException::withMessages(['decisions' => 'Решение — «подтвердить» или «исправить».']);
        }
        $this->authorize($actor, Permission::EvaluationOverride, $path);
        $corrected = $this->revisions->correct($actor, $result, $changes, $reason);
        ReviewDecision::create([
            ...$link, 'evaluation_result_id' => $result->id, 'new_evaluation_result_id' => $corrected['result']->id,
            'decision' => 'corrected', 'reason' => $reason, 'changes' => $corrected['diff'], 'decided_by' => $actor->id, 'created_at' => now(),
        ]);
        $this->audit->record('evaluation.corrected', $corrected['result'], [
            'score' => ['old' => $corrected['before'], 'new' => $corrected['after']],
            'criteria' => ['old' => array_map(fn ($d) => "{$d['code']}: {$d['from']}", $corrected['diff']), 'new' => array_map(fn ($d) => "{$d['code']}: {$d['to']}", $corrected['diff'])],
        ], $reason, $path);

        return true;
    }

    /**
     * Пользователи, которым можно поручить проверку в этом подразделении.
     *
     * @return Collection<int, User>
     */
    public function assignees(OrgPath $path, Permission $permission = Permission::ReviewResolve): Collection
    {
        return User::query()->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (User $u) => $this->authorizer->covers($u, $permission, $path))->values();
    }

    private function open(Call $call, ?AnalysisRun $run, string $source, string $reason, ?User $actor): ManualReview
    {
        $review = ManualReview::create([
            'call_id' => $call->id, 'group_id' => $call->group_id, 'company_id' => $call->company_id,
            'dealership_id' => $call->dealership_id, 'department_id' => $call->department_id, 'employee_id' => $call->employee_id,
            'analysis_run_id' => $run?->id, 'source' => $source, 'reason' => mb_substr($reason, 0, 500), 'status' => 'pending',
            'created_by' => $actor?->id,
        ]);
        if ($run !== null) {
            $run->results()->where('is_current', true)->update(['review_state' => 'pending']);
        }
        $this->audit->record('review.created', $review, ['source' => ['old' => null, 'new' => $source]], $reason, $call->orgPath(), $actor);

        return $review;
    }

    private function closeCallFlag(Call $call): void
    {
        if (! ManualReview::query()->where('call_id', $call->id)->whereIn('status', ManualReview::OPEN)->exists()) {
            $call->forceFill(['review_required' => false])->save();
        }
    }

    private function assertOpen(ManualReview $review): void
    {
        if (! $review->isOpen()) {
            throw ValidationException::withMessages(['review' => 'Проверка уже закрыта.']);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function locked(ManualReview $review, int $lockVersion, array $attributes): void
    {
        $updated = ManualReview::query()->whereKey($review->id)->where('lock_version', $lockVersion)
            ->update([...$attributes, 'lock_version' => $lockVersion + 1, 'updated_at' => now()]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['lock_version' => 'Проверку изменил другой пользователь. Обновите страницу.']);
        }
        $review->refresh();
    }

    private function authorize(User $actor, Permission $permission, OrgPath $path): void
    {
        if (! $this->authorizer->covers($actor, $permission, $path)) {
            throw new AuthorizationException('Недостаточно прав для этого действия.');
        }
    }
}
