<?php

namespace App\Domain\Evaluation\Services;

use App\Domain\Evaluation\Models\ChecklistCriterion;
use App\Domain\Evaluation\Models\ChecklistVersion;
use App\Domain\Transcription\Models\TranscriptSegment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Серверная проверка ответа модели (ТЗ, разд. 7.2, 8).
 * Структурные нарушения (не JSON, не те критерии) — исключение: запрос повторяется.
 * Недостоверные доказательства (несуществующая реплика, цитата не из реплики, чужой говорящий,
 * невозможный таймкод) — критерий помечается, результат уходит на проверку человеку.
 */
final class ResponseValidator
{
    private const STATUSES = ['pass', 'fail', 'not_applicable', 'insufficient_data'];

    /**
     * @param  Collection<int, TranscriptSegment>  $segments
     * @return array{criteria: list<array{criterion: ChecklistCriterion, status: string, reason: ?string, confidence: ?float, evidence: list<array<string, mixed>>, evidence_valid: bool, issues: list<string>}>, summary: ?string, risks: list<string>, recommendations: list<string>}
     *
     * @throws InvalidEvaluationResponse
     */
    public function validate(string $raw, ChecklistVersion $version, Collection $segments, int $durationMs): array
    {
        $data = json_decode($this->stripFences($raw), true);
        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidEvaluationResponse('Ответ модели — не JSON-объект.');
        }
        if ((int) ($data['checklist_version_id'] ?? 0) !== $version->id) {
            throw new InvalidEvaluationResponse('Ответ относится к другой версии чек-листа.');
        }
        if (! is_array($data['criteria'] ?? null)) {
            throw new InvalidEvaluationResponse('В ответе нет списка критериев.');
        }

        $criteria = $version->criteria->keyBy('id');
        $segmentsById = $segments->keyBy('id');
        $seen = [];
        $results = [];

        foreach ($data['criteria'] as $item) {
            if (! is_array($item)) {
                throw new InvalidEvaluationResponse('Элемент списка критериев — не объект.');
            }
            $id = (int) ($item['criterion_id'] ?? 0);
            $criterion = $criteria->get($id) ?? throw new InvalidEvaluationResponse("Неизвестный критерий {$id}.");
            if (isset($seen[$id])) {
                throw new InvalidEvaluationResponse("Критерий {$criterion->code} оценён дважды.");
            }
            $seen[$id] = true;

            $status = (string) ($item['status'] ?? '');
            if (! in_array($status, self::STATUSES, true)) {
                throw new InvalidEvaluationResponse("Недопустимый статус критерия {$criterion->code}.");
            }

            $issues = [];
            $confidence = $item['confidence'] ?? null;
            if ($confidence !== null && (! is_numeric($confidence) || $confidence < 0 || $confidence > 1)) {
                $issues[] = 'Уверенность вне диапазона 0–1.';
                $confidence = null;
            }

            $evidence = [];
            foreach (is_array($item['evidence_refs'] ?? null) ? $item['evidence_refs'] : [] as $ref) {
                $evidence[] = $this->checkEvidence(is_array($ref) ? $ref : [], $segmentsById, $durationMs);
            }
            $validCount = count(array_filter($evidence, fn (array $e) => $e['is_valid']));
            $invalid = array_filter($evidence, fn (array $e) => ! $e['is_valid']);
            foreach ($invalid as $e) {
                $issues[] = 'Доказательство отклонено: '.$e['issue'];
            }

            $needsEvidence = in_array($status, ['pass', 'fail'], true);
            if ($needsEvidence && $validCount === 0) {
                $issues[] = 'Нет подтверждённых доказательств для статуса «'.$status.'».';
            }

            $results[] = [
                'criterion' => $criterion,
                'status' => $status,
                'reason' => isset($item['reason']) ? Str::limit((string) $item['reason'], 2000) : null,
                'confidence' => $confidence === null ? null : (float) $confidence,
                'evidence' => $evidence,
                // Любое отклонённое доказательство — повод проверить критерий человеком.
                'evidence_valid' => $invalid === [] && (! $needsEvidence || $validCount > 0),
                'issues' => $issues,
            ];
        }

        $missing = $criteria->keys()->diff(array_keys($seen));
        if ($missing->isNotEmpty()) {
            throw new InvalidEvaluationResponse('В ответе оценены не все критерии.');
        }

        return [
            'criteria' => $results,
            'summary' => isset($data['summary']) ? Str::limit((string) $data['summary'], 4000) : null,
            'risks' => $this->strings($data['risks'] ?? []),
            'recommendations' => $this->strings($data['recommendations'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $ref
     * @param  Collection<int, TranscriptSegment>  $segments
     * @return array<string, mixed>
     */
    private function checkEvidence(array $ref, Collection $segments, int $durationMs): array
    {
        $type = (string) ($ref['type'] ?? '');
        $tolerance = (int) config('analysis.timecode_tolerance_ms', 500);
        $base = ['type' => Str::limit($type, 24, ''), 'transcript_segment_id' => null, 'quote' => null, 'start_ms' => null, 'end_ms' => null, 'speaker' => null];

        if ($type === 'quote') {
            $segment = $segments->get((int) ($ref['segment_id'] ?? 0));
            $quote = Str::limit((string) ($ref['quote'] ?? ''), 2000, '');
            $speaker = isset($ref['speaker']) ? Str::limit((string) $ref['speaker'], 16, '') : null;
            $base = [...$base, 'quote' => $quote, 'speaker' => $speaker];
            if ($segment === null) {
                return [...$base, 'is_valid' => false, 'issue' => 'реплики с таким номером нет в расшифровке.'];
            }
            $base = [...$base, 'transcript_segment_id' => $segment->id, 'start_ms' => $segment->start_ms, 'end_ms' => $segment->end_ms];
            if ($this->normalize($quote) === '' || ! str_contains($this->normalize($segment->text), $this->normalize($quote))) {
                return [...$base, 'is_valid' => false, 'issue' => 'цитаты нет в указанной реплике.'];
            }
            if ($speaker !== null && $speaker !== $segment->speaker) {
                return [...$base, 'is_valid' => false, 'issue' => 'говорящий не совпадает с репликой.'];
            }

            return [...$base, 'speaker' => $segment->speaker, 'is_valid' => true, 'issue' => null];
        }

        if ($type === 'time_range') {
            $start = $ref['start_ms'] ?? null;
            $end = $ref['end_ms'] ?? null;
            $base = [...$base, 'start_ms' => is_numeric($start) ? max(0, (int) $start) : null, 'end_ms' => is_numeric($end) ? max(0, (int) $end) : null];
            if (! is_numeric($start) || ! is_numeric($end) || $start < 0 || $end <= $start || $end > $durationMs + $tolerance) {
                return [...$base, 'is_valid' => false, 'issue' => 'невозможный отрезок времени.'];
            }

            return [...$base, 'is_valid' => true, 'issue' => null];
        }

        return [...$base, 'is_valid' => false, 'issue' => 'неподдерживаемый тип доказательства.'];
    }

    /** Сравнение цитаты без учёта регистра, ё/е, кавычек и лишних пробелов. */
    private function normalize(string $text): string
    {
        $text = mb_strtolower(str_replace(['ё', 'Ё'], 'е', $text));
        $text = (string) preg_replace('/[«»"“”„\']+/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text), " \t\n.,!?…");
    }

    /** Модели иногда оборачивают JSON в ```json … ```. */
    private function stripFences(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $raw, $m)) {
            return $m[1];
        }

        return $raw;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_map(fn ($v) => Str::limit((string) $v, 1000), array_filter($value, 'is_scalar')))
            : [];
    }
}
