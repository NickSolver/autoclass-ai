<?php

namespace App\Domain\Sources\Data;

final readonly class SourceBatch
{
    /** @param list<array<string, mixed>> $payloads сырые записи источника; нормализует ядро через адаптер */
    public function __construct(public array $payloads, public ?string $nextCursor, public bool $hasMore) {}
}
