<?php

namespace App\Domain\Audio;

final readonly class AudioInfo
{
    public function __construct(
        public AudioFormat $format,
        public int $durationMs,
        public ?string $codec,
        public ?int $sampleRate,
        public ?int $channels,
        public ?int $bitRate,
    ) {}
}
