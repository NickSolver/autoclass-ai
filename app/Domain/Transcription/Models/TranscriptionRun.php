<?php

namespace App\Domain\Transcription\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $call_id
 * @property ?int $provider_config_id
 * @property string $driver
 * @property string $status
 * @property ?string $language
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?string $error_message
 */
class TranscriptionRun extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
