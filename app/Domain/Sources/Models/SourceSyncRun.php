<?php

namespace App\Domain\Sources\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Сеанс синхронизации: курсоры, счётчики и ошибка.
 *
 * @property int $id
 * @property int $source_connection_id
 * @property string $trigger
 * @property string $status
 * @property ?string $cursor_from
 * @property ?string $cursor_to
 * @property int $fetched
 * @property int $created
 * @property int $duplicates
 * @property int $without_recording
 * @property int $failed
 * @property ?string $error_message
 * @property ?int $triggered_by
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 * @property-read SourceConnection $connection
 */
class SourceSyncRun extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** @return BelongsTo<SourceConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SourceConnection::class, 'source_connection_id');
    }
}
