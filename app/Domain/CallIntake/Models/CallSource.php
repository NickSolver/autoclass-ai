<?php

namespace App\Domain\CallIntake\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property bool $is_enabled
 */
class CallSource extends Model
{
    public const MANUAL = 'manual';

    protected $fillable = ['code', 'name', 'is_enabled'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public static function manual(): self
    {
        return self::query()->where('code', self::MANUAL)->firstOrFail();
    }
}
