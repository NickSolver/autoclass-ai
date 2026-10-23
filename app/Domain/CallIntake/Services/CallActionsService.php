<?php

namespace App\Domain\CallIntake\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\CallIntake\AttributionStatus;
use App\Domain\CallIntake\CallDirection;
use App\Domain\CallIntake\CallStatus;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Services\OrgPathResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CallActionsService
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly OrgPathResolver $paths,
        private readonly OrgSnapshot $snapshots,
        private readonly CallUploadService $uploads,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Повторная обработка — только явным действием, с аудитом. Уже идущая обработка не дублируется.
     */
    public function reprocess(User $actor, Call $call): Call
    {
        $updated = DB::transaction(function () use ($call) {
            $locked = Call::query()->lockForUpdate()->findOrFail($call->id);
            if ($locked->status->isInProgress()) {
                return false;
            }
            $locked->forceFill([
                'status' => CallStatus::Queued,
                'status_changed_at' => now(),
                'last_error' => null,
                'excluded_reason' => null,
                'excluded_at' => null,
                'excluded_by' => null,
            ])->save();

            return true;
        });

        if (! $updated) {
            throw new ConflictHttpException('Звонок уже обрабатывается.');
        }

        $this->audit->record('call.reprocess_requested', $call, ['previous_status' => $call->status->value]);
        $this->uploads->dispatchPreparation($call);

        return $call->refresh();
    }

    /** Исключение из обработки (запись сохраняется). Удаление — по политике хранения, не здесь. */
    public function exclude(User $actor, Call $call, string $reason): Call
    {
        if ($call->status === CallStatus::Excluded) {
            return $call;
        }
        $previous = $call->status;
        $call->forceFill([
            'status' => CallStatus::Excluded,
            'status_changed_at' => now(),
            'excluded_reason' => $reason,
            'excluded_at' => now(),
            'excluded_by' => $actor->id,
        ])->save();
        $this->audit->record('call.excluded', $call, ['previous_status' => $previous->value], $reason);

        return $call;
    }

    /**
     * Уточнение подразделения, сотрудника и метаданных. Нужны права и на текущее, и на новое подразделение.
     * Снимок оргструктуры пересоздаётся на момент исправления — старый сохраняется в аудите.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, Call $call, array $data): Call
    {
        $changes = [];
        $path = $call->orgPath();

        if (array_intersect_key($data, array_flip(['company_id', 'dealership_id', 'department_id'])) !== []) {
            $path = $this->paths->fromUnits(null, $data['company_id'] ?? null, $data['dealership_id'] ?? null, $data['department_id'] ?? null);
            if (! $this->authorizer->covers($actor, Permission::CallsUpload, $path)) {
                throw new AuthorizationException('Нет права переносить звонок в это подразделение.');
            }
        }

        $employeeId = array_key_exists('employee_id', $data) ? $data['employee_id'] : $call->employee_id;
        $employee = null;
        if ($employeeId !== null) {
            $employee = Employee::query()->visibleTo($actor, Permission::CallsUpload)->find($employeeId)
                ?? throw ValidationException::withMessages(['employee_id' => 'Сотрудник не найден в вашей области.']);
        }

        $attributes = [];
        if (array_key_exists('direction', $data)) {
            $attributes['direction'] = CallDirection::from((string) $data['direction']);
        }
        if (array_key_exists('started_at', $data)) {
            $attributes['started_at'] = $data['started_at'] ? Carbon::parse($data['started_at'])->utc() : null;
            $attributes['started_at_tz'] = $data['started_at_tz'] ?? null;
        }
        if (array_key_exists('note', $data)) {
            $attributes['note'] = $data['note'];
        }

        $orgChanged = $path != $call->orgPath() || $employee?->id !== $call->employee_id;
        if ($orgChanged) {
            $changes['org_snapshot'] = ['old' => $call->org_snapshot, 'new' => null];
            $attributes += [
                ...$path->toColumns(),
                'employee_id' => $employee?->id,
                'attribution_status' => $employee ? AttributionStatus::Resolved : AttributionStatus::NeedsEmployee,
                'org_snapshot' => $this->snapshots->capture($path, $employee),
            ];
            $changes['org_snapshot']['new'] = $attributes['org_snapshot'];
        }

        foreach ($attributes as $key => $value) {
            if ($key !== 'org_snapshot' && $call->getAttribute($key) != $value) {
                $old = $call->getAttribute($key);
                $changes[$key] = [
                    'old' => $old instanceof \BackedEnum ? $old->value : ($old instanceof Carbon ? $old->toIso8601String() : $old),
                    'new' => $value instanceof \BackedEnum ? $value->value : ($value instanceof Carbon ? $value->toIso8601String() : $value),
                ];
            }
        }

        $call->forceFill($attributes)->save();
        if ($changes !== []) {
            $this->audit->record('call.updated', $call, $changes);
        }

        return $call->refresh();
    }
}
