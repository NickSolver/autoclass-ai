<?php

namespace App\Domain\CallIntake\Services;

use App\Domain\Audio\AudioFormat;
use App\Domain\Audio\AudioRejected;
use App\Domain\Audio\Models\AudioFile;
use App\Domain\Audio\Services\AudioProbe;
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
use Illuminate\Support\Facades\Storage;
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
        private readonly AudioProbe $probe,
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

        $tmp = (string) $file->getRealPath();
        $format = AudioFormat::sniff($tmp)
            ?? throw ValidationException::withMessages(['file' => 'Неподдерживаемый формат. Допустимы MP3, WAV и M4A.']);

        try {
            $info = $this->probe->probe($tmp, $format);
        } catch (AudioRejected $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage().($e->detail ? " ({$e->detail})" : '')]);
        }
        $this->checkDuration($info->durationMs);

        $sha256 = (string) hash_file('sha256', $tmp);
        $duplicate = $this->findDuplicate($sha256);
        if ($duplicate !== null && ! $confirmDuplicate) {
            throw new DuplicateAudio($duplicate);
        }

        $uuid = (string) Str::uuid();
        $disk = (string) config('calls.disk');
        $storedPath = now()->format('Y/m')."/{$uuid}/original.{$format->value}";
        Storage::disk($disk)->putFileAs(dirname($storedPath), $file, basename($storedPath));

        try {
            $call = DB::transaction(function () use ($actor, $file, $meta, $idempotencyKey, $source, $path, $employee, $format, $info, $sha256, $duplicate, $uuid, $disk, $storedPath) {
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

                $call->audioFiles()->create([
                    'kind' => AudioFile::ORIGINAL,
                    'disk' => $disk,
                    'path' => $storedPath,
                    'original_name' => $this->safeName($file->getClientOriginalName()),
                    'format' => $format->value,
                    'mime' => $format->mime(),
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => $sha256,
                    'duration_ms' => $info->durationMs,
                    'codec' => $info->codec,
                    'sample_rate' => $info->sampleRate,
                    'channels' => $info->channels,
                    'bit_rate' => $info->bitRate,
                ]);

                $this->audit->record('call.uploaded', $call, [
                    'file' => $this->safeName($file->getClientOriginalName()),
                    'duration_ms' => $info->durationMs,
                    'duplicate_of' => $duplicate?->id,
                ]);

                return $call;
            });
        } catch (UniqueConstraintViolationException) {
            // Параллельный запрос с тем же ключом успел первым.
            Storage::disk($disk)->deleteDirectory(dirname($storedPath));
            $existing = $this->findByKey($source, $idempotencyKey, $actor);
            if ($existing === null) {
                throw new ConflictHttpException('Повторите загрузку.');
            }

            return ['call' => $existing, 'created' => false];
        } catch (Throwable $e) {
            Storage::disk($disk)->deleteDirectory(dirname($storedPath));
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

    private function findDuplicate(string $sha256): ?Call
    {
        $audio = AudioFile::query()->where('kind', AudioFile::ORIGINAL)->where('sha256', $sha256)->oldest('id')->first();

        return $audio?->call;
    }

    private function checkDuration(int $durationMs): void
    {
        $min = (int) config('calls.min_duration_seconds', 1) * 1000;
        $max = (int) config('calls.max_duration_minutes', 120) * 60_000;
        if ($durationMs < $min) {
            throw ValidationException::withMessages(['file' => 'Запись слишком короткая (минимум '.config('calls.min_duration_seconds').' с).']);
        }
        if ($durationMs > $max) {
            throw ValidationException::withMessages(['file' => 'Запись слишком длинная (максимум '.config('calls.max_duration_minutes').' мин).']);
        }
    }

    /** Имя файла только для отображения: без путей и управляющих символов. */
    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return Str::limit($name !== '' ? $name : 'запись', 250, '');
    }
}
