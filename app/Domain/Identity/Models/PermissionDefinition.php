<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Строка таблицы permissions — отражение enum Permission в БД (для связей и матрицы прав).
 *
 * @property int $id
 * @property string $code
 * @property string $category
 * @property string $name
 */
class PermissionDefinition extends Model
{
    protected $table = 'permissions';

    protected $fillable = ['code', 'category', 'name'];
}
