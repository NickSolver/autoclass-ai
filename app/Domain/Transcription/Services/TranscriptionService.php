<?php

namespace App\Domain\Transcription\Services;

use App\Domain\AiProviders\Contracts\AudioInput;
use App\Domain\AiProviders\Contracts\TranscriptionResult;
use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderException;
use App\Domain\AiProviders\Services\DriverRegistry;
use App\Domain\AiProviders\Services\ProviderInvoker;
use App\Domain\Audio\Models\AudioFile;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Transcription\Models\TranscriptionRun;
use App\Domain\Transcription\Models\TranscriptVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Распознавание нормализованной записи. Ответ провайдера проверяется до сохранения: таймкоды
 * должны лежать в пределах записи и идти по порядку (приёмка C: «таймкоды проверяемы»).
 */
final class TranscriptionService
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly ProviderInvoker $invoker,
    ) {}

    /** @throws ProviderException */
    public function transcribe(Call $call, ProviderConfig $config, int $attempt): TranscriptVersion
    {
        $call->loadMissing(['normalized', 'original']);
        $audio = $call->normalized ?? throw new ProviderException(ProviderException::CONFIG_ERROR, 'Нет подготовленной записи для распознавания.');
        $run = TranscriptionRun::create([
            'call_id' => $call->id, 'provider_config_id' => $config->id, 'driver' => $config->driver,
            'status' => 'running', 'started_at' => now(),
        ]);

        // Провайдеру нужны локальные файлы: копии во временном каталоге, удаляются после вызова.
        $original = $call->original ?? throw new ProviderException(ProviderException::CONFIG_ERROR, 'Нет исходной записи.');
        $workDir = storage_path('app/tmp/asr-'.Str::uuid());
        @mkdir($workDir, 0o700, true);
        try {
            $input = new AudioInput(
                workDir: $workDir,
                normalizedPath: $this->copy($audio, "{$workDir}/normalized.wav"),
                originalPath: $this->copy($original, "{$workDir}/original.{$original->format}"),
                originalFormat: $original->format,
                originalChannels: (int) ($original->channels ?? 1),
                durationMs: $audio->duration_ms,
            );
            $driver = $this->drivers->speechToText($config->driver);
            /** @var TranscriptionResult $result */
            $result = $this->invoker->invoke(
                $config, 'transcribe',
                fn () => $driver->transcribe($config, $input),
                fn (TranscriptionResult $r) => ['audio_seconds' => $r->billedSeconds],
                ['call_id' => $call->id, 'input_hash' => $audio->sha256, 'attempt' => $attempt],
            );
            $this->validate($result, $audio->duration_ms);
        } catch (ProviderException $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => Str::limit($e->getMessage(), 490)]);
            throw $e;
        } finally {
            array_map('unlink', glob("{$workDir}/*") ?: []);
            @rmdir($workDir);
        }

        return DB::transaction(function () use ($call, $run, $result) {
            $run->update(['status' => 'succeeded', 'finished_at' => now(), 'language' => $result->language]);
            $version = TranscriptVersion::create([
                'call_id' => $call->id,
                'transcription_run_id' => $run->id,
                'version' => (int) TranscriptVersion::query()->where('call_id', $call->id)->max('version') + 1,
                'source' => 'asr',
                'is_synthetic' => $result->synthetic,
                'created_at' => now(),
            ]);
            foreach ($result->segments as $i => $s) {
                $version->segments()->create([
                    'position' => $i,
                    'start_ms' => $s->startMs,
                    'end_ms' => $s->endMs,
                    'speaker' => Str::limit($s->speaker, 16, ''),
                    'speaker_role' => in_array($s->speakerRole, ['agent', 'client'], true) ? $s->speakerRole : 'unknown',
                    'text' => $s->text,
                    'confidence' => $s->confidence,
                ]);
            }

            return $version;
        });
    }

    private function copy(AudioFile $file, string $target): string
    {
        file_put_contents($target, Storage::disk($file->disk)->readStream($file->path));

        return $target;
    }

    /** @throws ProviderException */
    private function validate(TranscriptionResult $result, int $durationMs): void
    {
        $tolerance = (int) config('analysis.timecode_tolerance_ms', 500);
        $previousStart = -1;
        foreach ($result->segments as $i => $s) {
            $n = $i + 1;
            if ($s->startMs < 0 || $s->endMs <= $s->startMs) {
                throw new ProviderException(ProviderException::BAD_RESPONSE, "Провайдер вернул некорректный отрезок времени в реплике {$n}.");
            }
            if ($s->endMs > $durationMs + $tolerance) {
                throw new ProviderException(ProviderException::BAD_RESPONSE, "Таймкод реплики {$n} выходит за длительность записи.");
            }
            if ($s->startMs < $previousStart) {
                throw new ProviderException(ProviderException::BAD_RESPONSE, "Реплики расшифровки идут не по порядку (реплика {$n}).");
            }
            if (trim($s->text) === '') {
                throw new ProviderException(ProviderException::BAD_RESPONSE, "Пустой текст в реплике {$n}.");
            }
            $previousStart = $s->startMs;
        }
    }
}
