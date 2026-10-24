<?php

namespace Tests\Feature\Api;

use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\ProviderRole;
use App\Domain\Evaluation\Models\Checklist;
use App\Domain\Evaluation\Models\CriterionResult;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Identity\Models\User;
use Database\Seeders\DemoChecklistSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioFixtures;
use Tests\TestCase;

/** Этап D — конструктор чек-листов (ТЗ, разд. 7). */
class ChecklistDesignerTest extends TestCase
{
    use AudioFixtures;

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

    /** @return list<array<string, mixed>> */
    private function criteria(int $greetingWeight = 50): array
    {
        return [
            ['code' => 'greeting', 'title' => 'Приветствие', 'weight' => $greetingWeight, 'is_required' => true, 'is_critical' => false],
            ['code' => 'next_step', 'title' => 'Следующий шаг', 'weight' => 50, 'is_required' => true, 'is_critical' => true, 'ai_hint' => 'Тест-драйв или визит'],
        ];
    }

    /** @return array{id: int, draft: array<string, mixed>} */
    private function createChecklist(): array
    {
        $this->actingAs($this->root);
        $id = $this->postJson('/api/v1/checklists', ['code' => 'sales_v1', 'name' => 'Продажи'])->assertCreated()->json('id');

        return ['id' => $id, 'draft' => $this->getJson("/api/v1/checklists/{$id}")->assertOk()->json('selected')];
    }

    #[Test]
    public function draft_is_edited_published_and_then_immutable(): void
    {
        ['id' => $id, 'draft' => $draft] = $this->createChecklist();

        $saved = $this->putJson("/api/v1/checklists/{$id}/versions/{$draft['id']}", [
            'lock_version' => $draft['lock_version'], 'criteria' => $this->criteria(), 'zero_on_critical' => false,
        ])->assertOk()->assertJsonCount(2, 'criteria')->json();
        $this->putJson("/api/v1/checklists/{$id}/versions/{$draft['id']}", ['lock_version' => $draft['lock_version'], 'criteria' => []])
            ->assertStatus(422)->assertJsonValidationErrors('lock_version');

        $this->postJson("/api/v1/checklists/{$id}/versions/{$draft['id']}/publish")->assertOk()->assertJsonPath('status', 'published');
        $this->putJson("/api/v1/checklists/{$id}/versions/{$draft['id']}", ['lock_version' => $saved['lock_version'], 'criteria' => $this->criteria(10)])
            ->assertStatus(422)->assertJsonValidationErrors('version');

        $copy = $this->postJson("/api/v1/checklists/{$id}/versions")->assertCreated()->assertJsonPath('version', 2)->json();
        $this->assertSame(['greeting', 'next_step'], array_column($copy['criteria'], 'code'), 'Новый черновик — копия опубликованной');
    }

    #[Test]
    public function publication_requires_valid_criteria(): void
    {
        ['id' => $id, 'draft' => $draft] = $this->createChecklist();

        $this->postJson("/api/v1/checklists/{$id}/versions/{$draft['id']}/publish")->assertStatus(422);
        $this->putJson("/api/v1/checklists/{$id}/versions/{$draft['id']}", ['lock_version' => $draft['lock_version'], 'criteria' => [
            ['code' => 'a', 'title' => 'A', 'weight' => 10], ['code' => 'a', 'title' => 'B', 'weight' => 10],
        ]])->assertStatus(422)->assertJsonValidationErrors('criteria');
        $saved = $this->putJson("/api/v1/checklists/{$id}/versions/{$draft['id']}", ['lock_version' => $draft['lock_version'], 'criteria' => [
            ['code' => 'a', 'title' => 'A', 'weight' => 0],
        ]])->assertOk();
        $this->postJson("/api/v1/checklists/{$id}/versions/{$draft['id']}/publish")->assertStatus(422)->assertJsonValidationErrors('version');
    }

    #[Test]
    public function versions_can_be_compared(): void
    {
        ['id' => $id, 'draft' => $draft] = $this->createChecklist();
        $this->putJson("/api/v1/checklists/{$id}/versions/{$draft['id']}", ['lock_version' => $draft['lock_version'], 'criteria' => $this->criteria()])->assertOk();
        $this->postJson("/api/v1/checklists/{$id}/versions/{$draft['id']}/publish")->assertOk();
        $v2 = $this->postJson("/api/v1/checklists/{$id}/versions")->json();
        $criteria = $this->criteria(30);
        $criteria[] = ['code' => 'closing', 'title' => 'Прощание', 'weight' => 20];
        array_splice($criteria, 1, 1);
        $this->putJson("/api/v1/checklists/{$id}/versions/{$v2['id']}", ['lock_version' => $v2['lock_version'], 'criteria' => $criteria])->assertOk();

        $changes = collect($this->getJson("/api/v1/checklists/{$id}/compare?from={$draft['id']}&to={$v2['id']}")->assertOk()->json('changes'))->keyBy('code');
        $this->assertSame('changed', $changes['greeting']['change']);
        $this->assertSame('removed', $changes['next_step']['change']);
        $this->assertSame('added', $changes['closing']['change']);
    }

    #[Test]
    public function new_version_does_not_change_existing_evaluations(): void
    {
        Storage::fake('calls');
        $this->seed(DemoChecklistSeeder::class);
        ProviderConfig::create(['kind' => ProviderKind::Asr, 'driver' => 'mock', 'name' => 'A', 'role' => ProviderRole::Primary, 'settings' => ['mock_scenario' => 'normal']]);
        ProviderConfig::create(['kind' => ProviderKind::Llm, 'driver' => 'mock', 'name' => 'L', 'role' => ProviderRole::Primary, 'settings' => ['mock_scenario' => 'normal']]);

        $callId = $this->actingAs($this->root)->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->post('/api/v1/calls/uploads', ['file' => $this->audioFile('mp3', 12.0), 'department_id' => $this->salesA->id, 'direction' => 'incoming'], ['Accept' => 'application/json'])
            ->assertCreated()->json('id');
        $before = EvaluationResult::query()->where('call_id', $callId)->with('criteria')->get()->toArray();
        $sales = Checklist::query()->where('code', 'demo_incoming_sales')->firstOrFail();

        // Новая версия демо-чек-листа: другие веса и критерии.
        $draft = $this->postJson("/api/v1/checklists/{$sales->id}/versions")->assertCreated()->json();
        $this->putJson("/api/v1/checklists/{$sales->id}/versions/{$draft['id']}", ['lock_version' => $draft['lock_version'], 'criteria' => [['code' => 'only', 'title' => 'Единственный', 'weight' => 1]]])->assertOk();
        $this->postJson("/api/v1/checklists/{$sales->id}/versions/{$draft['id']}/publish")->assertOk();

        $this->assertEquals($before, EvaluationResult::query()->where('call_id', $callId)->with('criteria')->get()->toArray(), 'Старые оценки не переписаны');
        $this->assertSame(1, $sales->versions()->where('status', 'archived')->count());
        $this->assertGreaterThan(1, CriterionResult::query()->count());
    }

    #[Test]
    public function draft_can_be_tested_on_a_call_without_saving_results(): void
    {
        Storage::fake('calls');
        $this->seed(DemoChecklistSeeder::class);
        ProviderConfig::create(['kind' => ProviderKind::Asr, 'driver' => 'mock', 'name' => 'A', 'role' => ProviderRole::Primary, 'settings' => ['mock_scenario' => 'normal']]);
        ProviderConfig::create(['kind' => ProviderKind::Llm, 'driver' => 'mock', 'name' => 'L', 'role' => ProviderRole::Primary, 'settings' => ['mock_scenario' => 'normal']]);
        $callId = $this->actingAs($this->root)->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->post('/api/v1/calls/uploads', ['file' => $this->audioFile('mp3', 36.0), 'department_id' => $this->salesA->id], ['Accept' => 'application/json'])
            ->assertCreated()->json('id');
        $resultsBefore = EvaluationResult::query()->count();

        ['id' => $id, 'draft' => $draft] = $this->createChecklist();
        $this->putJson("/api/v1/checklists/{$id}/versions/{$draft['id']}", ['lock_version' => $draft['lock_version'], 'criteria' => $this->criteria()])->assertOk();

        $this->postJson("/api/v1/checklists/{$id}/versions/{$draft['id']}/test", ['call_id' => $callId])->assertOk()
            ->assertJsonPath('synthetic', true)
            ->assertJsonPath('score.business_status', 'full_assessment')
            ->assertJsonCount(2, 'criteria');
        $this->assertSame($resultsBefore, EvaluationResult::query()->count(), 'Пробная оценка не сохраняется в звонок');
    }

    #[Test]
    public function scope_and_permissions_are_enforced(): void
    {
        $adminA = $this->userWithRole('company_admin', $this->companyA);
        $this->actingAs($adminA);

        $this->postJson('/api/v1/checklists', ['code' => 'global_x', 'name' => 'Общий'])->assertForbidden();
        $own = $this->postJson('/api/v1/checklists', ['code' => 'a_only', 'name' => 'Компании A', 'company_id' => $this->companyA->id])->assertCreated()->json('id');
        $draft = $this->getJson("/api/v1/checklists/{$own}")->assertOk()->assertJsonPath('can.publish', false)->json('selected');
        $this->putJson("/api/v1/checklists/{$own}/versions/{$draft['id']}", ['lock_version' => $draft['lock_version'], 'criteria' => $this->criteria()])->assertOk();
        $this->postJson("/api/v1/checklists/{$own}/versions/{$draft['id']}/publish")->assertForbidden();

        $adminB = $this->userWithRole('company_admin', $this->companyB);
        $this->actingAs($adminB)->getJson("/api/v1/checklists/{$own}")->assertNotFound();

        $director = $this->userWithRole('director', $this->companyA);
        $this->actingAs($director)->getJson('/api/v1/checklists')->assertForbidden();
    }
}
