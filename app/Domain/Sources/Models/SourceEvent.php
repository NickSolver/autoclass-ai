<?php

namespace App\Domain\Sources\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Полученное событие источника и результат его импорта. Сырой payload — только зашифрованным
 * (ТЗ, разд. 4.1) и наружу не отдаётся.
 *
 * @property int $id
 * @property int $source_connection_id
 * @property ?int $source_sync_run_id
 * @property string $channel
 * @property ?string $external_id
 * @property string $status
 * @property ?int $call_id
 * @property ?array<string, mixed> $payload
 * @property ?string $error_message
 * @property Carbon $received_at
 */
class SourceEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'received_at' => 'datetime'];
    }
}
