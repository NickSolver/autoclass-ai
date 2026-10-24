<?php

namespace App\Domain\CallIntake\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\CallIntake\AttributionStatus;
use App\Domain\CallIntake\CallDirection;
use App\Domain\CallIntake\CallStatus;
use App\Domain\CallIntake\Exceptions\DuplicateAudio;
use App\Domain\CallIntake\Jobs\PrepareCallAudio;
use App\Domain\CallIntake\Models\Call;
use App\Domain\CallIntake\Models\CallSource;
use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Services\OrgPathResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Ручная загрузка записи звонка (ТЗ, разд. 4.2; ADR-0003).
 * Порядок: идемпотентность → права на подразделение → сигнатура → ffprobe → дубли → хранилище → очередь.
 */
final class CallUploadService
{
    public function __construct(
        private readonly Authorizer $authorizer,
        private readonly OrgPathResolver $paths,
        private readonly AudioIntake $audio,
        private readonly OrgSnapshot $snapshots,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{company_id?: ?int, dealership_id?: ?int, department_id?: ?int, employee_id?: ?int,
     *     direction?: ?string, started_at?: ?string, started_at_tz?: ?string, note?: ?string}  $meta
     * @return array{call: Call, created: bool}
     */
    public function upload(User $actor, UploadedFile $file, array $meta, string $idempotencyKey, bool $confirmDuplicate): array
    {
        $source = CallSource::manual();

        // Повтор того же запроса (обрыв связи, двойная отправка) возвращает уже созданный звонок.
        if ($existing = $this->findByKey($source, $idempotencyKey, $actor)) {
            return ['call' => $existing, 'created' => false];
        }

        $path = $this->paths->fromUnits(null, $meta['company_id'] ?? null, $meta['dealership_id'] ?? null, $meta['department_id'] ?? null);
        if (! $this->authorizer->covers($actor, Permission::CallsUpload, $path)) {
            throw new AuthorizationException('Нет права загружать звонки в это подразделение.');
        }

        $employee = null;
        if (! empty($meta['employee_id'])) {
            $employee = Employee::query()->visibleTo($actor, Permission::CallsUpload)->find($meta['employee_id'])
                ?? throw ValidationException::withMessages(['employee_id' => 'Сотрудник не найден в вашей области.']);
        }

        $inspected = $this->audio->inspect((string) $file->getRealPath());
        $info = $inspected['info'];
        $duplicate = $this->audio->findDuplicate($inspected['sha256']);
        if ($duplicate !== null && ! $confirmDuplicate) {
            throw new DuplicateAudio($duplicate);
        }

        $uuid = (string) Str::uuid();
        $stored = $this->audio->store((string) $file->getRealPath(), $uuid, $inspected['format']);

        try {
            $call = DB::transaction(function () use ($actor, $file, $meta, $idempotencyKey, $source, $path, $employee, $inspected, $info, $duplicate, $uuid, $stored) {
                $call = Call::create([
                    'uuid' => $uuid,
                    'source_id' => $source->id,
                    'external_id' => $idempotencyKey,
                    'direction' => CallDirection::tryFrom((string) ($meta['direction'] ?? '')) ?? CallDirection::Unknown,
                    'started_at' => isset($meta['started_at']) ? Carbon::parse($meta['started_at'])->utc() : null,
                    'started_at_tz' => $meta['started_at_tz'] ?? null,
                    'duration_ms' => $info->durationMs,
                    ...$path->toColumns(),
                    'employee_id' => $employee?->id,
                    'attribution_status' => $employee ? AttributionStatus::Resolved : AttributionStatus::NeedsEmployee,
                    'org_snapshot' => $this->snapshots->capture($path, $employee),
                    'note' => $meta['note'] ?? null,
                    'status' => CallStatus::Queued,
                    'status_changed_at' => now(),
                    'duplicate_of_call_id' => $duplicate?->id,
                    'uploaded_by' => $actor->id,
                ]);

                $this->audio->attach($call, $inspected, $stored, $file->getClientOriginalName());

                $this->audit->record('call.uploaded', $call, [
                    'file' => AudioIntake::safeName($file->getClientOriginalName()),
                    'duration_ms' => $info->durationMs,
                    'duplicate_of' => $duplicate?->id,
                ]);

                return $call;
            });
        } catch (UniqueConstraintViolationException) {
            // Параллельный запрос с тем же ключом успел первым.
            $this->audio->discard($stored['disk'], $stored['path']);
            $existing = $this->findByKey($source, $idempotencyKey, $actor);
            if ($existing === null) {
                throw new ConflictHttpException('Повторите загрузку.');
            }

            return ['call' => $existing, 'created' => false];
        } catch (Throwable $e) {
            $this->audio->discard($stored['disk'], $stored['path']);
            throw $e;
        }

        $this->dispatchPreparation($call);

        // refresh: occurred_at вычисляет БД, статус мог измениться синхронной обработкой.
        return ['call' => $call->refresh(), 'created' => true];
    }

    public function dispatchPreparation(Call $call): void
    {
        try {
            PrepareCallAudio::dispatch($call->id);
        } catch (Throwable $e) {
            // Синхронная очередь (тесты, стенд e2e) пробрасывает ошибку задачи; статус и причину уже сохранила задача.
            Log::warning('Подготовка аудио не выполнена', ['call_id' => $call->id, 'error' => $e->getMessage()]);
        }
    }

    private function findByKey(CallSource $source, string $key, User $actor): ?Call
    {
        $call = Call::query()->where('source_id', $source->id)->where('external_id', $key)->first();
        if ($call !== null && $call->uploaded_by !== $actor->id) {
            throw new ConflictHttpException('Ключ идемпотентности уже использован другим пользователем.');
        }

        return $call;
    }
}
