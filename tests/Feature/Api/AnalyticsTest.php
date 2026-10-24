<?php

namespace Tests\Feature\Api;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Identity\Models\User;
use App\Domain\Review\Models\ManualReview;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProcessedCalls;
use Tests\TestCase;

/** Этап E — аналитика с честными знаменателями (ТЗ, разд. 10, 16). */
class AnalyticsTest extends TestCase
{
    use ProcessedCalls;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrg();
        $this->root = $this->superadmin();
    }

    protected function tearDown(): void
    {
        $this->tearDownAudioFixtures();
        parent::tearDown();
    }

    #[Test]
    public function unknown_scores_do_not_distort_the_average_and_corrections_are_distinguishable(): void
    {
        $this->enableMockProviders();
        $scored = $this->processedCall($this->root);
        $this->setLlmScenario('insufficient_data');
        $unscored = $this->processedCall($this->root, null, 40.0);
        $excluded = $this->processedCall($this->root, null, 44.0);
        $this->actingAs($this->root)->postJson("/api/v1/calls/{$excluded->id}/exclude", ['reason' => 'Тестовый звонок'])->assertOk();

        $aiScore = EvaluationResult::query()->where('call_id', $scored->id)->where('role', 'primary')->value('score');
        $this->assertNotNull($aiScore);

        $report = $this->getJson('/api/v1/analytics')->assertOk()->json();
        $this->assertSame(3, $report['volume']['total']);
        $this->assertSame(1, $report['volume']['excluded']);
        $this->assertSame(2, $report['quality']['evaluated']);
        $this->assertSame(1, $report['quality']['scored']);
        $this->assertSame(1, $report['quality']['unscored']);
        $this->assertEquals($aiScore, $report['quality']['average'], 'Звонок без оценки не превращается в 0');
        $this->assertSame(['ai' => 1, 'human_confirmed' => 0, 'human_corrected' => 0], $report['quality']['finality']);
        $this->assertNotEmpty($report['checklists']['additional'], 'Дополнительные — отдельной таблицей');
        $this->assertSame(2, array_sum(array_column($report['checklists']['primary'], 'evaluated')));
        $this->assertNull($report['conversion']['crm_confirmed_conversion'], 'Без CRM — нет данных, а не 0');
        $this->assertSame(1, $report['review']['pending']);

        // Контролёр разрешает критерии — звонок получает оценку, итоговость «исправлено человеком».
        $qc = $this->userWithRole('quality_controller', $this->group);
        $review = ManualReview::query()->where('call_id', $unscored->id)->firstOrFail();
        $this->actingAs($qc)->postJson("/api/v1/review-queue/{$review->id}/take", ['lock_version' => 0])->assertOk();
        $results = $this->getJson("/api/v1/calls/{$unscored->id}/analysis")->json('current.results');
        $decisions = array_map(fn (array $r) => [
            'result_id' => $r['id'], 'decision' => 'corrected',
            'changes' => array_values(array_map(fn (array $c) => ['criterion_result_id' => $c['id'], 'status' => 'fail', 'comment' => 'Не выполнено'],
                array_filter($r['criteria'], fn (array $c) => $c['status'] === 'insufficient_data'))),
        ], $results);
        $this->postJson("/api/v1/review-queue/{$review->id}/resolve", ['lock_version' => 1, 'comment' => 'Проверено', 'decisions' => $decisions])->assertOk();

        $after = $this->actingAs($this->root)->getJson('/api/v1/analytics?group_by=department')->assertOk()->json();
        $this->assertSame(2, $after['quality']['scored']);
        $this->assertSame(1, $after['quality']['finality']['human_corrected']);
        $this->assertSame($this->salesA->id, $after['breakdown']['rows'][0]['id']);
        $this->assertSame(3, $after['breakdown']['rows'][0]['calls']);
    }

    #[Test]
    public function analytics_and_export_respect_scope_and_permissions(): void
    {
        $this->enableMockProviders();
        $this->processedCall($this->root);

        $directorB = $this->userWithRole('director', $this->companyB);
        $this->actingAs($directorB)->getJson('/api/v1/analytics')->assertOk()->assertJsonPath('volume.total', 0);
        $this->getJson('/api/v1/analytics?company_id='.$this->companyA->id)->assertOk()->assertJsonPath('volume.total', 0);

        $head = $this->userWithRole('head_of_department', $this->salesA);
        $this->actingAs($head)->getJson('/api/v1/analytics')->assertOk()->assertJsonPath('volume.total', 1)->assertJsonPath('can.export', false);
        $this->get('/api/v1/analytics/export')->assertForbidden();

        $director = $this->userWithRole('director', $this->companyA);
        $csv = $this->actingAs($director)->get('/api/v1/analytics/export')->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith("\xEF\xBB\xBFЗвонок;Дата", $lines[0]);
        $this->assertStringContainsString('[DEMO]', $lines[1]);
        $this->assertTrue(AuditEvent::query()->where('action', 'analytics.exported')->where('actor_user_id', $director->id)->exists());

        $this->getJson('/api/v1/analytics?from=2026-01-10&to=2026-01-01')->assertStatus(422);
    }
}
