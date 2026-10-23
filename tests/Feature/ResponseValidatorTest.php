<?php

namespace Tests\Feature;

use App\Domain\Evaluation\Models\Checklist;
use App\Domain\Evaluation\Models\ChecklistVersion;
use App\Domain\Evaluation\Services\InvalidEvaluationResponse;
use App\Domain\Evaluation\Services\ResponseValidator;
use App\Domain\Transcription\Models\TranscriptSegment;
use Database\Seeders\DemoChecklistSeeder;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Серверная проверка ответа модели и доказательств (ТЗ, разд. 7.2, 8). */
class ResponseValidatorTest extends TestCase
{
    private ChecklistVersion $version;

    /** @var Collection<int, TranscriptSegment> */
    private Collection $segments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoChecklistSeeder::class);
        $this->version = Checklist::query()->where('code', 'demo_communication_standard')->firstOrFail()->latestPublished()->load(['checklist', 'criteria']);
        $this->segments = collect([
            new TranscriptSegment(['start_ms' => 0, 'end_ms' => 4000, 'speaker' => 'S1', 'text' => 'Здравствуйте, меня зовут Анна. Чем могу помочь?']),
            new TranscriptSegment(['start_ms' => 4000, 'end_ms' => 8000, 'speaker' => 'S2', 'text' => 'Добрый день, интересует кроссовер.']),
        ])->each(fn (TranscriptSegment $s, int $i) => $s->setAttribute('id', 100 + $i));
    }

    /** @param list<array<string, mixed>> $evidence */
    private function response(array $evidence, string $status = 'pass', ?array $only = null): string
    {
        $criteria = $this->version->criteria->map(fn ($c, $i) => [
            'criterion_id' => $c->id, 'status' => $i === 0 ? $status : 'not_applicable', 'reason' => 'r', 'confidence' => 0.5,
            'evidence_refs' => $i === 0 ? $evidence : [],
        ])->all();

        return (string) json_encode(['checklist_version_id' => $this->version->id, 'criteria' => $only ?? $criteria, 'summary' => 's', 'risks' => [], 'recommendations' => []], JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function first(string $raw): array
    {
        return app(ResponseValidator::class)->validate($raw, $this->version, $this->segments, 8000)['criteria'][0];
    }

    #[Test]
    public function exact_quote_from_the_right_speaker_is_accepted_case_insensitively(): void
    {
        $r = $this->first($this->response([['type' => 'quote', 'segment_id' => 100, 'quote' => 'меня зовут АННА', 'speaker' => 'S1']]));

        $this->assertTrue($r['evidence_valid']);
        $this->assertSame(0, $r['evidence'][0]['start_ms'], 'Таймкоды берутся из реплики, а не из ответа модели');
    }

    #[Test]
    public function fabricated_evidence_is_rejected_with_a_reason(): void
    {
        $cases = [
            'несуществующая реплика' => [['type' => 'quote', 'segment_id' => 999, 'quote' => 'Здравствуйте'], 'реплики с таким номером нет'],
            'цитата не из реплики' => [['type' => 'quote', 'segment_id' => 100, 'quote' => 'Предлагаю тест-драйв'], 'цитаты нет в указанной реплике'],
            'чужой говорящий' => [['type' => 'quote', 'segment_id' => 100, 'quote' => 'Здравствуйте', 'speaker' => 'S2'], 'говорящий не совпадает'],
            'отрезок за пределами записи' => [['type' => 'time_range', 'start_ms' => 5000, 'end_ms' => 60000], 'невозможный отрезок'],
            'перевёрнутый отрезок' => [['type' => 'time_range', 'start_ms' => 5000, 'end_ms' => 1000], 'невозможный отрезок'],
            'неизвестный тип' => [['type' => 'screenshot'], 'неподдерживаемый тип'],
        ];

        foreach ($cases as $label => [$evidence, $issue]) {
            $r = $this->first($this->response([$evidence]));
            $this->assertFalse($r['evidence_valid'], $label);
            $this->assertStringContainsString($issue, (string) $r['evidence'][0]['issue'], $label);
        }
    }

    #[Test]
    public function pass_or_fail_without_evidence_needs_review_but_insufficient_data_does_not(): void
    {
        $this->assertFalse($this->first($this->response([], 'fail'))['evidence_valid']);
        $this->assertTrue($this->first($this->response([], 'insufficient_data'))['evidence_valid']);
    }

    #[Test]
    public function structural_errors_raise_for_a_retry(): void
    {
        $ids = $this->version->criteria->pluck('id');
        $broken = [
            'не JSON' => 'Вот оценка: отлично',
            'чужая версия' => json_encode(['checklist_version_id' => 999, 'criteria' => []]),
            'не все критерии' => $this->response([], 'pass', [['criterion_id' => $ids[0], 'status' => 'pass', 'reason' => '', 'evidence_refs' => []]]),
            'дубль критерия' => $this->response([], 'pass', array_map(fn () => ['criterion_id' => $ids[0], 'status' => 'not_applicable', 'reason' => '', 'evidence_refs' => []], range(1, 3))),
            'неизвестный статус' => $this->response([], 'excellent'),
        ];

        foreach ($broken as $label => $raw) {
            try {
                app(ResponseValidator::class)->validate((string) $raw, $this->version, $this->segments, 8000);
                $this->fail("{$label}: ожидалась ошибка структуры");
            } catch (InvalidEvaluationResponse) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function markdown_fences_around_json_are_tolerated(): void
    {
        $raw = "```json\n".$this->response([['type' => 'time_range', 'start_ms' => 0, 'end_ms' => 8000]], 'fail')."\n```";

        $this->assertTrue($this->first($raw)['evidence_valid']);
    }
}
