<?php

namespace Tests\Feature\Api;

use App\Domain\AiProviders\Models\ProviderCall;
use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderException;
use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\ProviderRole;
use App\Domain\AiProviders\Services\ProviderSelector;
use App\Domain\CallIntake\Jobs\EvaluateCall;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\AnalysisRun;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Employee;
use Database\Seeders\DemoChecklistSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioFixtures;
use Tests\TestCase;

/**
 * Этап C на тестовом провайдере. Приёмка: таймкоды проверяемы, ошибки API не теряют звонок,
 * ответы с придуманными доказательствами отправляются на проверку.
 */
class AiPipelineTest extends TestCase
{
    use AudioFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('calls');
        $this->seed(DemoChecklistSeeder::class);
        $this->buildOrg();
        $this->admin = $this->userWithRole('company_admin', $this->companyA);
    }

    protected function tearDown(): void
    {
        $this->tearDownAudioFixtures();
        parent::tearDown();
    }

    private function mockProvider(ProviderKind $kind, string $scenario = 'normal', ProviderRole $role = ProviderRole::Primary): ProviderConfig
    {
        return ProviderConfig::create([
            'kind' => $kind, 'driver' => 'mock', 'name' => "Тест {$kind->value} {$scenario}", 'role' => $role,
            'settings' => ['mock_scenario' => $scenario, 'price_per_minute' => 1.2, 'price_per_1k_tokens_in' => 0.1, 'price_per_1k_tokens_out' => 0.2],
        ]);
    }

    private function uploadCall(float $seconds = 36.0, string $direction = 'incoming', int $frequency = 440): Call
    {
        $id = $this->actingAs($this->admin)
            ->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->post('/api/v1/calls/uploads', ['file' => $this->audioFile('mp3', $seconds, $frequency), 'department_id' => $this->salesA->id, 'direction' => $direction], ['Accept' => 'application/json'])
            ->assertCreated()->json('id');

        return Call::query()->findOrFail($id);
    }

    #[Test]
    public function full_pipeline_produces_a_synthetic_transcript_and_a_verified_evaluation(): void
    {
        $this->mockProvider(ProviderKind::Asr);
        $this->mockProvider(ProviderKind::Llm);

        $call = $this->uploadCall(36.0);
        $this->assertSame('completed', $call->status->value);

        $transcript = $this->getJson("/api/v1/calls/{$call->id}/transcript")->assertOk()
            ->assertJsonPath('current.is_synthetic', true)->json('current.segments');
        $this->assertCount(11, $transcript);
        foreach ($transcript as $s) {
            $this->assertLessThan($s['end_ms'], $s['start_ms']);
            $this->assertLessThanOrEqual($call->duration_ms, $s['end_ms'], 'Таймкоды в пределах записи');
        }

        $analysis = $this->getJson("/api/v1/calls/{$call->id}/analysis")->assertOk()
            ->assertJsonPath('current.status', 'succeeded')
            ->assertJsonPath('current.synthetic', true)
            ->assertJsonPath('current.results.0.role', 'primary')
            ->assertJsonPath('current.results.0.checklist.name', 'Входящий — продажа (DEMO)')
            ->assertJsonPath('current.results.0.checklist.is_demo', true)
            ->assertJsonPath('current.results.1.role', 'additional')
            ->json('current');

        foreach ($analysis['results'] as $result) {
            $this->assertSame('full_assessment', $result['business_status']);
            $this->assertIsInt($result['score']);
            foreach ($result['criteria'] as $criterion) {
                $this->assertTrue($criterion['evidence_valid'], $criterion['title']);
            }
        }

        $this->getJson("/api/v1/calls/{$call->id}")->assertJsonPath('evaluation.business_status', 'full_assessment')
            ->assertJsonPath('review_required', false);

        // Журнал: распознавание + по вызову на чек-лист, со стоимостью по тарифу.
        $this->assertSame(1, ProviderCall::query()->where('operation', 'transcribe')->where('status', 'ok')->count());
        $this->assertSame(2, ProviderCall::query()->where('operation', 'evaluate')->where('status', 'ok')->count());
        $this->assertEqualsWithDelta(36 / 60 * 1.2, (float) ProviderCall::query()->where('operation', 'transcribe')->value('cost'), 0.01);
    }

    #[Test]
    public function without_providers_the_call_waits_honestly(): void
    {
        $call = $this->uploadCall(5.0);
        $this->assertSame('awaiting_transcription', $call->status->value);
        $this->getJson("/api/v1/calls/{$call->id}/analysis")->assertOk()->assertJsonPath('current', null);

        $this->mockProvider(ProviderKind::Asr);
        $this->postJson("/api/v1/calls/{$call->id}/reprocess")->assertOk()->assertJsonPath('status.code', 'awaiting_evaluation');
    }

    #[Test]
    public function invented_evidence_is_rejected_and_sent_to_human_review(): void
    {
        $this->mockProvider(ProviderKind::Asr);
        $this->mockProvider(ProviderKind::Llm, 'invented_evidence');

        $call = $this->uploadCall();
        $result = $this->getJson("/api/v1/calls/{$call->id}/analysis")->json('current.results.0');

        $this->assertSame('human_intervention_required', $result['business_status']);
        $this->assertSame('pending', $result['review_state']);
        $this->assertNull($result['score'], 'Итоговое число не публикуется');
        $this->assertIsInt($result['provisional_score']);
        $this->assertFalse($result['criteria'][0]['evidence_valid']);
        $this->assertFalse($result['criteria'][0]['evidence'][0]['is_valid']);
        $this->assertSame('цитаты нет в указанной реплике.', $result['criteria'][0]['evidence'][0]['issue']);

        $this->getJson('/api/v1/calls/summary')->assertJsonPath('review_required', 1);
        $this->assertSame([$call->id], collect($this->getJson('/api/v1/calls?review_required=1')->json('data'))->pluck('id')->all());
    }

    #[Test]
    public function insufficient_data_stays_insufficient(): void
    {
        $this->mockProvider(ProviderKind::Asr);
        $this->mockProvider(ProviderKind::Llm, 'insufficient_data');

        $call = $this->uploadCall();
        $result = $this->getJson("/api/v1/calls/{$call->id}/analysis")->json('current.results.0');

        $this->assertSame('insufficient_data', $result['criteria'][0]['status']);
        $this->assertSame('human_intervention_required', $result['business_status']);
        $this->assertNull($result['score']);
    }

    #[Test]
    public function asr_timecodes_outside_the_recording_are_rejected(): void
    {
        config(['analysis.tries' => 1]);
        $this->mockProvider(ProviderKind::Asr, 'bad_timecodes');
        $this->mockProvider(ProviderKind::Llm);

        $call = $this->uploadCall(10.0);

        $this->assertSame('failed', $call->status->value);
        $this->assertStringContainsString('выходит за длительность записи', (string) $call->last_error);
        $this->getJson("/api/v1/calls/{$call->id}/transcript")->assertJsonPath('current', null);
        Storage::disk('calls')->assertExists($call->original->path);
    }

    #[Test]
    public function provider_errors_never_lose_the_call(): void
    {
        foreach (['timeout', 'server_error', 'rate_limited', 'bad_json'] as $i => $scenario) {
            ProviderConfig::query()->delete();
            config(['analysis.tries' => 1]);
            $this->mockProvider(ProviderKind::Asr);
            $this->mockProvider(ProviderKind::Llm, $scenario);

            $call = $this->uploadCall(10.0, frequency: 300 + $i * 100);

            $this->assertSame('failed', $call->status->value, $scenario);
            $this->assertNotNull($call->last_error, $scenario);
            Storage::disk('calls')->assertExists($call->original->path);
            $this->assertSame('failed', AnalysisRun::query()->where('call_id', $call->id)->latest('id')->value('status'), $scenario);
            // Сетевой сбой — ошибка в журнале вызовов; ответ не по схеме — вызов оплачен (ok), отклонён проверкой.
            $scenario === 'bad_json'
                ? $this->assertStringContainsString('не по схеме', (string) AnalysisRun::query()->where('call_id', $call->id)->latest('id')->value('error_message'))
                : $this->assertTrue(ProviderCall::query()->where('call_id', $call->id)->where('status', 'error')->exists(), $scenario);

            // После устранения сбоя звонок обрабатывается заново.
            ProviderConfig::query()->where('kind', 'llm')->update(['settings' => json_encode(['mock_scenario' => 'normal'])]);
            $this->postJson("/api/v1/calls/{$call->id}/reprocess")->assertOk()->assertJsonPath('status.code', 'completed');
        }
    }

    #[Test]
    public function malformed_json_is_retried_once_before_failing(): void
    {
        config(['analysis.tries' => 1]);
        $this->mockProvider(ProviderKind::Asr);
        $this->mockProvider(ProviderKind::Llm, 'bad_json');

        $call = $this->uploadCall(10.0);

        $this->assertSame(2, ProviderCall::query()->where('call_id', $call->id)->where('operation', 'evaluate')->count(), 'Контролируемый повтор');
        $this->assertStringContainsString('не по схеме', (string) $call->last_error);
    }

    #[Test]
    public function temporary_failure_keeps_the_call_waiting_for_retry(): void
    {
        // Звонок дошёл до оценки, провайдер оценки ещё не включён.
        $this->mockProvider(ProviderKind::Asr);
        $call = $this->uploadCall(10.0);
        $this->assertSame('awaiting_evaluation', $call->status->value);

        // Сбой 500 на первой попытке из трёх: звонок ждёт повтора, ничего не потеряно.
        $config = $this->mockProvider(ProviderKind::Llm, 'server_error');
        $job = (new EvaluateCall($call->id))->withFakeQueueInteractions();
        try {
            app()->call([$job, 'handle']);
            $this->fail('Ожидалась ошибка провайдера');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderException::SERVER_ERROR, $e->errorCode);
        }
        $job->assertNotFailed();
        $this->assertSame('awaiting_evaluation', $call->refresh()->status->value);
        $this->assertStringStartsWith('Временный сбой, будет повтор', (string) $call->last_error);

        // 429: задача откладывается на время, которое назвал провайдер.
        $config->update(['settings' => ['mock_scenario' => 'rate_limited']]);
        $job = (new EvaluateCall($call->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);
        $job->assertReleased(5);
        $this->assertSame('awaiting_evaluation', $call->refresh()->status->value);
    }

    #[Test]
    public function fallback_provider_is_used_on_the_last_attempt(): void
    {
        $primary = $this->mockProvider(ProviderKind::Llm);
        $fallback = $this->mockProvider(ProviderKind::Llm, role: ProviderRole::Fallback);
        $selector = app(ProviderSelector::class);

        $this->assertSame($primary->id, $selector->forAttempt(ProviderKind::Llm, 1, 3)?->id);
        $this->assertSame($fallback->id, $selector->forAttempt(ProviderKind::Llm, 3, 3)?->id);
    }

    #[Test]
    public function transcript_and_evaluation_follow_permissions_and_scope(): void
    {
        $this->mockProvider(ProviderKind::Asr);
        $this->mockProvider(ProviderKind::Llm);
        $call = $this->uploadCall(10.0);

        $adminB = $this->userWithRole('company_admin', $this->companyB);
        $this->actingAs($adminB)->getJson("/api/v1/calls/{$call->id}/transcript")->assertNotFound();
        $this->actingAs($adminB)->getJson("/api/v1/calls/{$call->id}/analysis")->assertNotFound();

        // Роль «Сотрудник»: оценки своих звонков — да, расшифровки — нет (нет transcript.view).
        $employee = Employee::factory()->create(['company_id' => $this->companyA->id]);
        $call->forceFill(['employee_id' => $employee->id])->save();
        $self = $this->userWithRole('employee', null, $this->salesA, ['employee_id' => $employee->id]);
        $this->actingAs($self)->getJson("/api/v1/calls/{$call->id}/analysis")->assertOk();
        $this->actingAs($self)->getJson("/api/v1/calls/{$call->id}/transcript")->assertForbidden();
    }
}
