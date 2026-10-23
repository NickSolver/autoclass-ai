<?php

namespace Tests\Unit;

use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Evaluation\Services\ScoreCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Правило оценки v1 (ТЗ, разд. 7.1). */
class ScoreCalculatorTest extends TestCase
{
    /** @return array{weight: float, status: string, required: bool, critical: bool, evidence_valid: bool} */
    private function item(string $status, float $weight = 10, bool $critical = false, bool $valid = true, bool $required = true): array
    {
        return ['weight' => $weight, 'status' => $status, 'required' => $required, 'critical' => $critical, 'evidence_valid' => $valid];
    }

    #[Test]
    public function weighted_score_excludes_not_applicable_from_denominator(): void
    {
        $r = (new ScoreCalculator)->calculate([
            $this->item('pass', 30), $this->item('fail', 10), $this->item('not_applicable', 60),
        ], false);

        $this->assertSame(75, $r['score']);
        $this->assertSame(EvaluationResult::FULL, $r['business_status']);
    }

    #[Test]
    public function insufficient_data_is_not_a_fail_and_blocks_the_final_score(): void
    {
        $r = (new ScoreCalculator)->calculate([$this->item('pass', 50), $this->item('insufficient_data', 50)], false);

        $this->assertNull($r['score'], 'Итоговое число не публикуется до решения человека');
        $this->assertSame(100, $r['provisional_score'], 'Предварительная — по разрешённым критериям, без подмены fail');
        $this->assertSame(EvaluationResult::INTERVENTION, $r['business_status']);
    }

    #[Test]
    public function rejected_evidence_requires_human_review(): void
    {
        $r = (new ScoreCalculator)->calculate([$this->item('pass', 50, valid: false), $this->item('pass', 50)], false);

        $this->assertNull($r['score']);
        $this->assertSame(EvaluationResult::INTERVENTION, $r['business_status']);
    }

    #[Test]
    public function zero_denominator_gives_null_not_zero(): void
    {
        $r = (new ScoreCalculator)->calculate([$this->item('not_applicable')], false);

        $this->assertNull($r['score']);
        $this->assertSame(EvaluationResult::FULL, $r['business_status']);
    }

    #[Test]
    public function critical_violation_is_a_flag_and_zeroes_only_when_enabled(): void
    {
        $items = [$this->item('pass', 80), $this->item('fail', 20, critical: true)];

        $default = (new ScoreCalculator)->calculate($items, false);
        $this->assertTrue($default['critical_violation']);
        $this->assertSame(80, $default['score']);

        $this->assertSame(0, (new ScoreCalculator)->calculate($items, true)['score']);
    }
}
