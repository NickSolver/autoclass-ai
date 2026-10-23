<?php

namespace App\Domain\CallIntake\Exceptions;

use RuntimeException;

/** Ошибка обработки, повтор которой бессмыслен (нет исходного файла и т. п.). */
final class PermanentFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
