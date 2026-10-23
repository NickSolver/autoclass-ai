<?php

namespace App\Domain\AiProviders\Contracts;

final readonly class TranscriptSegmentData
{
    public function __construct(
        public int $startMs,
        public int $endMs,
        public string $speaker,
        public string $text,
        public ?float $confidence = null,
        public string $speakerRole = 'unknown',
    ) {}
}
