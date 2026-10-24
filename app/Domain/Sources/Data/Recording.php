<?php

namespace App\Domain\Sources\Data;

/** Запись, скачанная во временный файл. Файл удаляет ядро после приёма. */
final readonly class Recording
{
    public function __construct(public string $path, public string $name) {}
}
