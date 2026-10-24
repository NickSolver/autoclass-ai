<?php

namespace App\Domain\Sources;

use RuntimeException;

/** Ошибка источника. Сообщение показывается администратору — без секретов. */
final class SourceException extends RuntimeException
{
    public const AUTH = 'auth';

    public const UNAVAILABLE = 'unavailable';

    public const BAD_PAYLOAD = 'bad_payload';

    public const NOT_SUPPORTED = 'not_supported';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
