<?php

namespace App\Domain\Audio\Services;

use App\Domain\Audio\AudioFormat;
use App\Domain\Audio\AudioInfo;
use App\Domain\Audio\AudioRejected;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Проверка технической читаемости записи через ffprobe. Формат задаётся явно,
 * доступ ffmpeg — только к локальному файлу (без сетевых протоколов и плейлистов).
 */
final class AudioProbe
{
    public function probe(string $path, AudioFormat $format): AudioInfo
    {
        $result = Process::timeout(60)->run([
            (string) config('calls.ffprobe'),
            '-v', 'error',
            '-protocol_whitelist', 'file',
            '-f', $format->demuxer(),
            '-print_format', 'json',
            '-show_format', '-show_streams',
            $path,
        ]);

        if (! $result->successful()) {
            throw new AudioRejected('unreadable', 'Файл повреждён или не читается как аудиозапись.', $this->diagnostic($result->errorOutput(), $path));
        }

        /** @var array{format?: array<string, mixed>, streams?: list<array<string, mixed>>} $data */
        $data = json_decode($result->output(), true) ?: [];
        $streams = $data['streams'] ?? [];
        $audio = collect($streams)->firstWhere('codec_type', 'audio');
        $hasVideo = collect($streams)->contains(fn (array $s) => ($s['codec_type'] ?? null) === 'video'
            && ($s['disposition']['attached_pic'] ?? 0) !== 1);

        if ($audio === null) {
            throw new AudioRejected('no_audio', 'В файле нет звуковой дорожки.');
        }
        if ($hasVideo) {
            throw new AudioRejected('has_video', 'Файл содержит видео. Загрузите только аудиозапись (MP3, WAV, M4A).');
        }

        $duration = (float) ($data['format']['duration'] ?? $audio['duration'] ?? 0);
        if (! is_finite($duration) || $duration <= 0) {
            throw new AudioRejected('no_duration', 'Не удалось определить длительность записи: файл повреждён или пуст.');
        }

        return new AudioInfo(
            format: $format,
            durationMs: (int) round($duration * 1000),
            codec: isset($audio['codec_name']) ? (string) $audio['codec_name'] : null,
            sampleRate: isset($audio['sample_rate']) ? (int) $audio['sample_rate'] : null,
            channels: isset($audio['channels']) ? (int) $audio['channels'] : null,
            bitRate: isset($data['format']['bit_rate']) ? (int) $data['format']['bit_rate'] : null,
        );
    }

    /** Первая строка ошибки ffprobe без путей сервера. */
    private function diagnostic(string $stderr, string $path): ?string
    {
        $line = trim(Str::before(trim($stderr), "\n"));
        $line = str_replace([$path, dirname($path)], ['<файл>', ''], $line);

        return $line === '' ? null : Str::limit($line, 200);
    }
}
