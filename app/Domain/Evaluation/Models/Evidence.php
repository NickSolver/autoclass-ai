<?php

namespace App\Domain\Evaluation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Доказательство по критерию (ТЗ, разд. 7.2). Сохраняется и недействительное — с причиной,
 * чтобы проверяющий видел, что именно модель «придумала».
 *
 * @property int $id
 * @property int $criterion_result_id
 * @property string $type
 * @property ?int $transcript_segment_id
 * @property ?string $quote
 * @property ?int $start_ms
 * @property ?int $end_ms
 * @property ?string $speaker
 * @property bool $is_valid
 * @property ?string $issue
 */
class Evidence extends Model
{
    public $timestamps = false;

    protected $table = 'evidence';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_valid' => 'boolean', 'start_ms' => 'integer', 'end_ms' => 'integer'];
    }
}
