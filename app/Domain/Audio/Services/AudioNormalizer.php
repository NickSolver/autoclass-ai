<?php

namespace App\Domain\Audio\Services;

use App\Domain\Audio\AudioFormat;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Нормализованная копия для распознавания речи: моно, 16 кГц, PCM 16 бит (WAV).
 * Оригинал не изменяется.
 */
final class AudioNormalizer
{
    public function normalize(string $source, AudioFormat $format, string $target): void
    {
        $result = Process::timeout(600)->run([
            (string) config('calls.ffmpeg'),
            '-nostdin', '-hide_banner', '-v', 'error',
            '-protocol_whitelist', 'file',
            '-f', $format->demuxer(),
            '-i', $source,
            '-vn',
            '-ac', (string) config('calls.normalized.channels', 1),
            '-ar', (string) config('calls.normalized.sample_rate', 16000),
            '-c:a', 'pcm_s16le',
            '-f', 'wav',
            '-y', $target,
        ]);

        if (! $result->successful() || ! is_file($target) || filesize($target) === 0) {
            throw new RuntimeException('FFmpeg не смог подготовить запись: '.mb_substr(trim($result->errorOutput()), 0, 200));
        }
    }
}
