<?php

namespace App\Domain\Audio\Models;

use App\Domain\CallIntake\Models\Call;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Аудиофайл звонка в приватном хранилище. Оригинал неизменяем; нормализованная копия
 * пересоздаётся при повторной обработке.
 *
 * @property int $id
 * @property int $call_id
 * @property string $kind
 * @property string $disk
 * @property string $path
 * @property ?string $original_name
 * @property string $format
 * @property string $mime
 * @property int $size_bytes
 * @property string $sha256
 * @property int $duration_ms
 * @property ?string $codec
 * @property ?int $sample_rate
 * @property ?int $channels
 * @property ?int $bit_rate
 */
class AudioFile extends Model
{
    public const ORIGINAL = 'original';

    public const NORMALIZED = 'normalized';

    protected $fillable = [
        'call_id', 'kind', 'disk', 'path', 'original_name', 'format', 'mime', 'size_bytes', 'sha256',
        'duration_ms', 'codec', 'sample_rate', 'channels', 'bit_rate',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer', 'duration_ms' => 'integer', 'sample_rate' => 'integer',
            'channels' => 'integer', 'bit_rate' => 'integer',
        ];
    }

    /** @return BelongsTo<Call, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
