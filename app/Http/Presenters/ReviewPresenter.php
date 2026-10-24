<?php

namespace App\Http\Presenters;

use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Review\Models\Appeal;
use App\Domain\Review\Models\ManualReview;
use App\Domain\Review\Services\AppealService;

/** Представление проверок и апелляций для API: данные и доступные текущему пользователю действия. */
final class ReviewPresenter
{
    public const SOURCES = ['ai' => 'Оценка ИИ', 'workflow' => 'Бизнес-процесс', 'manual' => 'Вручную', 'appeal' => 'Апелляция'];

    /** @return array<string, mixed> */
    public static function review(ManualReview $r, User $user): array
    {
        $auth = app(Authorizer::class);
        $path = $r->orgPath();
        $open = $r->isOpen();

        return [
            'id' => $r->id, 'call_id' => $r->call_id, 'status' => $r->status, 'source' => $r->source,
            'source_label' => self::SOURCES[$r->source] ?? $r->source, 'reason' => $r->reason,
            'assignee' => $r->assignee ? ['id' => $r->assignee->id, 'name' => $r->assignee->name] : null,
            'resolver' => $r->resolver?->name, 'resolution' => $r->resolution, 'resolution_comment' => $r->resolution_comment,
            'created_at' => $r->created_at->toIso8601String(), 'resolved_at' => $r->resolved_at?->toIso8601String(),
            'lock_version' => $r->lock_version,
            'can' => [
                'assign' => $open && $auth->covers($user, Permission::ReviewAssign, $path),
                'cancel' => $open && $auth->covers($user, Permission::ReviewAssign, $path),
                'take' => $open && $r->status === 'pending' && ($r->assignee_id === null || $r->assignee_id === $user->id)
                    && $auth->covers($user, Permission::ReviewResolve, $path),
                'resolve' => $r->status === 'in_progress' && $r->assignee_id === $user->id && $auth->covers($user, Permission::ReviewResolve, $path),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function appeal(Appeal $a, User $user): array
    {
        $auth = app(Authorizer::class);
        $path = $a->orgPath();
        $open = $a->isOpen();
        $conflict = app(AppealService::class)->conflict($user, $a);
        $version = $a->result->checklistVersion;

        return [
            'id' => $a->id, 'call_id' => $a->call_id, 'status' => $a->status, 'reason' => $a->reason,
            'result' => ['id' => $a->evaluation_result_id, 'checklist' => $version->checklist->name, 'version' => $version->version, 'role' => $a->result->role, 'score' => $a->result->score],
            'author' => $a->author->name, 'assignee' => $a->assignee ? ['id' => $a->assignee->id, 'name' => $a->assignee->name] : null,
            'resolver' => $a->resolver?->name, 'resolution_comment' => $a->resolution_comment,
            'created_at' => $a->created_at->toIso8601String(), 'resolved_at' => $a->resolved_at?->toIso8601String(),
            'lock_version' => $a->lock_version,
            'conflict' => $open ? $conflict : null,
            'can' => [
                'assign' => $open && $auth->covers($user, Permission::ReviewAssign, $path),
                'take' => $open && $a->status === 'open' && $conflict === null && ($a->assignee_id === null || $a->assignee_id === $user->id)
                    && $auth->covers($user, Permission::AppealResolve, $path),
                'resolve' => $a->status === 'in_review' && $a->assignee_id === $user->id && $conflict === null && $auth->covers($user, Permission::AppealResolve, $path),
                'withdraw' => $open && $a->created_by === $user->id,
            ],
        ];
    }
}
