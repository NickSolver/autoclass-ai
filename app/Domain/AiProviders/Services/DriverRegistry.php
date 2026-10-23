<?php

namespace App\Domain\AiProviders\Services;

use App\Domain\AiProviders\Contracts\EvaluationProviderInterface;
use App\Domain\AiProviders\Contracts\SpeechToTextProviderInterface;
use App\Domain\AiProviders\Drivers\Mock\MockEvaluation;
use App\Domain\AiProviders\Drivers\Mock\MockSpeechToText;
use App\Domain\AiProviders\Drivers\OpenAiCompatible\OpenAiCompatibleEvaluation;
use App\Domain\AiProviders\ProviderException;
use App\Domain\AiProviders\ProviderKind;

/**
 * Реестр драйверов. Настоящие провайдеры добавляются отдельными драйверами после выбора
 * провайдера и проверки его API — интеграции не пишутся «вслепую» (ТЗ, разд. 4.3, 8).
 */
final class DriverRegistry
{
    /** @var array<string, array{name: string, external: bool, asr: ?class-string<SpeechToTextProviderInterface>, llm: ?class-string<EvaluationProviderInterface>}> */
    private const DRIVERS = [
        'mock' => [
            'name' => 'Тестовый (заглушка)',
            'external' => false,
            'asr' => MockSpeechToText::class,
            'llm' => MockEvaluation::class,
        ],
        // Любой сервис с OpenAI-совместимым Chat Completions API (DeepSeek, OpenAI и др.). Только оценка:
        // распознавания речи в этом API у DeepSeek нет.
        'openai_compatible' => [
            'name' => 'OpenAI-совместимый API',
            'external' => true,
            'asr' => null,
            'llm' => OpenAiCompatibleEvaluation::class,
        ],
    ];

    /** Готовые настройки для известных сервисов (адрес и модель; ключ и тарифы — администратор). */
    public const PRESETS = [
        'openai_compatible' => [
            ['name' => 'DeepSeek', 'endpoint' => 'https://api.deepseek.com', 'model' => 'deepseek-chat'],
        ],
    ];

    /** @return list<array{code: string, name: string, external: bool}> */
    public function available(ProviderKind $kind): array
    {
        $out = [];
        foreach (self::DRIVERS as $code => $driver) {
            if ($driver[$kind->value] !== null) {
                $out[] = ['code' => $code, 'name' => $driver['name'], 'external' => $driver['external']];
            }
        }

        return $out;
    }

    public function exists(ProviderKind $kind, string $driver): bool
    {
        return (self::DRIVERS[$driver][$kind->value] ?? null) !== null;
    }

    public function isExternal(string $driver): bool
    {
        return self::DRIVERS[$driver]['external'] ?? true;
    }

    public function speechToText(string $driver): SpeechToTextProviderInterface
    {
        $class = self::DRIVERS[$driver]['asr'] ?? throw new ProviderException(ProviderException::CONFIG_ERROR, "Драйвер распознавания «{$driver}» не найден.");

        return app($class);
    }

    public function evaluation(string $driver): EvaluationProviderInterface
    {
        $class = self::DRIVERS[$driver]['llm'] ?? throw new ProviderException(ProviderException::CONFIG_ERROR, "Драйвер оценки «{$driver}» не найден.");

        return app($class);
    }
}
