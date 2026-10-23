<?php

namespace App\Domain\AiProviders\Drivers\Mock;

use App\Domain\AiProviders\Contracts\EvaluationProviderInterface;
use App\Domain\AiProviders\Contracts\EvaluationResponse;
use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderException;

/**
 * Тестовый «оценщик»: детерминированно ищет ключевые фразы демо-критериев в репликах сотрудника.
 * Критерии, которых он не знает, честно получают «недостаточно данных». Ответ — тот же JSON,
 * что ждём от настоящей модели, и проходит ту же проверку на сервере.
 */
final class MockEvaluation implements EvaluationProviderInterface
{
    use MockFailures;

    /** Ключевые фразы демо-критериев (коды из DemoChecklistSeeder). */
    private const KEYWORDS = [
        'greeting' => ['здравствуйте', 'меня зовут'],
        'need' => ['что для вас важнее', 'чем могу помочь'],
        'questions' => ['какой бюджет', 'подскажите'],
        'next_step' => ['тест-драйв', 'приехать'],
        'agreement' => ['договорились', 'записала'],
        'closing' => ['до свидания', 'хорошего дня'],
        'identify' => ['меня зовут'],
        'purpose' => ['предлагаю', 'чем могу помочь'],
        'next_action' => ['договорились', 'тест-драйв'],
        'politeness' => ['спасибо', 'пожалуйста', 'здравствуйте'],
    ];

    /** Критерии «отсутствия»: выполнены, если запрещённого нет; доказательство — весь разговор. */
    private const ABSENCE = [
        'no_bad_phrases' => ['дурак', 'не мои проблемы', 'отстаньте'],
        'clarity' => [],
    ];

    public function evaluate(ProviderConfig $config, array $prompt): EvaluationResponse
    {
        $scenario = MockScenario::tryFrom((string) $config->setting('mock_scenario')) ?? MockScenario::Normal;
        $this->simulateFailure($scenario);

        $payload = $prompt['payload'];
        if ($scenario === MockScenario::BadJson) {
            return new EvaluationResponse('{"criteria": [ это не JSON', $this->tokens($prompt['user']), 10);
        }

        /** @var list<array{segment_id: int, start_ms: int, end_ms: int, speaker: string, role: string, text: string}> $segments */
        $segments = $payload['transcript'];
        $agent = array_values(array_filter($segments, fn (array $s) => $s['role'] === 'agent'));
        $first = $segments[0] ?? null;
        $last = $segments[count($segments) - 1] ?? null;
        $whole = $first && $last ? ['type' => 'time_range', 'start_ms' => $first['start_ms'], 'end_ms' => $last['end_ms']] : null;

        $results = [];
        foreach ($payload['criteria'] as $i => $criterion) {
            $code = $criterion['code'];
            $result = ['criterion_id' => $criterion['id'], 'confidence' => 0.9, 'evidence_refs' => []];

            if (isset(self::KEYWORDS[$code])) {
                $hit = $this->find($agent, self::KEYWORDS[$code]);
                if ($hit !== null) {
                    $result += ['status' => 'pass', 'reason' => 'Найдено в реплике сотрудника.'];
                    $result['evidence_refs'][] = ['type' => 'quote', 'segment_id' => $hit['segment_id'], 'quote' => $hit['text'], 'speaker' => $hit['speaker']];
                } else {
                    $result += ['status' => 'fail', 'reason' => 'В разговоре не обнаружено.'];
                    if ($whole) {
                        $result['evidence_refs'][] = $whole;
                    }
                }
            } elseif (isset(self::ABSENCE[$code])) {
                $bad = $this->find($agent, self::ABSENCE[$code]);
                $result += $bad === null
                    ? ['status' => 'pass', 'reason' => 'Нарушений в репликах сотрудника не обнаружено.']
                    : ['status' => 'fail', 'reason' => 'Обнаружена недопустимая формулировка.'];
                if ($bad !== null) {
                    $result['evidence_refs'][] = ['type' => 'quote', 'segment_id' => $bad['segment_id'], 'quote' => $bad['text'], 'speaker' => $bad['speaker']];
                } elseif ($whole !== null) {
                    $result['evidence_refs'][] = $whole;
                }
            } else {
                $result += ['status' => 'insufficient_data', 'reason' => 'Тестовый провайдер не умеет оценивать этот критерий.', 'confidence' => 0.0];
            }

            if ($scenario === MockScenario::InventedEvidence && $i === 0) {
                $result['status'] = 'pass';
                $result['evidence_refs'] = [['type' => 'quote', 'segment_id' => $first['segment_id'] ?? 0, 'quote' => 'Мы дарим вам автомобиль бесплатно.', 'speaker' => 'S1']];
            }
            if ($scenario === MockScenario::InsufficientData && $i === 0) {
                $result['status'] = 'insufficient_data';
                $result['reason'] = 'Фрагмент записи неразборчив (имитация).';
                $result['evidence_refs'] = [];
            }
            $results[] = $result;
        }

        $passed = count(array_filter($results, fn (array $r) => $r['status'] === 'pass'));
        $json = json_encode([
            'checklist_version_id' => $payload['checklist_version_id'],
            'criteria' => $results,
            'summary' => "Тестовая оценка: выполнено {$passed} из ".count($results).' критериев.',
            'risks' => [],
            'recommendations' => $passed < count($results) ? ['Отработать невыполненные пункты чек-листа (тестовая рекомендация).'] : [],
        ], JSON_UNESCAPED_UNICODE) ?: throw new ProviderException(ProviderException::BAD_RESPONSE, 'Не удалось сформировать ответ.');

        return new EvaluationResponse($json, $this->tokens($prompt['system'].$prompt['user']), $this->tokens($json));
    }

    public function testConnection(ProviderConfig $config): void
    {
        $this->simulateFailure(MockScenario::tryFrom((string) $config->setting('mock_scenario')) ?? MockScenario::Normal);
    }

    public function isExternal(): bool
    {
        return false;
    }

    /**
     * @param  list<array{segment_id: int, speaker: string, text: string}>  $segments
     * @param  list<string>  $needles
     * @return ?array{segment_id: int, speaker: string, text: string}
     */
    private function find(array $segments, array $needles): ?array
    {
        foreach ($segments as $segment) {
            $text = mb_strtolower($segment['text']);
            foreach ($needles as $needle) {
                if (str_contains($text, $needle)) {
                    return $segment;
                }
            }
        }

        return null;
    }

    /** Оценка числа токенов (≈ 4 символа на токен) — для учёта стоимости тестовых вызовов. */
    private function tokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
