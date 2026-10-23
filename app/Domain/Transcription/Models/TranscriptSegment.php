<?php

namespace App\Domain\Transcription\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $transcript_version_id
 * @property int $position
 * @property int $start_ms
 * @property int $end_ms
 * @property string $speaker
 * @property string $speaker_role
 * @property string $text
 * @property ?string $confidence
 */
class TranscriptSegment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_ms' => 'integer', 'end_ms' => 'integer', 'position' => 'integer'];
    }
}
