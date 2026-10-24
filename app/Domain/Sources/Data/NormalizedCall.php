<?php

namespace App\Domain\Sources\Data;

use App\Domain\CallIntake\CallDirection;
use Illuminate\Support\Carbon;

/** Нормализованный звонок источника (ТЗ, разд. 4.1). */
final readonly class NormalizedCall
{
    /**
     * @param  list<string>  $extensions  добавочные / идентификаторы участников со стороны компании
     * @param  list<string>  $recordingRefs  ссылки адаптера на записи (не URL для клиента)
     * @param  array<string, mixed>  $metadata  несекретные метаданные (кампания, тег и т. п.)
     * @param  array<string, mixed>  $raw  исходный payload — хранится только зашифрованным
     */
    public function __construct(
        public string $externalId,
        public CallDirection $direction,
        public ?Carbon $startedAt,
        public ?string $timezone,
        public ?int $durationMs,
        public ?string $clientPhone,
        public array $extensions,
        public array $recordingRefs,
        public array $metadata,
        public array $raw,
    ) {}
}
