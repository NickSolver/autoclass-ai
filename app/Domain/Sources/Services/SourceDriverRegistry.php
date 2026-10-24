<?php

namespace App\Domain\Sources\Services;

use App\Domain\CallIntake\Models\CallSource;
use App\Domain\Sources\Contracts\CallSourceDriver;
use App\Domain\Sources\Drivers\MockSourceDriver;
use Illuminate\Validation\ValidationException;

/**
 * Реестр адаптеров источников. Calltouch и «Лягушка» перечислены, но без кода интеграции:
 * их API не придумывается (ТЗ, разд. 4.3, 4.4) — адаптеры появятся после документации и тестового доступа.
 */
final class SourceDriverRegistry
{
    /** @var array<string, array{name: string, available: bool, description: string, class?: class-string<CallSourceDriver>}> */
    private const DRIVERS = [
        'mock' => [
            'name' => 'Тестовый источник',
            'available' => true,
            'description' => 'Синтетические звонки и записи-тоны для проверки синхронизации, приёма событий и дублей. Реальных данных не содержит.',
            'class' => MockSourceDriver::class,
        ],
        'calltouch' => [
            'name' => 'Calltouch',
            'available' => false,
            'description' => 'Ждёт документации API и тестового доступа: возможности аккаунта, вебхуки, получение записей и атрибуция подтверждаются по реальному тарифу.',
        ],
        'lyagushka' => [
            'name' => 'Телефония «Лягушка»',
            'available' => false,
            'description' => 'Ждёт документации: официальное название, идентификаторы, авторизация, получение записей и тестовая среда.',
        ],
    ];

    /** @return array<string, array{name: string, available: bool, description: string}> */
    public function all(): array
    {
        return array_map(fn (array $d) => ['name' => $d['name'], 'available' => $d['available'], 'description' => $d['description']], self::DRIVERS);
    }

    public function driver(string $code): CallSourceDriver
    {
        $meta = self::DRIVERS[$code] ?? null;
        $class = $meta['class'] ?? null;
        if ($meta === null || ! $meta['available'] || $class === null) {
            throw ValidationException::withMessages(['driver' => 'Адаптер источника недоступен.']);
        }

        return app($class);
    }

    /** Запись call_sources для адаптера (идемпотентность звонков — по source + external_id). */
    public function source(string $code): CallSource
    {
        $meta = self::DRIVERS[$code] ?? throw ValidationException::withMessages(['driver' => 'Неизвестный источник.']);

        return CallSource::query()->firstOrCreate(['code' => $code], ['name' => $meta['name'], 'is_enabled' => true]);
    }
}
