<?php

namespace App\Domain\AiProviders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Запись журнала вызова провайдера: только метаданные (без аудио, текста и ответа модели).
 *
 * @property int $id
 * @property ?int $provider_config_id
 * @property ?int $call_id
 * @property string $kind
 * @property string $driver
 * @property ?string $model
 * @property string $operation
 * @property ?string $prompt_version
 * @property ?string $input_hash
 * @property Carbon $started_at
 * @property int $duration_ms
 * @property string $status
 * @property ?string $error_code
 * @property ?string $error_message
 * @property int $attempt
 * @property ?string $audio_seconds
 * @property ?int $tokens_in
 * @property ?int $tokens_out
 * @property ?string $cost
 * @property string $currency
 */
class ProviderCall extends Model
{
    public $timestamps = false;

    protected $table = 'ai_provider_calls';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime'];
    }

    /** @return BelongsTo<ProviderConfig, $this> */
    public function config(): BelongsTo
    {
        return $this->belongsTo(ProviderConfig::class, 'provider_config_id');
    }
}
