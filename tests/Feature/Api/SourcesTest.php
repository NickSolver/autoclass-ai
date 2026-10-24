<?php

namespace Tests\Feature\Api;

use App\Domain\CallIntake\CallStatus;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Employee;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Models\SourceEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Этап F (каркас): источники звонков по единому договору адаптера (ТЗ, разд. 4.1, 16). */
class SourcesTest extends TestCase
{
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('calls');
        $this->buildOrg();
        $this->root = $this->superadmin();
    }

    /** @param array<string, mixed> $settings */
    private function connection(array $settings = []): array
    {
        return $this->actingAs($this->root)->postJson('/api/v1/source-connections', [
            'driver' => 'mock', 'name' => 'Тест', 'company_id' => $this->companyA->id, 'department_id' => $this->salesA->id,
            'settings' => ['scenario' => 'normal', 'calls_per_batch' => 3, 'total_calls' => 7, 'extensions' => '101', ...$settings],
        ])->assertCreated()->json();
    }

    private function enable(array $c): array
    {
        return $this->patchJson("/api/v1/source-connections/{$c['id']}", ['lock_version' => $c['lock_version'], 'is_enabled' => true])->assertOk()->json();
    }

    #[Test]
    public function only_documented_adapters_can_be_connected(): void
    {
        $drivers = collect($this->actingAs($this->root)->getJson('/api/v1/source-drivers')->assertOk()->json('data'))->keyBy('code');
        $this->assertTrue($drivers['mock']['available']);
        $this->assertFalse($drivers['calltouch']['available']);
        $this->assertFalse($drivers['lyagushka']['available']);

        $this->postJson('/api/v1/source-connections', ['driver' => 'calltouch', 'name' => 'CT', 'company_id' => $this->companyA->id])
            ->assertStatus(422)->assertJsonValidationErrors('driver');
        $this->postJson('/api/v1/source-connections', ['driver' => 'mock', 'name' => 'X', 'company_id' => $this->companyA->id, 'settings' => ['scenario' => 'x', 'calls_per_batch' => 99, 'total_calls' => 1]])
            ->assertStatus(422)->assertJsonValidationErrors(['settings.scenario', 'settings.calls_per_batch']);
    }

    #[Test]
    public function pull_sync_imports_calls_once_and_matches_employees(): void
    {
        $employee = Employee::factory()->create(['company_id' => $this->companyA->id, 'phone_extension' => '101']);
        $c = $this->connection();

        $run = $this->postJson("/api/v1/source-connections/{$c['id']}/sync")->assertStatus(202)->json('connection.runs.0');
        $this->assertSame(['succeeded', 7, 7, 0], [$run['status'], $run['fetched'], $run['created'], $run['duplicates']]);
        $calls = Call::query()->where('source_connection_id', $c['id'])->get();
        $this->assertCount(7, $calls);
        $first = $calls->firstWhere('external_id', "{$c['id']}:mock-1");
        $this->assertSame([$employee->id, $this->salesA->id, '•••0001'], [$first->employee_id, $first->department_id, $first->client_phone_masked]);
        $this->assertNotNull($first->original, 'Запись принята в приватное хранилище');
        $this->assertSame('7', SourceConnection::query()->find($c['id'])->cursor);

        // Повтор с начала (сброс курсора) не создаёт дублей.
        DB::table('source_connections')->where('id', $c['id'])->update(['cursor' => null]);
        $again = $this->postJson("/api/v1/source-connections/{$c['id']}/sync")->assertStatus(202)->json('connection.runs.0');
        $this->assertSame([7, 0, 7], [$again['fetched'], $again['created'], $again['duplicates']]);
        $this->assertSame(7, Call::query()->where('source_connection_id', $c['id'])->count());

        // Сырой payload хранится зашифрованным: номера клиента в открытом виде в БД нет.
        $raw = DB::table('source_events')->where('source_connection_id', $c['id'])->value('payload');
        $this->assertStringNotContainsString('+79000000001', (string) $raw);
        $this->assertSame('+79000000001', SourceEvent::query()->where('source_connection_id', $c['id'])->firstOrFail()->payload['client_phone']);
    }

    #[Test]
    public function missing_recordings_and_bad_payloads_are_logged_not_lost(): void
    {
        $c = $this->connection(['scenario' => 'no_recording', 'total_calls' => 3]);
        $run = $this->postJson("/api/v1/source-connections/{$c['id']}/sync")->json('connection.runs.0');
        $this->assertSame(1, $run['without_recording']);
        $missed = Call::query()->where('external_id', "{$c['id']}:mock-3")->firstOrFail();
        $this->assertSame(CallStatus::AwaitingAudio, $missed->status);
        $this->assertNull($missed->employee_id, 'Без добавочного сотрудник не подставляется');

        $bad = $this->connection(['scenario' => 'bad_payload', 'total_calls' => 2]);
        $run = $this->postJson("/api/v1/source-connections/{$bad['id']}/sync")->json('connection.runs.0');
        $this->assertSame(['partial', 1, 1], [$run['status'], $run['failed'], $run['created']]);
        $this->getJson("/api/v1/source-connections/{$bad['id']}/events?status=failed")->assertOk()
            ->assertJsonPath('data.0.error_message', 'Запись тестового источника не по схеме: нет id, started_at или duration_sec.')
            ->assertJsonMissingPath('data.0.payload');
    }

    #[Test]
    public function source_errors_fail_the_run_and_are_shown(): void
    {
        $c = $this->connection(['scenario' => 'auth_error']);
        $this->postJson("/api/v1/source-connections/{$c['id']}/test")->assertOk()->assertJsonPath('ok', false);
        $detail = $this->postJson("/api/v1/source-connections/{$c['id']}/sync")->json('connection');
        $this->assertSame('failed', $detail['runs'][0]['status']);
        $this->assertStringContainsString('ключ', $detail['last_error']);
        $this->assertSame(0, Call::query()->count());
    }

    #[Test]
    public function webhook_requires_valid_signature_and_imports_events(): void
    {
        $c = $this->enable($this->connection());
        $this->putJson("/api/v1/source-connections/{$c['id']}/secret", ['secret' => 'top-secret-key'])->assertOk()
            ->assertJsonPath('has_secret', true)->assertDontSee('top-secret-key');
        $secret = $this->postJson("/api/v1/source-connections/{$c['id']}/webhook-secret")->assertOk()->json('webhook_secret');
        $url = $this->getJson("/api/v1/source-connections/{$c['id']}")->assertDontSee($secret)->json('webhook.url');
        $this->assertStringNotContainsString($secret, $this->getJson('/api/v1/audit')->getContent());

        $body = json_encode(['calls' => [[
            'id' => 'push-1', 'direction' => 'incoming', 'started_at' => '2026-10-20T10:00:00+03:00', 'duration_sec' => 12,
            'client_phone' => '+79001234567', 'extension' => null, 'has_recording' => true,
        ]]]);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_MOCK_SIGNATURE' => hash_hmac('sha256', $body, $secret)];

        auth()->logout();
        $this->call('POST', $url, [], [], [], [...$headers, 'HTTP_X_MOCK_SIGNATURE' => 'bad'], $body)->assertStatus(401);
        $this->call('POST', $url.'x', [], [], [], $headers, $body)->assertNotFound();
        $this->call('POST', $url, [], [], [], $headers, $body)->assertStatus(202)->assertJsonPath('accepted', 1);
        $this->call('POST', $url, [], [], [], $headers, $body)->assertStatus(202);

        $this->assertSame(1, Call::query()->where('external_id', "{$c['id']}:push-1")->count(), 'Повтор события не создаёт дубль');
        $this->assertSame(['imported', 'duplicate'], SourceEvent::query()->where('channel', 'push')->orderBy('id')->pluck('status')->all());
    }

    #[Test]
    public function connections_are_managed_only_with_permission(): void
    {
        $c = $this->connection();
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $this->actingAs($admin)->getJson('/api/v1/source-connections')->assertForbidden();
        $this->getJson("/api/v1/source-connections/{$c['id']}")->assertNotFound();
        $this->postJson('/api/v1/source-connections', ['driver' => 'mock', 'name' => 'X', 'company_id' => $this->companyA->id])->assertForbidden();

        // Выключенное подключение не принимает события.
        $this->call('POST', $this->actingAs($this->root)->getJson("/api/v1/source-connections/{$c['id']}")->json('webhook.url'), [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
            ->assertNotFound();
    }
}
