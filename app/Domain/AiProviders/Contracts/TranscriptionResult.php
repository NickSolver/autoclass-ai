<?php

namespace App\Domain\AiProviders\Contracts;

final readonly class TranscriptionResult
{
    /**
     * @param  list<TranscriptSegmentData>  $segments
     * @param  bool  $synthetic  true — текст не распознан из записи (тестовый провайдер)
     */
    public function __construct(
        public array $segments,
        public ?string $language,
        public float $billedSeconds,
        public bool $synthetic = false,
    ) {}
}
