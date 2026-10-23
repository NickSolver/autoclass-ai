<?php

namespace App\Domain\AiProviders\Drivers\Mock;

/**
 * Режимы тестового провайдера: штатная работа и имитация сбоев — чтобы проверять реакцию
 * конвейера (повторы, резерв, ручная проверка) без внешних сервисов.
 */
enum MockScenario: string
{
    case Normal = 'normal';
    case Timeout = 'timeout';
    case RateLimited = 'rate_limited';
    case ServerError = 'server_error';
    /** Только ASR: таймкоды за пределами записи. */
    case BadTimecodes = 'bad_timecodes';
    /** Только оценка: ответ — не JSON. */
    case BadJson = 'bad_json';
    /** Только оценка: цитата, которой нет в расшифровке. */
    case InventedEvidence = 'invented_evidence';
    /** Только оценка: обязательный критерий — «недостаточно данных». */
    case InsufficientData = 'insufficient_data';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Штатная работа',
            self::Timeout => 'Сбой: тайм-аут',
            self::RateLimited => 'Сбой: превышен лимит запросов (429)',
            self::ServerError => 'Сбой: ошибка сервера (500)',
            self::BadTimecodes => 'Ошибка: неверные таймкоды',
            self::BadJson => 'Ошибка: некорректный JSON',
            self::InventedEvidence => 'Ошибка: выдуманная цитата',
            self::InsufficientData => 'Недостаточно данных по обязательному критерию',
        };
    }

    /** @return list<self> */
    public static function forKind(string $kind): array
    {
        $common = [self::Normal, self::Timeout, self::RateLimited, self::ServerError];

        return $kind === 'asr'
            ? [...$common, self::BadTimecodes]
            : [...$common, self::BadJson, self::InventedEvidence, self::InsufficientData];
    }
}
