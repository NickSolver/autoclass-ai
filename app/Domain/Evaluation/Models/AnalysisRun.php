<?php

namespace App\Domain\Evaluation\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Неизменяемый запуск анализа (ТЗ, разд. 5 п. 10): расшифровка, маршрут, провайдер, результаты.
 *
 * @property int $id
 * @property int $call_id
 * @property int $transcript_version_id
 * @property ?int $provider_config_id
 * @property string $driver
 * @property string $status
 * @property string $prompt_version
 * @property array<string, mixed> $routing
 * @property Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?string $error_message
 * @property-read Collection<int, EvaluationResult> $results
 */
class AnalysisRun extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['routing' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** @return HasMany<EvaluationResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(EvaluationResult::class)->orderByRaw("role = 'primary' desc")->orderBy('id');
    }
}
