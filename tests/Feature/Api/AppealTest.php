<?php

namespace Tests\Feature\Api;

use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Employee;
use App\Domain\Review\Models\Appeal;
use App\Domain\Review\Models\ReviewDecision;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ProcessedCalls;
use Tests\TestCase;

/** Этап E — апелляции: подача сотрудником, независимое решение, история (ТЗ, разд. 11). */
class AppealTest extends TestCase
{
    use ProcessedCalls;

    private User $root;

    private User $employeeUser;

    private Call $call;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrg();
        $this->root = $this->superadmin();
        $employee = Employee::factory()->create(['company_id' => $this->companyA->id]);
        $this->employeeUser = $this->userWithRole('employee', null, $this->salesA, ['employee_id' => $employee->id]);
        $this->enableMockProviders();
        $this->call = $this->processedCall($this->root, $employee);
    }

    protected function tearDown(): void
    {
        $this->tearDownAudioFixtures();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function primary(User $as): array
    {
        return collect($this->actingAs($as)->getJson("/api/v1/calls/{$this->call->id}/analysis")->assertOk()->json('current.results'))->firstWhere('role', 'primary');
    }

    #[Test]
    public function employee_appeal_is_accepted_by_an_independent_controller(): void
    {
        $primary = $this->primary($this->employeeUser);
        $appeal = $this->actingAs($this->employeeUser)->postJson("/api/v1/calls/{$this->call->id}/appeals", [
            'result_id' => $primary['id'], 'reason' => 'Следующий шаг был: договорились о тест-драйве в конце звонка.',
        ])->assertCreated()->assertJsonPath('status', 'open')->assertJsonPath('can.withdraw', true)->json();
        $this->postJson("/api/v1/calls/{$this->call->id}/appeals", ['result_id' => $primary['id'], 'reason' => 'Повторная апелляция по тому же'])
            ->assertStatus(422);
        $this->getJson('/api/v1/appeals')->assertOk()->assertJsonCount(1, 'data');

        // РОП видит, но решить не может; контролёр другой компании не видит.
        $head = $this->userWithRole('head_of_department', $this->salesA);
        $this->actingAs($head)->postJson("/api/v1/appeals/{$appeal['id']}/take", ['lock_version' => 0])->assertForbidden();
        $qcB = $this->userWithRole('quality_controller', $this->companyB);
        $this->actingAs($qcB)->postJson("/api/v1/appeals/{$appeal['id']}/take", ['lock_version' => 0])->assertNotFound();

        $qc = $this->userWithRole('quality_controller', $this->companyA);
        $taken = $this->actingAs($qc)->postJson("/api/v1/appeals/{$appeal['id']}/take", ['lock_version' => 0])->assertOk()->assertJsonPath('status', 'in_review')->json();
        $criterion = collect($this->primary($qc)['criteria'])->first();
        $target = $criterion['status'] === 'pass' ? 'fail' : 'pass';
        $this->postJson("/api/v1/appeals/{$appeal['id']}/resolve", [
            'lock_version' => $taken['lock_version'], 'decision' => 'accepted', 'comment' => 'Довод подтверждён записью',
            'changes' => [['criterion_result_id' => $criterion['id'], 'status' => $target, 'comment' => 'На 0:30 договорились о визите']],
        ])->assertOk()->assertJsonPath('status', 'accepted');

        $current = EvaluationResult::query()->where('call_id', $this->call->id)->where('role', 'primary')->where('is_current', true)->firstOrFail();
        $this->assertSame(['human', $qc->id], [$current->origin, $current->created_by]);
        $this->assertNotNull(ReviewDecision::query()->where('appeal_id', $appeal['id'])->value('new_evaluation_result_id'));

        // Новая апелляция на исправленную оценку: тот, кто её выставил, решать не может.
        $second = $this->actingAs($this->employeeUser)->postJson("/api/v1/calls/{$this->call->id}/appeals", [
            'result_id' => $current->id, 'reason' => 'Не согласен с исправлением контролёра.',
        ])->assertCreated()->json();
        $this->actingAs($qc)->postJson("/api/v1/appeals/{$second['id']}/take", ['lock_version' => 0])->assertForbidden();
        $this->actingAs($this->root)->getJson("/api/v1/appeals/{$second['id']}/assignees")->assertOk()->assertJsonMissing(['id' => $qc->id]);
        $this->postJson("/api/v1/appeals/{$second['id']}/assign", ['assignee_id' => $qc->id, 'lock_version' => 0])->assertStatus(422);

        $qc2 = $this->userWithRole('quality_controller', $this->group);
        $taken = $this->actingAs($qc2)->postJson("/api/v1/appeals/{$second['id']}/take", ['lock_version' => 0])->assertOk()->json();
        $this->postJson("/api/v1/appeals/{$second['id']}/resolve", ['lock_version' => $taken['lock_version'], 'decision' => 'rejected', 'comment' => 'Исправление верное'])
            ->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertTrue($current->refresh()->is_current, 'Отклонённая апелляция не меняет оценку');

        $history = $this->primary($this->employeeUser)['history'];
        $this->assertSame(['ai', 'human'], array_column($history, 'origin'));
        $this->assertSame('rejected', $history[1]['decisions'][0]['decision']);
    }

    #[Test]
    public function author_withdraws_and_self_resolution_is_forbidden(): void
    {
        $primary = $this->primary($this->root);
        $own = $this->actingAs($this->root)->postJson("/api/v1/calls/{$this->call->id}/appeals", ['result_id' => $primary['id'], 'reason' => 'Проверка собственной апелляции.'])
            ->assertCreated()->assertJsonPath('can.take', false)->json();
        $this->postJson("/api/v1/appeals/{$own['id']}/take", ['lock_version' => 0])->assertForbidden();

        $this->actingAs($this->employeeUser)->postJson("/api/v1/appeals/{$own['id']}/withdraw", ['lock_version' => 0])->assertForbidden();
        $this->actingAs($this->root)->postJson("/api/v1/appeals/{$own['id']}/withdraw", ['lock_version' => 0])->assertOk()->assertJsonPath('status', 'withdrawn');
        $this->assertSame(0, Appeal::query()->whereIn('status', Appeal::OPEN)->count());
    }
}
