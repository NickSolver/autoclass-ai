<?php

namespace App\Domain\Transcription\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Версия расшифровки — неизменяема. Новая расшифровка или правка человеком — новая версия.
 *
 * @property int $id
 * @property int $call_id
 * @property ?int $transcription_run_id
 * @property int $version
 * @property string $source
 * @property bool $is_synthetic
 * @property ?int $created_by
 * @property ?string $reason
 * @property Carbon $created_at
 * @property-read Collection<int, TranscriptSegment> $segments
 */
class TranscriptVersion extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_synthetic' => 'boolean', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Версия расшифровки неизменяема.'));
    }

    /** @return HasMany<TranscriptSegment, $this> */
    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptSegment::class)->orderBy('position');
    }
}
