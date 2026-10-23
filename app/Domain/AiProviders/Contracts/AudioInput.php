<?php

namespace App\Domain\AiProviders\Contracts;

use App\Domain\AiProviders\ProviderException;
use Illuminate\Support\Facades\Process;

/**
 * Запись для распознавания: нормализованная копия (моно 16 кГц WAV) и оригинал.
 * Драйвер сам выбирает, что отправить: многоканальные провайдеры получают оригинальные каналы —
 * в телефонной записи сотрудник и клиент часто на разных каналах, и сведение в моно теряет
 * самое надёжное разделение говорящих.
 */
final class AudioInput
{
    public function __construct(
        public readonly string $workDir,
        public readonly string $normalizedPath,
        public readonly string $originalPath,
        public readonly string $originalFormat,
        public readonly int $originalChannels,
        public readonly int $durationMs,
    ) {}

    /**
     * Перекодирование для провайдера (ffmpeg, только локальные файлы).
     *
     * @param  'mp3'|'ogg_opus'|'pcm_s16le'|'wav'  $codec
     * @param  bool  $keepChannels  сохранить каналы оригинала (до 2), иначе — моно
     */
    public function encode(string $codec, int $sampleRate = 16000, bool $keepChannels = false, string $bitrate = '48k'): string
    {
        $channels = $keepChannels ? max(1, min(2, $this->originalChannels)) : 1;
        $source = $keepChannels ? $this->originalPath : $this->normalizedPath;
        $demuxer = $keepChannels ? ($this->originalFormat === 'm4a' ? 'mov' : $this->originalFormat) : 'wav';
        [$ext, $args] = match ($codec) {
            'mp3' => ['mp3', ['-c:a', 'libmp3lame', '-b:a', $bitrate, '-f', 'mp3']],
            'ogg_opus' => ['ogg', ['-c:a', 'libopus', '-b:a', $bitrate, '-f', 'ogg']],
            'pcm_s16le' => ['pcm', ['-c:a', 'pcm_s16le', '-f', 's16le']],
            'wav' => ['wav', ['-c:a', 'pcm_s16le', '-f', 'wav']],
        };
        $target = "{$this->workDir}/upload-{$channels}ch-{$sampleRate}.{$ext}";

        $result = Process::timeout(600)->run([
            (string) config('calls.ffmpeg'), '-nostdin', '-hide_banner', '-v', 'error',
            '-protocol_whitelist', 'file', '-f', $demuxer, '-i', $source,
            '-vn', '-ac', (string) $channels, '-ar', (string) $sampleRate, ...$args, '-y', $target,
        ]);
        if (! $result->successful() || ! is_file($target)) {
            throw new ProviderException(ProviderException::CONFIG_ERROR, 'Не удалось подготовить запись для провайдера.');
        }

        return $target;
    }

    public function channelsFor(bool $keepChannels): int
    {
        return $keepChannels ? max(1, min(2, $this->originalChannels)) : 1;
    }
}
