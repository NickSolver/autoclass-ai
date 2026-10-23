<?php

namespace App\Domain\AiProviders\Services;

use App\Domain\AiProviders\Contracts\EvaluationProviderInterface;
use App\Domain\AiProviders\Contracts\SpeechToTextProviderInterface;
use App\Domain\AiProviders\Drivers\Mock\MockEvaluation;
use App\Domain\AiProviders\Drivers\Mock\MockSpeechToText;
use App\Domain\AiProviders\Drivers\OpenAi\OpenAiTranscription;
use App\Domain\AiProviders\Drivers\OpenAiCompatible\OpenAiCompatibleEvaluation;
use App\Domain\AiProviders\Drivers\TBank\TBankVoiceKit;
use App\Domain\AiProviders\Drivers\Yandex\YandexSpeechKit;
use App\Domain\AiProviders\ProviderException;
use App\Domain\AiProviders\ProviderKind;

/**
 * Реестр драйверов (ADR-0004, ADR-0005). Драйвер появляется только после сверки с официальной
 * спецификацией API провайдера — интеграции не пишутся «вслепую» (ТЗ, разд. 4.3, 8).
 * Сервисы, для которых спецификация недоступна, перечислены как «ожидают документации» —
 * они видны в интерфейсе, но выбрать их нельзя.
 */
final class DriverRegistry
{
    /**
     * @var array<string, array{
     *     name: string, external: bool, kind: string, class: class-string,
     *     endpoint?: string, models?: list<string>, model_required?: bool,
     *     secret_fields: list<array{key: string, label: string, multiline?: bool}>, note?: string
     * }>
     */
    private const DRIVERS = [
        'mock_asr' => [
            'name' => 'Тестовый (заглушка)', 'external' => false, 'kind' => 'asr', 'class' => MockSpeechToText::class,
            'secret_fields' => [],
        ],
        'mock_llm' => [
            'name' => 'Тестовый (заглушка)', 'external' => false, 'kind' => 'llm', 'class' => MockEvaluation::class,
            'secret_fields' => [],
        ],
        'yandex' => [
            'name' => 'Яндекс SpeechKit', 'external' => true, 'kind' => 'asr', 'class' => YandexSpeechKit::class,
            'endpoint' => 'https://stt.api.cloud.yandex.net', 'models' => ['general', 'general:rc'],
            'secret_fields' => [['key' => 'secret', 'label' => 'Ключ API сервисного аккаунта (роль ai.speechkit-stt.user)']],
            'note' => 'Данные обрабатываются в РФ. Стерео — каналы как говорящие; моно — разметка говорящих Яндекса.',
        ],
        'tbank' => [
            'name' => 'T-Bank VoiceKit', 'external' => true, 'kind' => 'asr', 'class' => TBankVoiceKit::class,
            'endpoint' => 'https://api.tinkoff.ai', 'models' => [],
            'secret_fields' => [['key' => 'api_key', 'label' => 'API-ключ'], ['key' => 'secret_key', 'label' => 'Секретный ключ']],
            'note' => 'Данные обрабатываются в РФ. Говорящие различаются только по каналам стереозаписи.',
        ],
        'openai' => [
            'name' => 'OpenAI (распознавание)', 'external' => true, 'kind' => 'asr', 'class' => OpenAiTranscription::class,
            'endpoint' => 'https://api.openai.com/v1', 'models' => OpenAiTranscription::MODELS, 'model_required' => true,
            'secret_fields' => [['key' => 'secret', 'label' => 'Ключ API']],
            'note' => 'Данные уходят за рубеж. gpt-4o-transcribe-diarize разделяет говорящих, whisper-1 — нет. Лимит 25 МБ на запись.',
        ],
        'openai_compatible' => [
            'name' => 'OpenAI-совместимый API', 'external' => true, 'kind' => 'llm', 'class' => OpenAiCompatibleEvaluation::class,
            'model_required' => true,
            'secret_fields' => [['key' => 'secret', 'label' => 'Ключ API']],
            'note' => 'Любой сервис с Chat Completions API: DeepSeek, OpenAI и др.',
        ],
    ];

    /** Сервисы, которые ждут документации или доступа, с причиной. hidden — не показывать в интерфейсе. */
    private const PLANNED = [
        'salute' => ['name' => 'SaluteSpeech (Сбер)', 'kind' => 'asr', 'reason' => 'Описание REST API распознавания файлов недоступно из сети портала (в официальном репозитории — только потоковый gRPC). Нужны документация и тестовый доступ.'],
        'mts' => ['name' => 'МТС Audiogram', 'kind' => 'asr', 'reason' => 'Публичной спецификации API нет. Нужны документация и тестовый доступ от МТС AI.'],
        'speechpro' => ['name' => 'ЦРТ (Центр речевых технологий)', 'kind' => 'asr', 'reason' => 'Публично доступен только потоковый gRPC в статусе beta (2022); актуальная документация недоступна. Нужны документация и доступ.'],
        // Скрыт по решению заказчика 24.10.2026 (данные за рубеж, нужен Google Cloud Storage); запись сохранена.
        'google' => ['name' => 'Google Speech-to-Text', 'kind' => 'asr', 'hidden' => true, 'reason' => 'Синхронное распознавание — только до 1 минуты; длинные звонки требуют Google Cloud Storage. Нужен проект Google Cloud.'],
    ];

    /** Готовые настройки для известных сервисов (адрес и модель; ключ и тарифы — администратор). */
    public const PRESETS = [
        'openai_compatible' => [
            // Модель — по списку /models на 24.10.2026; «Проверить» сверяет модель с актуальным списком.
            ['name' => 'DeepSeek', 'endpoint' => 'https://api.deepseek.com', 'model' => 'deepseek-flash'],
            ['name' => 'OpenAI', 'endpoint' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini'],
        ],
    ];

    /** @return list<array<string, mixed>> */
    public function available(ProviderKind $kind): array
    {
        $out = [];
        foreach (self::DRIVERS as $code => $d) {
            if ($d['kind'] === $kind->value) {
                $out[] = [
                    'code' => str_starts_with($code, 'mock_') ? 'mock' : $code, 'name' => $d['name'], 'external' => $d['external'], 'available' => true,
                    'endpoint' => $d['endpoint'] ?? null, 'models' => $d['models'] ?? [], 'model_required' => $d['model_required'] ?? false,
                    'secret_fields' => $d['secret_fields'], 'note' => $d['note'] ?? null,
                ];
            }
        }
        foreach (self::PLANNED as $code => $p) {
            if ($p['kind'] === $kind->value && ! ($p['hidden'] ?? false)) {
                $out[] = ['code' => $code, 'name' => $p['name'], 'external' => true, 'available' => false, 'reason' => $p['reason']];
            }
        }

        return $out;
    }

    public function exists(ProviderKind $kind, string $driver): bool
    {
        return (self::DRIVERS[$this->resolve($kind, $driver)]['kind'] ?? null) === $kind->value;
    }

    public function isExternal(string $driver): bool
    {
        return self::DRIVERS[$driver]['external'] ?? (self::DRIVERS["{$driver}_asr"]['external'] ?? true);
    }

    public function defaultEndpoint(string $driver): ?string
    {
        return self::DRIVERS[$driver]['endpoint'] ?? null;
    }

    public function modelRequired(string $driver): bool
    {
        return self::DRIVERS[$driver]['model_required'] ?? false;
    }

    /** @return list<string> */
    public function secretFields(string $driver): array
    {
        $fields = self::DRIVERS[$driver]['secret_fields'] ?? (self::DRIVERS["{$driver}_asr"]['secret_fields'] ?? []);

        return array_column($fields, 'key');
    }

    public function speechToText(string $driver): SpeechToTextProviderInterface
    {
        $class = self::DRIVERS[$this->resolve(ProviderKind::Asr, $driver)]['class'] ?? null;
        if ($class === null || self::DRIVERS[$this->resolve(ProviderKind::Asr, $driver)]['kind'] !== 'asr') {
            throw new ProviderException(ProviderException::CONFIG_ERROR, "Драйвер распознавания «{$driver}» не найден.");
        }

        return app($class);
    }

    public function evaluation(string $driver): EvaluationProviderInterface
    {
        $class = self::DRIVERS[$this->resolve(ProviderKind::Llm, $driver)]['class'] ?? null;
        if ($class === null || self::DRIVERS[$this->resolve(ProviderKind::Llm, $driver)]['kind'] !== 'llm') {
            throw new ProviderException(ProviderException::CONFIG_ERROR, "Драйвер оценки «{$driver}» не найден.");
        }

        return app($class);
    }

    /** Код «mock» общий для двух видов — внутри реестра это mock_asr / mock_llm. */
    private function resolve(ProviderKind $kind, string $driver): string
    {
        return $driver === 'mock' ? "mock_{$kind->value}" : $driver;
    }
}
