<?php

namespace App\Domain\Sources\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\CallIntake\AttributionStatus;
use App\Domain\CallIntake\CallStatus;
use App\Domain\CallIntake\Models\Call;
use App\Domain\CallIntake\Services\AudioIntake;
use App\Domain\CallIntake\Services\CallUploadService;
use App\Domain\CallIntake\Services\OrgSnapshot;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Models\EmployeeAssignment;
use App\Domain\Organization\Services\OrgPathResolver;
use App\Domain\Sources\Data\NormalizedCall;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Models\SourceEvent;
use App\Domain\Sources\SourceException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Импорт одного звонка источника (ТЗ, разд. 4.1): нормализация адаптером → идемпотентность по
 * source + подключение + external_id → сопоставление сотрудника по добавочному → запись → общий конвейер.
 * Звонки не склеиваются по номеру телефона. Без надёжного сопоставления сотрудник — «не установлен».
 */
final class SourceImporter
{
    public function __construct(
        private readonly SourceDriverRegistry $drivers,
        private readonly AudioIntake $audio,
        private readonly OrgPathResolver $paths,
        private readonly OrgSnapshot $snapshots,
        private readonly CallUploadService $pipeline,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $payload */
    public function import(SourceConnection $connection, array $payload, string $channel, ?int $syncRunId = null): SourceEvent
    {
        $event = SourceEvent::create([
            'source_connection_id' => $connection->id, 'source_sync_run_id' => $syncRunId, 'channel' => $channel,
            'external_id' => isset($payload['id']) && is_scalar($payload['id']) ? Str::limit((string) $payload['id'], 180, '') : null,
            'status' => 'pending', 'payload' => $payload, 'received_at' => now(),
        ]);

        return $this->process($connection, $event);
    }

    /** Обработка сохранённого события (pull — сразу, push — в очереди). */
    public function process(SourceConnection $connection, SourceEvent $event): SourceEvent
    {
        $driver = $this->drivers->driver($connection->source->code);
        try {
            $call = $driver->normalize((array) $event->payload);
        } catch (SourceException $e) {
            return $this->finish($event, 'failed', null, $e->getMessage());
        }
        $event->update(['external_id' => Str::limit($call->externalId, 180, '')]);
        $key = $this->key($connection, $call->externalId);

        if ($existing = $this->existing($connection, $key)) {
            return $this->finish($event, 'duplicate', $existing->id);
        }

        try {
            $recording = $call->recordingRefs !== [] ? $driver->resolveRecording($connection, $call) : null;
        } catch (SourceException $e) {
            return $this->finish($event, 'failed', null, "Запись не получена: {$e->getMessage()}");
        }

        try {
            if ($recording === null) {
                $created = $this->createCall($connection, $call, $key, null);

                return $this->finish($event, 'without_recording', $created->id, 'Источник не предоставил запись — звонок учтён без оценки.');
            }
            try {
                $inspected = $this->audio->inspect($recording->path);
            } catch (ValidationException $e) {
                return $this->finish($event, 'failed', null, 'Запись отклонена: '.($e->errors()['file'][0] ?? $e->getMessage()));
            }
            $created = $this->createCall($connection, $call, $key, ['inspected' => $inspected, 'path' => $recording->path, 'name' => $recording->name]);

            return $this->finish($event, 'imported', $created->id);
        } catch (UniqueConstraintViolationException) {
            // Параллельный импорт того же звонка (повтор события, одновременные синхронизации).
            return $this->finish($event, 'duplicate', $this->existing($connection, $key)?->id);
        } finally {
            if (isset($recording) && is_file($recording->path)) {
                @unlink($recording->path);
            }
        }
    }

    public function key(SourceConnection $connection, string $externalId): string
    {
        return Str::limit("{$connection->id}:{$externalId}", 191, '');
    }

    private function existing(SourceConnection $connection, string $key): ?Call
    {
        return Call::query()->where('source_id', $connection->source_id)->where('external_id', $key)->first();
    }

    /** @param array{inspected: array<string, mixed>, path: string, name: string}|null $audio */
    private function createCall(SourceConnection $connection, NormalizedCall $call, string $key, ?array $audio): Call
    {
        $employee = $this->employee($connection, $call->extensions);
        [$dealershipId, $departmentId] = $connection->department_id !== null || $employee === null
            ? [$connection->dealership_id, $connection->department_id]
            : $this->placement($employee, $connection);
        $path = $this->paths->fromUnits(null, $connection->company_id, $dealershipId, $departmentId);
        $uuid = (string) Str::uuid();
        $stored = $audio ? $this->audio->store($audio['path'], $uuid, $audio['inspected']['format']) : null;

        try {
            $created = DB::transaction(function () use ($connection, $call, $key, $audio, $employee, $path, $uuid, $stored) {
                $duplicate = $audio ? $this->audio->findDuplicate($audio['inspected']['sha256']) : null;
                $created = Call::create([
                    'uuid' => $uuid,
                    'source_id' => $connection->source_id,
                    'source_connection_id' => $connection->id,
                    'external_id' => $key,
                    'direction' => $call->direction,
                    'started_at' => $call->startedAt?->copy()->utc(),
                    'started_at_tz' => $call->timezone,
                    'duration_ms' => $audio ? $audio['inspected']['info']->durationMs : $call->durationMs,
                    'client_phone_masked' => self::maskPhone($call->clientPhone),
                    ...$path->toColumns(),
                    'employee_id' => $employee?->id,
                    'attribution_status' => $employee ? AttributionStatus::Resolved : AttributionStatus::NeedsEmployee,
                    'org_snapshot' => $this->snapshots->capture($path, $employee),
                    'note' => $call->metadata !== [] ? Str::limit(collect($call->metadata)->map(fn ($v, $k) => "{$k}: {$v}")->implode('; '), 1000) : null,
                    'status' => $audio ? CallStatus::Queued : CallStatus::AwaitingAudio,
                    'status_changed_at' => now(),
                    'last_error' => $audio ? null : 'Источник не предоставил запись.',
                    'duplicate_of_call_id' => $duplicate?->id,
                ]);
                if ($audio && $stored) {
                    $this->audio->attach($created, $audio['inspected'], $stored, $audio['name']);
                }
                $this->audit->record('call.imported', $created, [
                    'connection' => ['old' => null, 'new' => $connection->name],
                    'external_id' => ['old' => null, 'new' => $call->externalId],
                    'duplicate_of' => ['old' => null, 'new' => $duplicate?->id],
                ], path: $path);

                return $created;
            });
        } catch (Throwable $e) {
            if ($stored) {
                $this->audio->discard($stored['disk'], $stored['path']);
            }
            throw $e;
        }

        if ($audio) {
            $this->pipeline->dispatchPreparation($created);
        }

        return $created->refresh();
    }

    /**
     * Сотрудник по добавочному — только при однозначном совпадении в компании подключения.
     *
     * @param  list<string>  $extensions
     */
    private function employee(SourceConnection $connection, array $extensions): ?Employee
    {
        $extensions = array_values(array_filter(array_map('trim', $extensions)));
        if ($extensions === []) {
            return null;
        }
        $found = Employee::query()->where('company_id', $connection->company_id)->where('is_active', true)
            ->whereIn('phone_extension', $extensions)->limit(2)->get();

        return $found->count() === 1 ? $found->first() : null;
    }

    /** @return array{0: ?int, 1: ?int} центр и отдел по действующему назначению сотрудника */
    private function placement(Employee $employee, SourceConnection $connection): array
    {
        $assignment = EmployeeAssignment::query()->where('employee_id', $employee->id)->where('company_id', $connection->company_id)
            ->where('starts_on', '<=', today())->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', today()))
            ->orderByDesc('is_primary')->orderByDesc('starts_on')->first();

        return $assignment ? [$assignment->dealership_id, $assignment->department_id] : [$connection->dealership_id, $connection->department_id];
    }

    private function finish(SourceEvent $event, string $status, ?int $callId, ?string $error = null): SourceEvent
    {
        $event->update(['status' => $status, 'call_id' => $callId, 'error_message' => $error ? Str::limit($error, 490) : null]);

        return $event;
    }

    public static function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return $digits !== null && strlen($digits) >= 4 ? '•••'.substr($digits, -4) : null;
    }
}
