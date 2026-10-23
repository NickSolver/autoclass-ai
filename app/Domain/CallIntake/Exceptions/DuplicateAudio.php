<?php

namespace App\Domain\CallIntake\Exceptions;

use App\Domain\CallIntake\Models\Call;
use RuntimeException;

/** Такой же файл (SHA-256) уже загружался. Загрузка возможна только с явным подтверждением. */
final class DuplicateAudio extends RuntimeException
{
    public function __construct(public readonly Call $existing)
    {
        parent::__construct('Такой файл уже загружался.');
    }
}
