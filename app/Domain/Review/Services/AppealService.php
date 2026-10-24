<?php

namespace App\Domain\Review\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Review\Models\Appeal;
use App\Domain\Review\Models\ReviewDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Апелляции (ТЗ, разд. 11): сотрудник или руководитель с правом подаёт обращение на пересмотр оценки;
 * решает независимый контролёр — не автор, не сотрудник звонка и не тот, кто выставил оспариваемую ревизию.
 * Принятая апелляция — исправление (новая ревизия), отклонённая — решение с причиной.
 */
final class AppealService
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly ReviewService $reviews,
        private readonly AuditLogger $audit,
    ) {}

    public function create(User $actor, Call $call, EvaluationResult $result, string $reason): Appeal
    {
        if (! $call->isVisibleTo($actor, Permission::AppealCreate)) {
            throw new AuthorizationException('Нет права подавать апелляцию по этому звонку.');
        }
        if ($result->call_id !== $call->id || ! $result->is_current || $result->analysis_run_id !== $call->current_analysis_run_id) {
            throw ValidationException::withMessages(['result_id' => 'Апелляция подаётся на действующий результат.']);
        }
        if (Appeal::query()->where('evaluation_result_id', $result->id)->whereIn('status', Appeal::OPEN)->exists()) {
            throw ValidationException::withMessages(['result_id' => 'По этому результату уже есть открытая апелляция.']);
        }
        $appeal = Appeal::create([
            'call_id' => $call->id, 'evaluation_result_id' => $result->id,
            'group_id' => $call->group_id, 'company_id' => $call->company_id, 'dealership_id' => $call->dealership_id,
            'department_id' => $call->department_id, 'employee_id' => $call->employee_id,
            'created_by' => $actor->id, 'reason' => $reason, 'status' => 'open',
        ]);
        $this->audit->record('appeal.created', $appeal, null, $reason, $call->orgPath());

        return $appeal;
    }

    public function assign(User $actor, Appeal $appeal, User $assignee, int $lockVersion): Appeal
    {
        $this->authorize($actor, Permission::ReviewAssign, $appeal);
        $this->assertOpen($appeal);
        if (! $assignee->is_active || ! $this->authorizer->covers($assignee, Permission::AppealResolve, $appeal->orgPath())) {
            throw ValidationException::withMessages(['assignee_id' => 'У пользователя нет права решать апелляции в этом подразделении.']);
        }
        if ($reason = $this->conflict($assignee, $appeal)) {
            throw ValidationException::withMessages(['assignee_id' => $reason]);
        }
        $old = $appeal->assignee_id;
        $this->locked($appeal, $lockVersion, ['assignee_id' => $assignee->id, 'assigned_by' => $actor->id, 'status' => 'open']);
        $this->audit->record('appeal.assigned', $appeal, ['assignee_id' => ['old' => $old, 'new' => $assignee->id]], path: $appeal->orgPath());

        return $appeal->refresh();
    }

    public function take(User $actor, Appeal $appeal, int $lockVersion): Appeal
    {
        $this->authorize($actor, Permission::AppealResolve, $appeal);
        $this->assertOpen($appeal);
        if ($reason = $this->conflict($actor, $appeal)) {
            throw new AuthorizationException($reason);
        }
        if ($appeal->assignee_id !== null && $appeal->assignee_id !== $actor->id) {
            throw ValidationException::withMessages(['appeal' => 'Апелляция назначена другому пользователю.']);
        }
        $this->locked($appeal, $lockVersion, ['status' => 'in_review', 'assignee_id' => $actor->id]);
        $this->audit->record('appeal.taken', $appeal, path: $appeal->orgPath());

        return $appeal->refresh();
    }

    public function withdraw(User $actor, Appeal $appeal, int $lockVersion): Appeal
    {
        if ($appeal->created_by !== $actor->id) {
            throw new AuthorizationException('Отозвать апелляцию может только её автор.');
        }
        $this->assertOpen($appeal);
        $this->locked($appeal, $lockVersion, ['status' => 'withdrawn', 'resolved_at' => now()]);
        $this->audit->record('appeal.withdrawn', $appeal, path: $appeal->orgPath());

        return $appeal->refresh();
    }

    /**
     * @param  list<array{criterion_result_id: int, status: string, comment: string}>  $changes
     */
    public function resolve(User $actor, Appeal $appeal, string $decision, array $changes, string $comment, int $lockVersion): Appeal
    {
        $this->authorize($actor, Permission::AppealResolve, $appeal);
        if ($appeal->status !== 'in_review' || $appeal->assignee_id !== $actor->id) {
            throw ValidationException::withMessages(['appeal' => 'Сначала возьмите апелляцию в работу.']);
        }
        if ($reason = $this->conflict($actor, $appeal)) {
            throw new AuthorizationException($reason);
        }
        if (! in_array($decision, ['accepted', 'rejected'], true)) {
            throw ValidationException::withMessages(['decision' => 'Решение — «принять» или «отклонить».']);
        }

        DB::transaction(function () use ($actor, $appeal, $decision, $changes, $comment, $lockVersion) {
            $this->locked($appeal, $lockVersion, []);
            if ($decision === 'accepted') {
                // Исправляется действующая ревизия (результат мог быть уточнён после подачи апелляции).
                $current = EvaluationResult::query()->where('analysis_run_id', $appeal->result->analysis_run_id)
                    ->where('checklist_version_id', $appeal->result->checklist_version_id)->where('is_current', true)->firstOrFail();
                $this->reviews->decide($actor, $current, 'corrected', $changes, $comment, ['appeal_id' => $appeal->id]);
            } else {
                ReviewDecision::create([
                    'appeal_id' => $appeal->id, 'evaluation_result_id' => $appeal->evaluation_result_id, 'decision' => 'rejected',
                    'reason' => $comment, 'changes' => [], 'decided_by' => $actor->id, 'created_at' => now(),
                ]);
            }
            $appeal->update(['status' => $decision, 'resolved_by' => $actor->id, 'resolved_at' => now(), 'resolution_comment' => $comment]);
        });
        $this->audit->record("appeal.{$decision}", $appeal, ['status' => ['old' => 'in_review', 'new' => $decision]], $comment, $appeal->orgPath());

        return $appeal->refresh();
    }

    /** Причина, по которой пользователь не может решать апелляцию (независимость), или null. */
    public function conflict(User $user, Appeal $appeal): ?string
    {
        if ($appeal->created_by === $user->id) {
            return 'Нельзя решать собственную апелляцию.';
        }
        if ($user->employee_id !== null && $user->employee_id === $appeal->employee_id) {
            return 'Нельзя решать апелляцию по собственному звонку.';
        }
        $result = $appeal->result;
        if ($result->created_by === $user->id || ReviewDecision::query()->where('evaluation_result_id', $result->id)->where('decided_by', $user->id)->exists()) {
            return 'Апелляцию решает не тот, кто выставил оспариваемую оценку.';
        }

        return null;
    }

    private function authorize(User $actor, Permission $permission, Appeal $appeal): void
    {
        if (! $this->authorizer->covers($actor, $permission, $appeal->orgPath())) {
            throw new AuthorizationException('Недостаточно прав для этого действия.');
        }
    }

    private function assertOpen(Appeal $appeal): void
    {
        if (! $appeal->isOpen()) {
            throw ValidationException::withMessages(['appeal' => 'Апелляция уже закрыта.']);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function locked(Appeal $appeal, int $lockVersion, array $attributes): void
    {
        $updated = Appeal::query()->whereKey($appeal->id)->where('lock_version', $lockVersion)
            ->update([...$attributes, 'lock_version' => $lockVersion + 1, 'updated_at' => now()]);
        if ($updated === 0) {
            throw ValidationException::withMessages(['lock_version' => 'Апелляцию изменил другой пользователь. Обновите страницу.']);
        }
        $appeal->refresh();
    }
}
