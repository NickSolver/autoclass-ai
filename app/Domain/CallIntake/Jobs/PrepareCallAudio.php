<?php

namespace App\Domain\CallIntake\Jobs;

use App\Domain\Audio\AudioFormat;
use App\Domain\Audio\Models\AudioFile;
use App\Domain\Audio\Services\AudioNormalizer;
use App\Domain\Audio\Services\AudioProbe;
use App\Domain\CallIntake\CallStatus;
use App\Domain\CallIntake\Exceptions\PermanentFailure;
use App\Domain\CallIntake\Models\Call;
use App\Domain\CallIntake\Models\CallProcessingAttempt;
use App\Domain\CallIntake\Services\CallPipeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Шаг конвейера «подготовка аудио»: нормализованная копия для распознавания (ТЗ, разд. 5 п. 4).
 * Идемпотентна: повторный запуск перезаписывает копию и не создаёт новых сущностей;
 * звонок, который уже не ждёт подготовки (исключён, готов), пропускается.
 */
final class PrepareCallAudio implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const STEP = 'prepare';

    public int $tries = 3;

    public int $timeout = 900;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $callId, public readonly ?int $triggeredBy = null)
    {
        $this->onQueue('audio');
    }

    public function uniqueId(): string
    {
        return (string) $this->callId;
    }

    public function handle(AudioNormalizer $normalizer, AudioProbe $probe): void
    {
        $call = Call::query()->with('original')->find($this->callId);
        if ($call === null || ! in_array($call->status, [CallStatus::Queued, CallStatus::Preparing], true)) {
            return;
        }

        $attempt = $call->attempts()->create([
            'step' => self::STEP,
            'attempt' => $call->attempts()->where('step', self::STEP)->count() + 1,
            'status' => CallProcessingAttempt::RUNNING,
            'started_at' => now(),
            'triggered_by' => $this->triggeredBy,
        ]);
        $call->transitionTo(CallStatus::Preparing);

        $workDir = storage_path('app/tmp/'.Str::uuid());
        try {
            $original = $call->original ?? throw new PermanentFailure('missing_original', 'Исходная запись не найдена.');
            $disk = Storage::disk($original->disk);
            if (! $disk->exists($original->path)) {
                throw new PermanentFailure('missing_file', 'Файл исходной записи отсутствует в хранилище.');
            }

            @mkdir($workDir, 0o700, true);
            $source = "{$workDir}/source.{$original->format}";
            $target = "{$workDir}/normalized.wav";
            file_put_contents($source, $disk->readStream($original->path));

            $normalizer->normalize($source, AudioFormat::from($original->format), $target);
            $info = $probe->probe($target, AudioFormat::Wav);

            $path = dirname($original->path).'/normalized.wav';
            $disk->putFileAs(dirname($path), new File($target), basename($path));

            AudioFile::query()->updateOrCreate(
                ['call_id' => $call->id, 'kind' => AudioFile::NORMALIZED],
                [
                    'disk' => $original->disk,
                    'path' => $path,
                    'original_name' => null,
                    'format' => AudioFormat::Wav->value,
                    'mime' => AudioFormat::Wav->mime(),
                    'size_bytes' => (int) filesize($target),
                    'sha256' => (string) hash_file('sha256', $target),
                    'duration_ms' => $info->durationMs,
                    'codec' => $info->codec,
                    'sample_rate' => $info->sampleRate,
                    'channels' => $info->channels,
                    'bit_rate' => $info->bitRate,
                ],
            );

            $attempt->update(['status' => CallProcessingAttempt::SUCCEEDED, 'finished_at' => now()]);
            // Дальше — распознавание, если провайдер включён; иначе звонок ждёт его.
            app(CallPipeline::class)->afterPrepared($call);
        } catch (Throwable $e) {
            $permanent = $e instanceof PermanentFailure;
            $message = Str::limit(str_replace([$workDir, storage_path()], '', $e->getMessage()), 490);
            $attempt->update([
                'status' => CallProcessingAttempt::FAILED,
                'finished_at' => now(),
                'error_code' => $permanent ? $e->errorCode : 'processing_error',
                'error_message' => $message,
            ]);

            if ($permanent || $this->attempts() >= $this->tries) {
                $call->transitionTo(CallStatus::Failed, $message);
                $permanent ? $this->fail($e) : throw $e;

                return;
            }
            // Будет повтор: звонок возвращается в очередь с последней ошибкой.
            $call->transitionTo(CallStatus::Queued, $message);
            throw $e;
        } finally {
            if (is_dir($workDir)) {
                array_map('unlink', glob("{$workDir}/*") ?: []);
                @rmdir($workDir);
            }
        }
    }

    /** Задача окончательно не выполнилась (в т. ч. таймаут) — звонок не теряется, видна причина. */
    public function failed(?Throwable $e): void
    {
        $call = Call::query()->find($this->callId);
        if ($call !== null && $call->status !== CallStatus::Failed && $call->status !== CallStatus::Excluded) {
            $call->transitionTo(CallStatus::Failed, Str::limit($e?->getMessage() ?? 'Задача не выполнена.', 490));
        }
    }
}
