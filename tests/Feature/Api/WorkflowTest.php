<?php

namespace Tests\Feature\Api;

use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\ProviderRole;
use App\Domain\CallIntake\CallStatus;
use App\Domain\CallIntake\Jobs\EvaluateCall;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\Checklist;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Identity\Models\User;
use App\Domain\Transcription\Models\TranscriptVersion;
use App\Domain\Workflow\Graph\GraphValidator;
use App\Domain\Workflow\Models\WorkflowRun;
use App\Domain\Workflow\Services\WorkflowRuntime;
use Database\Seeders\DemoChecklistSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioFixtures;
use Tests\TestCase;

/**
 * Этап D — конструктор бизнес-процессов. Приёмка: администратор без изменения кода настраивает
 * «если входящий и отдел продаж → основной Продажи + дополнительный Стандарты → оценить;
 * если недостаточно данных → проверка»; новая версия не меняет уже запущенные экземпляры.
 */
class WorkflowTest extends TestCase
{
    use AudioFixtures;

    private User $root;

    private int $sales;

    private int $outgoing;

    private int $standard;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('calls');
        $this->seed(DemoChecklistSeeder::class);
        $this->buildOrg();
        $this->root = $this->superadmin();
        $this->sales = Checklist::query()->where('code', 'demo_incoming_sales')->value('id');
        $this->outgoing = Checklist::query()->where('code', 'demo_outgoing_contact')->value('id');
        $this->standard = Checklist::query()->where('code', 'demo_communication_standard')->value('id');
    }

    protected function tearDown(): void
    {
        $this->tearDownAudioFixtures();
        parent::tearDown();
    }

    /** Граф из ТЗ: входящий + продажи → Продажи + Стандарты → анализ → вмешательство? → задача. */
    private function acceptanceGraph(?int $primary = null): array
    {
        $n = fn (string $id, string $type, array $data = []) => ['id' => $id, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'data' => $data];
        $e = fn (string $s, string $t, string $h = 'out') => ['id' => "{$s}-{$h}-{$t}", 'source' => $s, 'sourceHandle' => $h, 'target' => $t];

        return [
            'nodes' => [
                $n('start', 'start', ['trigger' => 'transcription_completed']),
                $n('is_sales', 'condition', ['match' => 'all', 'rules' => [
                    ['field' => 'direction', 'operator' => 'eq', 'value' => 'incoming'],
                    ['field' => 'department_kind', 'operator' => 'eq', 'value' => 'sales'],
                ]]),
                $n('primary_sales', 'select_primary', ['checklist_id' => $primary ?? $this->sales]),
                $n('standards', 'add_additional', ['checklist_ids' => [$this->standard]]),
                $n('analyze', 'run_analysis'),
                $n('needs_human', 'condition', ['match' => 'any', 'rules' => [['field' => 'any_intervention', 'operator' => 'is_true']]]),
                $n('review', 'create_review_task', ['reason' => 'Недостаточно данных — проверить вручную']),
                $n('label', 'set_label', ['label' => 'продажи']),
                $n('primary_other', 'select_primary', ['checklist_id' => $this->outgoing]),
                $n('analyze_other', 'run_analysis'),
                $n('end', 'end'),
            ],
            'edges' => [
                $e('start', 'is_sales'),
                $e('is_sales', 'primary_sales', 'true'), $e('is_sales', 'primary_other', 'false'),
                $e('primary_sales', 'standards'), $e('standards', 'label'), $e('label', 'analyze'), $e('analyze', 'needs_human'),
                $e('needs_human', 'review', 'true'), $e('needs_human', 'end', 'false'), $e('review', 'end'),
                $e('primary_other', 'analyze_other'), $e('analyze_other', 'end'),
            ],
        ];
    }

    private function publishWorkflow(array $graph, ?int $companyId = null): array
    {
        $this->actingAs($this->root);
        $id = $this->postJson('/api/v1/workflows', ['name' => 'Маршрутизация', 'company_id' => $companyId])->assertCreated()->json('id');
        $draft = $this->getJson("/api/v1/workflows/{$id}")->json('selected');
        $this->putJson("/api/v1/workflows/{$id}/versions/{$draft['id']}", ['graph' => $graph, 'lock_version' => $draft['lock_version']])->assertOk();
        $this->postJson("/api/v1/workflows/{$id}/versions/{$draft['id']}/publish")->assertOk()->assertJsonPath('status', 'published');

        return ['id' => $id, 'version_id' => $draft['id']];
    }

    private function providers(string $llmScenario = 'normal'): void
    {
        ProviderConfig::create(['kind' => ProviderKind::Asr, 'driver' => 'mock', 'name' => 'ASR', 'role' => ProviderRole::Primary, 'settings' => ['mock_scenario' => 'normal']]);
        ProviderConfig::create(['kind' => ProviderKind::Llm, 'driver' => 'mock', 'name' => 'LLM', 'role' => ProviderRole::Primary, 'settings' => ['mock_scenario' => $llmScenario]]);
    }

    private function upload(string $direction, int $frequency = 440): Call
    {
        $id = $this->actingAs($this->root)->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->post('/api/v1/calls/uploads', ['file' => $this->audioFile('mp3', 12.0, $frequency), 'department_id' => $this->salesA->id, 'direction' => $direction], ['Accept' => 'application/json'])
            ->assertCreated()->json('id');

        return Call::query()->findOrFail($id);
    }

    #[Test]
    public function acceptance_scenario_is_configured_without_code(): void
    {
        $this->publishWorkflow($this->acceptanceGraph());
        $this->providers('insufficient_data');

        $incoming = $this->upload('incoming', 500);
        $this->assertSame(CallStatus::Completed, $incoming->status);
        $results = EvaluationResult::query()->where('call_id', $incoming->id)->with('checklistVersion')->get();
        $this->assertSame([$this->sales], $results->where('role', 'primary')->pluck('checklistVersion.checklist_id')->all());
        $this->assertSame([$this->standard], $results->where('role', 'additional')->pluck('checklistVersion.checklist_id')->all());
        $this->assertTrue($incoming->review_required);
        $this->assertSame('Недостаточно данных — проверить вручную', $incoming->review_reason);
        $this->assertSame(['продажи'], $incoming->labels);

        $run = WorkflowRun::query()->where('call_id', $incoming->id)->with('nodeRuns')->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertSame(['start', 'is_sales', 'primary_sales', 'standards', 'label', 'analyze', 'needs_human', 'review', 'end'], $run->nodeRuns->pluck('node_id')->all());
        $this->assertSame('true', $run->nodeRuns->firstWhere('node_id', 'is_sales')->branch);

        // Исходящий — другая ветка, без дополнительных чек-листов и меток.
        $outgoing = $this->upload('outgoing', 600);
        $primary = EvaluationResult::query()->where('call_id', $outgoing->id)->where('role', 'primary')->with('checklistVersion')->firstOrFail();
        $this->assertSame($this->outgoing, $primary->checklistVersion->checklist_id);
        $this->assertSame(1, EvaluationResult::query()->where('call_id', $outgoing->id)->count());
        $this->assertNull($outgoing->labels);
    }

    #[Test]
    public function running_instance_keeps_its_version_after_a_new_publication(): void
    {
        $workflow = $this->publishWorkflow($this->acceptanceGraph());
        $call = Call::factory()->inDepartment($this->salesA)->create(['status' => CallStatus::AwaitingEvaluation, 'direction' => 'incoming']);
        $transcript = TranscriptVersion::create(['call_id' => $call->id, 'version' => 1, 'source' => 'asr', 'is_synthetic' => true, 'created_at' => now()]);
        $transcript->segments()->create(['position' => 0, 'start_ms' => 0, 'end_ms' => 2000, 'speaker' => 'S1', 'speaker_role' => 'agent', 'text' => 'Здравствуйте, меня зовут Анна.']);

        // Экземпляр запущен на версии 1 и ждёт анализа.
        $run = app(WorkflowRuntime::class)->start($call, $transcript);
        $this->assertSame('waiting_analysis', $run->status);

        // Публикуется версия 2: основной чек-лист для продаж — другой.
        $this->actingAs($this->root);
        $draft = $this->postJson("/api/v1/workflows/{$workflow['id']}/versions")->assertCreated()->json();
        $this->putJson("/api/v1/workflows/{$workflow['id']}/versions/{$draft['id']}", ['graph' => $this->acceptanceGraph($this->outgoing), 'lock_version' => $draft['lock_version']])->assertOk();
        $this->postJson("/api/v1/workflows/{$workflow['id']}/versions/{$draft['id']}/publish")->assertOk();

        // Экземпляр продолжает версию 1.
        $this->providers();
        app()->call([new EvaluateCall($call->id), 'handle']);

        $this->assertSame($workflow['version_id'], $run->refresh()->workflow_version_id);
        $this->assertSame('completed', $run->status);
        $primary = EvaluationResult::query()->where('call_id', $call->id)->where('role', 'primary')->with('checklistVersion')->firstOrFail();
        $this->assertSame($this->sales, $primary->checklistVersion->checklist_id, 'Чек-лист из версии 1, а не из новой публикации');
    }

    #[Test]
    public function invalid_graphs_are_rejected_with_reasons(): void
    {
        $validator = app(GraphValidator::class);
        $valid = $this->acceptanceGraph();
        $this->assertSame([], $validator->validate($valid, null));

        $mutate = function (callable $change) use ($valid): array {
            $g = $valid;
            $change($g);

            return $g;
        };
        $edge = fn (string $s, string $t, string $h = 'out') => ['id' => Str::random(6), 'source' => $s, 'sourceHandle' => $h, 'target' => $t];

        $cases = [
            'цикл' => [$mutate(function (&$g) use ($edge) {
                $g['edges'] = array_values(array_filter($g['edges'], fn ($e) => ! ($e['source'] === 'review')));
                $g['edges'][] = $edge('review', 'is_sales');
            }), 'цикл'],
            'недостижимый узел' => [$mutate(fn (&$g) => $g['nodes'][] = ['id' => 'orphan', 'type' => 'end', 'data' => []]), 'недостижим'],
            'неподключённый выход' => [$mutate(fn (&$g) => $g['edges'] = array_values(array_filter($g['edges'], fn ($e) => $e['sourceHandle'] !== 'false'))), 'не подключён'],
            'анализ без основного' => [$mutate(function (&$g) use ($edge) {
                $g['edges'] = array_values(array_filter($g['edges'], fn ($e) => $e['source'] !== 'is_sales' || $e['sourceHandle'] !== 'false'));
                $g['edges'][] = $edge('is_sales', 'analyze_other', 'false');
                $g['nodes'] = array_values(array_filter($g['nodes'], fn ($n) => $n['id'] !== 'primary_other'));
                $g['edges'] = array_values(array_filter($g['edges'], fn ($e) => $e['source'] !== 'primary_other'));
            }), 'нужен основной чек-лист'],
            'поле результата до анализа' => [$mutate(fn (&$g) => $g['nodes'][1]['data']['rules'][] = ['field' => 'primary_score', 'operator' => 'gt', 'value' => 50]), 'только после «Запустить анализ»'],
            'поле не из списка' => [$mutate(fn (&$g) => $g['nodes'][1]['data']['rules'][] = ['field' => 'php_eval', 'operator' => 'eq', 'value' => 'x']), 'выберите поле из списка'],
            'оператор не подходит' => [$mutate(fn (&$g) => $g['nodes'][1]['data']['rules'][0]['operator'] = 'gt'), 'оператор не подходит'],
            'недоступный узел' => [$mutate(fn (&$g) => $g['nodes'][6]['type'] = 'notify'), 'пока недоступен'],
            'два старта' => [$mutate(fn (&$g) => $g['nodes'][] = ['id' => 'start2', 'type' => 'start', 'data' => ['trigger' => 'transcription_completed']]), 'ровно один узел «Старт»'],
        ];

        foreach ($cases as $label => [$graph, $expected]) {
            $messages = implode(' | ', array_column($validator->validate($graph, null), 'message'));
            $this->assertStringContainsString($expected, $messages, $label);
        }
    }

    #[Test]
    public function checklist_and_org_units_must_belong_to_the_workflow_scope(): void
    {
        $foreign = Checklist::create(['code' => 'b_only', 'name' => 'Чек-лист компании B', 'company_id' => $this->companyB->id]);
        $foreign->versions()->create(['version' => 1, 'status' => 'published', 'published_at' => now()])->criteria()->create(['code' => 'x', 'position' => 0, 'title' => 'X', 'weight' => 1]);
        $graph = $this->acceptanceGraph($foreign->id);
        $graph['nodes'][1]['data']['rules'][] = ['field' => 'department_id', 'operator' => 'eq', 'value' => $this->salesB->id];

        $messages = implode(' | ', array_column(app(GraphValidator::class)->validate($graph, $this->companyA->id), 'message'));
        $this->assertStringContainsString('принадлежит другой компании', $messages);
        $this->assertStringContainsString('недопустимое значение', $messages);
    }

    #[Test]
    public function publication_is_validated_and_published_versions_are_immutable(): void
    {
        $this->actingAs($this->root);
        $id = $this->postJson('/api/v1/workflows', ['name' => 'Пустой'])->assertCreated()->json('id');
        $draft = $this->getJson("/api/v1/workflows/{$id}")->json('selected');

        // Новый процесс («Старт» → «Завершение») валиден, но с битым графом публикация отклоняется.
        $broken = ['nodes' => [['id' => 's', 'type' => 'start', 'data' => ['trigger' => 'transcription_completed']]], 'edges' => []];
        $saved = $this->putJson("/api/v1/workflows/{$id}/versions/{$draft['id']}", ['graph' => $broken, 'lock_version' => $draft['lock_version']])->assertOk()->json();
        $this->postJson("/api/v1/workflows/{$id}/versions/{$draft['id']}/validate")->assertOk()->assertJsonCount(2, 'errors');
        $this->postJson("/api/v1/workflows/{$id}/versions/{$draft['id']}/publish")->assertStatus(422)->assertJsonValidationErrors('graph');

        // Оптимистическая блокировка черновика.
        $this->putJson("/api/v1/workflows/{$id}/versions/{$draft['id']}", ['graph' => $broken, 'lock_version' => $draft['lock_version']])
            ->assertStatus(422)->assertJsonValidationErrors('lock_version');

        $this->putJson("/api/v1/workflows/{$id}/versions/{$draft['id']}", ['graph' => $this->acceptanceGraph(), 'lock_version' => $saved['lock_version']])->assertOk();
        $this->postJson("/api/v1/workflows/{$id}/versions/{$draft['id']}/publish")->assertOk();
        $this->putJson("/api/v1/workflows/{$id}/versions/{$draft['id']}", ['graph' => $broken, 'lock_version' => $saved['lock_version'] + 1])
            ->assertStatus(422)->assertJsonValidationErrors('version');

        $copy = $this->postJson("/api/v1/workflows/{$id}/versions")->assertCreated()->assertJsonPath('version', 2)->json();
        $this->assertEquals($this->acceptanceGraph(), $copy['graph'], 'Новый черновик — копия опубликованной');
        $this->postJson("/api/v1/workflows/{$id}/versions")->assertStatus(422);
    }

    #[Test]
    public function simulation_shows_the_route_without_side_effects(): void
    {
        $workflow = $this->publishWorkflow($this->acceptanceGraph());

        $result = $this->postJson("/api/v1/workflows/{$workflow['id']}/versions/{$workflow['version_id']}/simulate", ['input' => [
            'direction' => 'incoming', 'department_kind' => 'sales', 'any_intervention' => true,
        ]])->assertOk()->json();

        $this->assertTrue($result['finished']);
        $this->assertSame(['start', 'is_sales', 'primary_sales', 'standards', 'label', 'analyze', 'needs_human', 'review', 'end'], array_column($result['trace'], 'node_id'));
        $this->assertSame($this->sales, $result['plan']['primary_checklist_id']);
        $this->assertSame(['Недостаточно данных — проверить вручную'], $result['plan']['review_reasons']);
        $this->assertSame(0, WorkflowRun::query()->count());

        $other = $this->postJson("/api/v1/workflows/{$workflow['id']}/versions/{$workflow['version_id']}/simulate", ['input' => ['direction' => 'outgoing']])->json();
        $this->assertSame($this->outgoing, $other['plan']['primary_checklist_id']);
    }

    #[Test]
    public function company_process_wins_over_group_process_and_scope_is_enforced(): void
    {
        $this->publishWorkflow($this->acceptanceGraph());
        $companyGraph = $this->acceptanceGraph($this->outgoing);
        $this->publishWorkflow($companyGraph, $this->companyA->id);

        $call = Call::factory()->inDepartment($this->salesA)->create(['direction' => 'incoming']);
        $this->assertNotNull(app(WorkflowRuntime::class)->versionFor($call)?->definition->company_id, 'Процесс компании важнее общего');

        $adminA = $this->userWithRole('company_admin', $this->companyA);
        $this->actingAs($adminA);
        $this->postJson('/api/v1/workflows', ['name' => 'Общий'])->assertForbidden();
        $own = $this->postJson('/api/v1/workflows', ['name' => 'Мой', 'company_id' => $this->companyA->id])->assertCreated()->json('id');
        $this->postJson('/api/v1/workflows', ['name' => 'Чужой', 'company_id' => $this->companyB->id])->assertForbidden();
        $draft = $this->getJson("/api/v1/workflows/{$own}")->assertOk()->assertJsonPath('can.publish', false)->json('selected');
        $this->postJson("/api/v1/workflows/{$own}/versions/{$draft['id']}/publish")->assertForbidden();

        $adminB = $this->userWithRole('company_admin', $this->companyB);
        $this->actingAs($adminB)->getJson("/api/v1/workflows/{$own}")->assertNotFound();
        $this->assertNotContains($own, collect($this->getJson('/api/v1/workflows')->json('data'))->pluck('id'));

        // Справочники палитры — только своя область.
        $catalog = $this->getJson('/api/v1/workflows/catalog')->assertOk();
        $this->assertSame([$this->companyB->id], collect($catalog->json('org.company'))->pluck('id')->all());
        $this->assertNotContains($this->salesA->id, collect($catalog->json('org.department'))->pluck('id'));
        $this->getJson('/api/v1/workflows/catalog?company_id='.$this->companyA->id)->assertNotFound();
    }
}
