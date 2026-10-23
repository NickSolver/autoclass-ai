<?php

namespace App\Domain\CallIntake\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $call_id
 * @property string $step
 * @property int $attempt
 * @property string $status
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?string $error_code
 * @property ?string $error_message
 * @property ?int $triggered_by
 */
class CallProcessingAttempt extends Model
{
    public const UPDATED_AT = null;

    public const RUNNING = 'running';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected $fillable = ['call_id', 'step', 'attempt', 'status', 'started_at', 'finished_at', 'error_code', 'error_message', 'triggered_by'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** @return BelongsTo<Call, $this> */
    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
