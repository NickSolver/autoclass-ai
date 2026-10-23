<?php

namespace App\Domain\Audio;

/** Допустимые форматы записей (ТЗ, разд. 4.2). */
enum AudioFormat: string
{
    case Mp3 = 'mp3';
    case Wav = 'wav';
    case M4a = 'm4a';

    /** Демультиплексор ffmpeg — задаётся явно, чтобы файл не мог «назваться» плейлистом (ADR-0003). */
    public function demuxer(): string
    {
        return match ($this) {
            self::Mp3 => 'mp3',
            self::Wav => 'wav',
            self::M4a => 'mov',
        };
    }

    public function mime(): string
    {
        return match ($this) {
            self::Mp3 => 'audio/mpeg',
            self::Wav => 'audio/wav',
            self::M4a => 'audio/mp4',
        };
    }

    /**
     * Определение формата по сигнатуре содержимого (не по расширению и не по заявленному MIME).
     */
    public static function sniff(string $path): ?self
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $head = (string) fread($handle, 12);
        fclose($handle);

        if (strlen($head) < 12) {
            return null;
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE') {
            return self::Wav;
        }
        if (substr($head, 4, 4) === 'ftyp') {
            return self::M4a;
        }
        if (str_starts_with($head, 'ID3') || (ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0)) {
            return self::Mp3;
        }

        return null;
    }
}
