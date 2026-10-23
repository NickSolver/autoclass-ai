<?php

namespace App\Domain\Audio;

use RuntimeException;

/**
 * Файл непригоден: понятная причина для пользователя (message) и короткая техническая
 * диагностика (detail) — без путей сервера.
 */
final class AudioRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
    }
}
