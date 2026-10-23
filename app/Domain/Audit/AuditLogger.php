<?php

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\OrgPath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Единая точка записи в журнал аудита. Никогда не принимает значения секретов:
 * вызывающий код обязан передавать только факт изменения чувствительных полей.
 */
final class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $changes  {field: {old, new}} либо произвольный контекст
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $changes = null,
        ?string $reason = null,
        ?OrgPath $path = null,
        ?User $actor = null,
    ): AuditEvent {
        $actor ??= Auth::user();
        $path ??= $subject !== null && method_exists($subject, 'orgPath') ? $subject->orgPath() : null;
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return AuditEvent::create([
            'occurred_at' => now(),
            'actor_user_id' => $actor?->id,
            'actor_login' => $actor?->login,
            'action' => $action,
            'subject_type' => $subject !== null ? self::subjectType($subject) : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subject !== null ? self::subjectLabel($subject) : null,
            'group_id' => $path?->groupId,
            'company_id' => $path?->companyId,
            'dealership_id' => $path?->dealershipId,
            'department_id' => $path?->departmentId,
            'changes' => $changes === [] ? null : $changes,
            'reason' => $reason !== null ? Str::limit($reason, 497) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request !== null ? Str::limit((string) $request->userAgent(), 497) : null,
        ]);
    }

    public static function subjectType(Model $model): string
    {
        return method_exists($model, 'auditSubjectType')
            ? $model->auditSubjectType()
            : Str::snake(class_basename($model));
    }

    private static function subjectLabel(Model $model): ?string
    {
        $label = method_exists($model, 'auditLabel') ? $model->auditLabel() : $model->getAttribute('name');

        return $label !== null ? Str::limit((string) $label, 250) : null;
    }
}
