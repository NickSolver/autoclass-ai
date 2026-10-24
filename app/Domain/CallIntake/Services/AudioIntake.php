<?php

namespace App\Domain\CallIntake\Services;

use App\Domain\Audio\AudioFormat;
use App\Domain\Audio\AudioInfo;
use App\Domain\Audio\AudioRejected;
use App\Domain\Audio\Models\AudioFile;
use App\Domain\Audio\Services\AudioProbe;
use App\Domain\CallIntake\Models\Call;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Приём файла записи — общий для ручной загрузки и подключаемых источников (ТЗ, разд. 4.1, 4.2):
 * сигнатура → ffprobe → длительность → хеш → приватное хранилище → запись audio_files.
 */
final class AudioIntake
{
    public function __construct(private readonly AudioProbe $probe) {}

    /**
     * @return array{format: AudioFormat, info: AudioInfo, sha256: string, size: int}
     *
     * @throws ValidationException ключ ошибки — file
     */
    public function inspect(string $path): array
    {
        $format = AudioFormat::sniff($path)
            ?? throw ValidationException::withMessages(['file' => 'Неподдерживаемый формат. Допустимы MP3, WAV и M4A.']);
        try {
            $info = $this->probe->probe($path, $format);
        } catch (AudioRejected $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage().($e->detail ? " ({$e->detail})" : '')]);
        }
        $this->checkDuration($info->durationMs);

        return ['format' => $format, 'info' => $info, 'sha256' => (string) hash_file('sha256', $path), 'size' => (int) filesize($path)];
    }

    /** @return array{disk: string, path: string} */
    public function store(string $path, string $uuid, AudioFormat $format): array
    {
        $disk = (string) config('calls.disk');
        $stored = now()->format('Y/m')."/{$uuid}/original.{$format->value}";
        Storage::disk($disk)->putFileAs(dirname($stored), new File($path), basename($stored));

        return ['disk' => $disk, 'path' => $stored];
    }

    public function discard(string $disk, string $path): void
    {
        Storage::disk($disk)->deleteDirectory(dirname($path));
    }

    /**
     * @param  array{format: AudioFormat, info: AudioInfo, sha256: string, size: int}  $inspected
     * @param  array{disk: string, path: string}  $stored
     */
    public function attach(Call $call, array $inspected, array $stored, ?string $originalName): AudioFile
    {
        $info = $inspected['info'];

        return $call->audioFiles()->create([
            'kind' => AudioFile::ORIGINAL,
            'disk' => $stored['disk'],
            'path' => $stored['path'],
            'original_name' => self::safeName((string) $originalName),
            'format' => $inspected['format']->value,
            'mime' => $inspected['format']->mime(),
            'size_bytes' => $inspected['size'],
            'sha256' => $inspected['sha256'],
            'duration_ms' => $info->durationMs,
            'codec' => $info->codec,
            'sample_rate' => $info->sampleRate,
            'channels' => $info->channels,
            'bit_rate' => $info->bitRate,
        ]);
    }

    public function findDuplicate(string $sha256): ?Call
    {
        $audio = AudioFile::query()->where('kind', AudioFile::ORIGINAL)->where('sha256', $sha256)->oldest('id')->first();

        return $audio?->call;
    }

    /** Имя файла только для отображения: без путей и управляющих символов. */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        return Str::limit($name !== '' ? $name : 'запись', 250, '');
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
}
