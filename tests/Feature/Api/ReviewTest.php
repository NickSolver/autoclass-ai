<?php

namespace Tests\Feature\Api;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Identity\Models\User;
use App\Domain\Review\Models\ManualReview;
use App\Domain\Transcription\Models\TranscriptVersion;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProcessedCalls;
use Tests\TestCase;

/** Этап E — ручная проверка и правки (ТЗ, разд. 11, 16). */
class ReviewTest extends TestCase
{
    use ProcessedCalls;

    private User $root;

    private User $qc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrg();
        $this->root = $this->superadmin();
        $this->qc = $this->userWithRole('quality_controller', $this->companyA);
    }

    protected function tearDown(): void
    {
        $this->tearDownAudioFixtures();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function review(Call $call): array
    {
        return $this->actingAs($this->qc)->getJson("/api/v1/calls/{$call->id}/analysis")->assertOk()->json('review');
    }

    #[Test]
    public function controller_corrects_result_as_a_new_revision_and_ai_result_stays(): void
    {
        $this->enableMockProviders('insufficient_data');
        $call = $this->processedCall($this->root);
        $this->assertTrue($call->review_required);
        $this->assertSame(1, ManualReview::query()->where('call_id', $call->id)->where('source', 'ai')->where('status', 'pending')->count());

        $this->actingAs($this->qc)->getJson('/api/v1/review-queue')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can.take', true);
        $review = $this->review($call);
        $this->postJson("/api/v1/review-queue/{$review['id']}/take", ['lock_version' => $review['lock_version']])->assertOk()->assertJsonPath('status', 'in_progress');

        $analysis = $this->getJson("/api/v1/calls/{$call->id}/analysis")->json();
        $results = $analysis['current']['results'];
        $primary = collect($results)->firstWhere('role', 'primary');
        $unresolved = collect($primary['criteria'])->firstWhere('status', 'insufficient_data');
        $decisions = array_map(fn (array $r) => $r['role'] === 'primary'
            ? ['result_id' => $r['id'], 'decision' => 'corrected', 'changes' => [['criterion_result_id' => $unresolved['id'], 'status' => 'pass', 'comment' => 'Приветствие слышно на 0:02']]]
            : ['result_id' => $r['id'], 'decision' => 'confirmed'], $results);

        $this->postJson("/api/v1/review-queue/{$analysis['review']['id']}/resolve", ['lock_version' => $analysis['review']['lock_version'], 'comment' => 'Прослушал запись', 'decisions' => []])
            ->assertStatus(422)->assertJsonValidationErrors('decisions');
        $this->postJson("/api/v1/review-queue/{$analysis['review']['id']}/resolve", ['lock_version' => $analysis['review']['lock_version'], 'comment' => 'Прослушал запись', 'decisions' => $decisions])
            ->assertOk()->assertJsonPath('status', 'resolved')->assertJsonPath('resolution', 'corrected');

        // Результат ИИ не изменён; итоговый — ревизия человека с полной оценкой.
        $ai = EvaluationResult::query()->findOrFail($primary['id']);
        $this->assertFalse($ai->is_current);
        $this->assertSame('ai', $ai->origin);
        $this->assertNull($ai->score);
        $this->assertSame('insufficient_data', $ai->criteria()->whereKey($unresolved['id'])->value('status'));
        $human = EvaluationResult::query()->where('supersedes_id', $ai->id)->firstOrFail();
        $this->assertSame(['human', 2, true, EvaluationResult::FULL, 'human_overridden'], [$human->origin, $human->revision, $human->is_current, $human->business_status, $human->approval_state]);
        $this->assertNotNull($human->score);
        $this->assertFalse($call->refresh()->review_required);

        $after = $this->getJson("/api/v1/calls/{$call->id}/analysis")->json('current.results');
        $primaryAfter = collect($after)->firstWhere('role', 'primary');
        $this->assertSame('human', $primaryAfter['origin']);
        $this->assertCount(2, $primaryAfter['history']);
        $this->assertSame('pass', $primaryAfter['history'][0]['decisions'][0]['changes'][0]['to']);
        $this->assertSame('human_confirmed', collect($after)->firstWhere('role', 'additional')['approval_state']);

        $audit = AuditEvent::query()->where('action', 'evaluation.corrected')->firstOrFail();
        $this->assertSame('Прослушал запись', $audit->reason);
        $this->assertSame($this->qc->id, $audit->actor_user_id);
        $this->assertNotEmpty($audit->changes['score'] ?? null, 'В журнале — старое и новое значение');
    }

    #[Test]
    public function manual_request_assignment_and_cancellation(): void
    {
        $this->enableMockProviders();
        $call = $this->processedCall($this->root);
        $this->assertSame(0, ManualReview::query()->count(), 'Полная оценка без задач процесса — без проверки');

        $director = $this->userWithRole('director', $this->companyA);
        $created = $this->actingAs($director)->postJson("/api/v1/calls/{$call->id}/reviews", ['reason' => 'Жалоба клиента'])->assertCreated()
            ->assertJsonPath('source', 'manual')->json();
        $this->postJson("/api/v1/calls/{$call->id}/reviews", ['reason' => 'Ещё раз'])->assertStatus(422);
        $this->postJson("/api/v1/review-queue/{$created['id']}/take", ['lock_version' => 0])->assertForbidden();

        $this->getJson("/api/v1/review-queue/{$created['id']}/assignees")->assertOk()->assertJsonFragment(['id' => $this->qc->id])->assertJsonMissing(['id' => $director->id]);
        $this->postJson("/api/v1/review-queue/{$created['id']}/assign", ['assignee_id' => $director->id, 'lock_version' => 0])->assertStatus(422)->assertJsonValidationErrors('assignee_id');
        $assigned = $this->postJson("/api/v1/review-queue/{$created['id']}/assign", ['assignee_id' => $this->qc->id, 'lock_version' => 0])->assertOk()->json();
        $this->postJson("/api/v1/review-queue/{$created['id']}/assign", ['assignee_id' => $this->qc->id, 'lock_version' => 0])->assertStatus(422)->assertJsonValidationErrors('lock_version');

        $otherQc = $this->userWithRole('quality_controller', $this->group);
        $this->actingAs($otherQc)->postJson("/api/v1/review-queue/{$created['id']}/take", ['lock_version' => $assigned['lock_version']])->assertStatus(422);

        $this->actingAs($director)->postJson("/api/v1/review-queue/{$created['id']}/cancel", ['reason' => 'Ошибочно', 'lock_version' => $assigned['lock_version']])
            ->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertFalse($call->refresh()->review_required);
    }

    #[Test]
    public function reviews_are_scoped_and_reprocessing_cancels_open_ones(): void
    {
        $this->enableMockProviders('insufficient_data');
        $call = $this->processedCall($this->root);
        $review = ManualReview::query()->where('call_id', $call->id)->firstOrFail();

        $qcB = $this->userWithRole('quality_controller', $this->companyB);
        $this->actingAs($qcB)->getJson('/api/v1/review-queue')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/review-queue/{$review->id}/take", ['lock_version' => 0])->assertNotFound();

        $this->actingAs($this->root)->postJson("/api/v1/calls/{$call->id}/reprocess")->assertOk();
        $this->assertSame('cancelled', $review->refresh()->status);
        $this->assertSame(1, ManualReview::query()->where('call_id', $call->id)->whereIn('status', ManualReview::OPEN)->count(), 'Новая проверка по новому анализу');
    }

    #[Test]
    public function transcript_correction_creates_a_new_version(): void
    {
        $this->enableMockProviders();
        $call = $this->processedCall($this->root);
        $v1 = TranscriptVersion::query()->where('call_id', $call->id)->firstOrFail();
        $segment = $v1->segments()->firstOrFail();

        $employeeUser = $this->userWithRole('head_of_department', $this->salesA);
        $this->actingAs($employeeUser)->postJson("/api/v1/calls/{$call->id}/transcript/corrections", [
            'version' => 1, 'reason' => 'Опечатка', 'segments' => [['id' => $segment->id, 'text' => 'Исправлено']],
        ])->assertForbidden();

        $this->actingAs($this->qc)->postJson("/api/v1/calls/{$call->id}/transcript/corrections", [
            'version' => 1, 'reason' => 'Неверно распознано имя', 'segments' => [['id' => $segment->id, 'text' => 'Здравствуйте, меня зовут Ольга.', 'speaker_role' => 'agent']],
        ])->assertCreated()->assertJsonPath('version', 2);
        $this->postJson("/api/v1/calls/{$call->id}/transcript/corrections", [
            'version' => 1, 'reason' => 'Ещё', 'segments' => [['id' => $segment->id, 'text' => 'X']],
        ])->assertStatus(422)->assertJsonValidationErrors('version');

        $this->getJson("/api/v1/calls/{$call->id}/transcript")->assertOk()
            ->assertJsonPath('current.source', 'human')->assertJsonPath('current.reason', 'Неверно распознано имя')
            ->assertJsonPath('current.segments.0.text', 'Здравствуйте, меня зовут Ольга.');
        $this->assertSame($segment->text, $segment->refresh()->text, 'Прежняя версия не изменена');
        $this->assertTrue(AuditEvent::query()->where('action', 'transcript.corrected')->exists());
    }
}
