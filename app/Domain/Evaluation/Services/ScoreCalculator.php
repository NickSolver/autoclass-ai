<?php

namespace App\Domain\Evaluation\Services;

use App\Domain\Evaluation\Models\EvaluationResult;

/**
 * Правило оценки v1 (ТЗ, разд. 7.1):
 * - pass = вес, fail = 0, not_applicable исключается из знаменателя;
 * - insufficient_data и критерии с отклонёнными доказательствами не подменяются fail/N/A —
 *   результат получает «Требуется вмешательство человека», итоговое число не публикуется;
 * - знаменатель 0 — оценка null, а не 0;
 * - критическое нарушение — отдельный флаг; обнуление — только если включено в версии чек-листа.
 */
final class ScoreCalculator
{
    public const RULE = 'v1';

    /**
     * @param  list<array{weight: float, status: string, required: bool, critical: bool, evidence_valid: bool}>  $items
     * @return array{score: ?int, provisional_score: ?int, business_status: string, critical_violation: bool, notes: list<string>}
     */
    public function calculate(array $items, bool $zeroOnCritical): array
    {
        $numerator = 0.0;
        $denominator = 0.0;
        $unresolved = 0;
        $critical = false;
        $notes = [];

        foreach ($items as $item) {
            $resolved = in_array($item['status'], ['pass', 'fail'], true) && $item['evidence_valid'];
            if ($item['status'] === 'not_applicable' && $item['evidence_valid']) {
                continue;
            }
            if (! $resolved) {
                $unresolved++;

                continue;
            }
            $denominator += $item['weight'];
            if ($item['status'] === 'pass') {
                $numerator += $item['weight'];
            } elseif ($item['critical']) {
                $critical = true;
            }
        }

        $provisional = $denominator > 0 ? (int) round(100 * $numerator / $denominator) : null;
        $full = $unresolved === 0;
        $score = $full ? $provisional : null;

        if ($unresolved > 0) {
            $notes[] = "Не разрешено критериев: {$unresolved}. Итоговая оценка будет после проверки человеком.";
        }
        if ($full && $critical && $zeroOnCritical && $score !== null) {
            $score = 0;
            $notes[] = 'Оценка обнулена: критическое нарушение (правило версии чек-листа).';
        }
        if ($denominator === 0.0 && $full) {
            $notes[] = 'Нет применимых критериев — оценка не рассчитывается.';
        }

        return [
            'score' => $score,
            'provisional_score' => $provisional,
            'business_status' => $full ? EvaluationResult::FULL : EvaluationResult::INTERVENTION,
            'critical_violation' => $critical,
            'notes' => $notes,
        ];
    }
}
