<?php

namespace App\Domain\AiProviders;

use RuntimeException;

/**
 * Ошибка вызова провайдера. Классификация определяет реакцию конвейера: повтор, резерв или отказ.
 */
final class ProviderException extends RuntimeException
{
    public const TIMEOUT = 'timeout';

    public const RATE_LIMITED = 'rate_limited';

    public const SERVER_ERROR = 'server_error';

    public const BAD_RESPONSE = 'bad_response';

    public const CONFIG_ERROR = 'config_error';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    /** Повтор имеет смысл (временный сбой). Ошибка конфигурации повтором не лечится. */
    public function isRetryable(): bool
    {
        return $this->errorCode !== self::CONFIG_ERROR;
    }
}
