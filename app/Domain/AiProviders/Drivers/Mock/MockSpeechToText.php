<?php

namespace App\Domain\AiProviders\Drivers\Mock;

use App\Domain\AiProviders\Contracts\AudioInput;
use App\Domain\AiProviders\Contracts\SpeechToTextProviderInterface;
use App\Domain\AiProviders\Contracts\TranscriptionResult;
use App\Domain\AiProviders\Contracts\TranscriptSegmentData;
use App\Domain\AiProviders\Models\ProviderConfig;

/**
 * Тестовый «распознаватель»: НЕ распознаёт речь. Возвращает один и тот же синтетический диалог,
 * распределённый по длительности записи, с пометкой synthetic. Данные не покидают портал.
 */
final class MockSpeechToText implements SpeechToTextProviderInterface
{
    use MockFailures;

    /** @var list<array{0: string, 1: string}> [говорящий, текст] */
    public const DIALOGUE = [
        ['agent', 'Здравствуйте, дилерский центр, меня зовут Анна. Чем могу помочь?'],
        ['client', 'Добрый день, интересует новый кроссовер.'],
        ['agent', 'Подскажите, что для вас важнее всего: комплектация или цена?'],
        ['client', 'Важны полный привод и хорошая комплектация.'],
        ['agent', 'Какой бюджет вы рассматриваете? Планируете сдать автомобиль в трейд-ин?'],
        ['client', 'Около трёх миллионов, старую машину хотел бы сдать.'],
        ['agent', 'Предлагаю приехать на тест-драйв в субботу, автомобиль будет готов.'],
        ['client', 'Да, суббота подходит.'],
        ['agent', 'Договорились: суббота, одиннадцать часов, я записала вас. Спасибо за звонок!'],
        ['client', 'Спасибо.'],
        ['agent', 'Хорошего дня, до свидания.'],
    ];

    public function transcribe(ProviderConfig $config, AudioInput $audio): TranscriptionResult
    {
        $durationMs = $audio->durationMs;
        $scenario = MockScenario::tryFrom((string) $config->setting('mock_scenario')) ?? MockScenario::Normal;
        $this->simulateFailure($scenario);

        // На короткую запись — меньше реплик: примерно одна на 3 секунды, не меньше двух.
        $count = max(2, min(count(self::DIALOGUE), intdiv($durationMs, 3000)));
        $slot = intdiv($durationMs, $count);
        $segments = [];
        for ($i = 0; $i < $count; $i++) {
            [$role, $text] = self::DIALOGUE[$i];
            $start = $i * $slot;
            $end = $scenario === MockScenario::BadTimecodes && $i === $count - 1
                ? $durationMs + 60_000
                : $start + max(1, (int) round($slot * 0.9));
            $segments[] = new TranscriptSegmentData($start, $end, $role === 'agent' ? 'S1' : 'S2', $text, 0.99, $role);
        }

        return new TranscriptionResult($segments, 'ru', $durationMs / 1000, synthetic: true);
    }

    public function testConnection(ProviderConfig $config): void
    {
        $this->simulateFailure(MockScenario::tryFrom((string) $config->setting('mock_scenario')) ?? MockScenario::Normal);
    }

    public function isExternal(): bool
    {
        return false;
    }
}
