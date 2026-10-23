<?php

namespace Tests\Feature\Api;

use App\Domain\Audio\Models\AudioFile;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Models\EmployeeAssignment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioFixtures;
use Tests\TestCase;

/**
 * Этап B — ручная загрузка. Приёмка: корректный файл импортируется один раз, повреждённый
 * отклоняется с диагностикой, чужой файл недоступен, повтор выполнения не дублирует сущности.
 */
class CallUploadTest extends TestCase
{
    use AudioFixtures;

    private User $adminA;

    private Employee $employeeA;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('calls');
        $this->buildOrg();
        $this->adminA = $this->userWithRole('company_admin', $this->companyA);
        $this->employeeA = Employee::factory()->create(['company_id' => $this->companyA->id, 'last_name' => 'Иванов', 'first_name' => 'Пётр']);
        EmployeeAssignment::factory()->for($this->employeeA)->inDepartment($this->salesA)->create(['position' => 'Менеджер']);
    }

    protected function tearDown(): void
    {
        $this->tearDownAudioFixtures();
        parent::tearDown();
    }

    /** @param array<string, mixed> $meta */
    private function upload(UploadedFile $file, array $meta = [], ?string $key = null, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->adminA)
            ->withHeader('Idempotency-Key', $key ?? Str::uuid()->toString())
            ->post('/api/v1/calls/uploads', ['file' => $file, 'department_id' => $this->salesA->id, ...$meta], ['Accept' => 'application/json']);
    }

    #[Test]
    #[DataProvider('formats')]
    public function valid_recording_is_stored_privately_and_prepared(string $format): void
    {
        $response = $this->upload($this->audioFile($format, 3.0), ['employee_id' => $this->employeeA->id, 'direction' => 'incoming'])
            ->assertCreated()
            ->assertJsonPath('status.code', 'awaiting_transcription')
            ->assertJsonPath('original.format', $format)
            ->assertJsonPath('normalized.sample_rate', 16000)
            ->assertJsonPath('normalized.channels', 1)
            ->assertJsonPath('org.employee', 'Иванов Пётр')
            ->assertJsonPath('org.employee_position', 'Менеджер')
            ->assertJsonPath('attribution_status', 'resolved');

        $this->assertEqualsWithDelta(3000, $response->json('duration_ms'), 100);
        $call = Call::query()->findOrFail($response->json('id'));
        Storage::disk('calls')->assertExists($call->original->path);
        Storage::disk('calls')->assertExists($call->normalized->path);
        $this->assertStringNotContainsString('звонок', $call->original->path, 'Имя пользователя не попадает в путь хранения');
        $this->assertSame('succeeded', $call->attempts()->firstOrFail()->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'call.uploaded', 'subject_id' => $call->id, 'company_id' => $this->companyA->id]);
    }

    /** @return array<string, array{string}> */
    public static function formats(): array
    {
        return ['mp3' => ['mp3'], 'wav' => ['wav'], 'm4a' => ['m4a']];
    }

    #[Test]
    public function damaged_and_foreign_files_are_rejected_with_a_reason_and_leave_nothing_behind(): void
    {
        $cases = [
            'повреждённый mp3' => [$this->rawFile('ID3'.random_bytes(4000), 'broken.mp3'), 'повреждён'],
            'текст под видом mp3' => [$this->rawFile(str_repeat('hello ', 100), 'notes.mp3'), 'Неподдерживаемый формат'],
            'плейлист под видом mp3' => [$this->rawFile("#EXTM3U\n#EXTINF:1,\nfile:///etc/passwd\n", 'playlist.mp3'), 'Неподдерживаемый формат'],
            'видео' => [$this->videoFile(), 'содержит видео'],
        ];

        foreach ($cases as $label => [$file, $message]) {
            $error = $this->upload($file)->assertStatus(422)->json('errors.file.0');
            $this->assertStringContainsString($message, (string) $error, $label);
            $this->assertStringNotContainsString('/var/www', (string) $error, "{$label}: без путей сервера");
        }

        $this->assertSame(0, Call::query()->count());
        $this->assertSame([], Storage::disk('calls')->allFiles());
    }

    #[Test]
    public function size_and_duration_limits_are_configurable(): void
    {
        config(['calls.min_duration_seconds' => 2]);
        $this->upload($this->audioFile('mp3', 1.0))->assertStatus(422)->assertJsonPath('errors.file.0', 'Запись слишком короткая (минимум 2 с).');

        config(['calls.max_duration_minutes' => 0]);
        config(['calls.min_duration_seconds' => 0]);
        $this->upload($this->audioFile('mp3', 3.0))->assertStatus(422);

        config(['calls.max_file_size_mb' => 0]);
        $this->upload($this->audioFile('wav', 3.0))->assertStatus(422)->assertJsonValidationErrors('file');
    }

    #[Test]
    public function the_same_request_is_imported_exactly_once(): void
    {
        $key = Str::uuid()->toString();
        $file = $this->audioFile('mp3');

        $first = $this->upload($file, key: $key)->assertCreated()->json('id');
        $second = $this->upload($file, key: $key)->assertOk()->json('id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Call::query()->count());
        $this->assertSame(2, AudioFile::query()->count(), 'Оригинал + нормализованная копия, без дублей');
        $this->assertCount(2, Storage::disk('calls')->allFiles());

        // Чужой ключ не раскрывает чужой звонок.
        $otherAdmin = $this->userWithRole('company_admin', $this->companyA);
        $this->upload($this->audioFile('mp3'), key: $key, as: $otherAdmin)->assertStatus(409);
    }

    #[Test]
    public function idempotency_key_is_required(): void
    {
        $this->actingAs($this->adminA)
            ->post('/api/v1/calls/uploads', ['file' => $this->audioFile(), 'company_id' => $this->companyA->id], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('idempotency_key');
    }

    #[Test]
    public function duplicate_content_requires_explicit_confirmation(): void
    {
        $file = $this->audioFile('mp3', 2.0, 500);
        $original = $this->upload($file)->assertCreated()->json('id');

        $this->upload($file)->assertStatus(409)
            ->assertJsonPath('code', 'duplicate_audio')
            ->assertJsonPath('duplicate.id', $original);
        $this->assertSame(1, Call::query()->count());

        $this->upload($file, ['confirm_duplicate' => true])->assertCreated()->assertJsonPath('duplicate_of.id', $original);
        $this->assertSame(2, Call::query()->count());
    }

    #[Test]
    public function duplicate_from_another_company_is_not_disclosed(): void
    {
        $file = $this->audioFile('mp3', 2.0, 600);
        $adminB = $this->userWithRole('company_admin', $this->companyB);
        $this->upload($file, ['department_id' => $this->salesB->id], as: $adminB)->assertCreated();

        $this->upload($file)->assertStatus(409)->assertJsonPath('duplicate.id', null);
    }

    #[Test]
    public function uploads_respect_the_org_scope(): void
    {
        $this->upload($this->audioFile(), ['department_id' => $this->salesB->id])->assertForbidden();

        $employeeB = Employee::factory()->create(['company_id' => $this->companyB->id]);
        $this->upload($this->audioFile(), ['employee_id' => $employeeB->id])->assertStatus(422)->assertJsonValidationErrors('employee_id');

        $director = $this->userWithRole('director', $this->companyA);
        $this->upload($this->audioFile(), as: $director)->assertForbidden();
        $this->assertSame(0, Call::query()->count());
    }

    #[Test]
    public function foreign_calls_and_their_audio_are_not_accessible(): void
    {
        $adminB = $this->userWithRole('company_admin', $this->companyB);
        $callB = $this->upload($this->audioFile(), ['department_id' => $this->salesB->id], as: $adminB)->json('id');

        $this->actingAs($this->adminA);
        $this->getJson("/api/v1/calls/{$callB}")->assertNotFound();
        $this->get("/api/v1/calls/{$callB}/audio")->assertNotFound();
        $this->get("/api/v1/calls/{$callB}/audio?download=1")->assertNotFound();
        $this->postJson("/api/v1/calls/{$callB}/reprocess")->assertNotFound();
        $this->postJson("/api/v1/calls/{$callB}/exclude", ['reason' => 'x'])->assertNotFound();
        $this->patchJson("/api/v1/calls/{$callB}", ['note' => 'x'])->assertNotFound();
        $this->assertNotContains($callB, collect($this->getJson('/api/v1/calls')->json('data'))->pluck('id'));
        $this->assertSame(0, $this->getJson('/api/v1/calls/summary')->json('total'));
    }

    #[Test]
    public function audio_is_streamed_with_seeking_and_downloads_are_audited(): void
    {
        $id = $this->upload($this->audioFile('mp3', 3.0))->json('id');

        $this->get("/api/v1/calls/{$id}/audio")->assertOk()->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('Accept-Ranges', 'bytes');
        $partial = $this->get("/api/v1/calls/{$id}/audio", ['Range' => 'bytes=0-99'])->assertStatus(206);
        $this->assertStringStartsWith('bytes 0-99/', (string) $partial->headers->get('Content-Range'));

        $download = $this->get("/api/v1/calls/{$id}/audio?download=1")->assertOk();
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_events', ['action' => 'call.audio_downloaded', 'subject_id' => $id]);

        // Директор слушает, но не скачивает (нет audio.download).
        $director = $this->userWithRole('director', $this->companyA);
        $this->actingAs($director)->get("/api/v1/calls/{$id}/audio")->assertOk();
        $this->actingAs($director)->get("/api/v1/calls/{$id}/audio?download=1")->assertForbidden();
    }

    #[Test]
    public function reprocessing_is_idempotent_and_keeps_history(): void
    {
        $id = $this->upload($this->audioFile())->json('id');

        $this->postJson("/api/v1/calls/{$id}/reprocess")->assertOk()->assertJsonPath('status.code', 'awaiting_transcription');
        $this->postJson("/api/v1/calls/{$id}/reprocess")->assertOk();

        $call = Call::query()->findOrFail($id);
        $this->assertSame(3, $call->attempts()->count(), 'Каждый запуск — отдельная попытка в истории');
        $this->assertSame(2, $call->audioFiles()->count(), 'Повтор не плодит файлы');
        $this->assertCount(2, Storage::disk('calls')->allFiles());
        $this->assertSame(2, AuditEvent::query()->where('action', 'call.reprocess_requested')->count());

        $director = $this->userWithRole('director', $this->companyA);
        $this->actingAs($director)->postJson("/api/v1/calls/{$id}/reprocess")->assertForbidden();
    }

    #[Test]
    public function processing_errors_keep_the_call_and_show_the_reason(): void
    {
        $id = $this->upload($this->audioFile())->json('id');
        $call = Call::query()->findOrFail($id);
        Storage::disk('calls')->delete($call->original->path);

        $response = $this->postJson("/api/v1/calls/{$id}/reprocess")->assertOk()
            ->assertJsonPath('status.code', 'failed')
            ->assertJsonPath('last_error', 'Файл исходной записи отсутствует в хранилище.')
            ->assertJsonPath('attempts.0.status', 'failed')
            ->assertJsonPath('attempts.0.error_code', 'missing_file');
        $this->assertStringNotContainsString('/var/www', $response->getContent());
        $this->assertModelExists($call);
    }

    #[Test]
    public function exclusion_requires_a_reason_and_can_be_reverted_by_reprocessing(): void
    {
        $id = $this->upload($this->audioFile())->json('id');

        $this->postJson("/api/v1/calls/{$id}/exclude", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/v1/calls/{$id}/exclude", ['reason' => 'Тестовый звонок'])->assertOk()
            ->assertJsonPath('status.code', 'excluded')
            ->assertJsonPath('excluded.reason', 'Тестовый звонок');
        $this->assertDatabaseHas('audit_events', ['action' => 'call.excluded', 'subject_id' => $id, 'reason' => 'Тестовый звонок']);
        Storage::disk('calls')->assertExists(Call::query()->findOrFail($id)->original->path);

        $this->postJson("/api/v1/calls/{$id}/reprocess")->assertOk()->assertJsonPath('status.code', 'awaiting_transcription')
            ->assertJsonPath('excluded', null);
    }

    #[Test]
    public function unknown_employee_is_allowed_and_can_be_resolved_later(): void
    {
        $id = $this->upload($this->audioFile())->assertCreated()->assertJsonPath('attribution_status', 'needs_employee')->json('id');

        $this->getJson('/api/v1/calls/summary')->assertJsonPath('needs_employee', 1)->assertJsonPath('awaiting_transcription', 1);
        $this->assertSame([$id], collect($this->getJson('/api/v1/calls?needs_employee=1')->json('data'))->pluck('id')->all());

        $this->patchJson("/api/v1/calls/{$id}", ['employee_id' => $this->employeeA->id])->assertOk()
            ->assertJsonPath('attribution_status', 'resolved')
            ->assertJsonPath('org.employee', 'Иванов Пётр');
        $this->assertDatabaseHas('audit_events', ['action' => 'call.updated', 'subject_id' => $id]);

        $this->patchJson("/api/v1/calls/{$id}", ['department_id' => $this->salesB->id])->assertForbidden();
    }

    #[Test]
    public function org_snapshot_does_not_change_when_the_structure_changes(): void
    {
        $id = $this->upload($this->audioFile(), ['employee_id' => $this->employeeA->id])->json('id');

        $this->salesA->update(['name' => 'Переименованный отдел']);
        $this->employeeA->assignments()->update(['position' => 'Руководитель']);

        $this->getJson("/api/v1/calls/{$id}")
            ->assertJsonPath('org.department', 'Продажи A')
            ->assertJsonPath('org.employee_position', 'Менеджер');
    }

    #[Test]
    public function employee_with_own_scope_sees_only_own_calls_and_cannot_listen(): void
    {
        $mine = $this->upload($this->audioFile('mp3', 2.0, 700), ['employee_id' => $this->employeeA->id])->json('id');
        $other = $this->upload($this->audioFile('mp3', 2.0, 800))->json('id');

        $self = $this->userWithRole('employee', null, $this->salesA, ['employee_id' => $this->employeeA->id]);
        $this->actingAs($self);

        $this->assertSame([$mine], collect($this->getJson('/api/v1/calls')->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/calls/{$other}")->assertNotFound();
        $this->get("/api/v1/calls/{$mine}/audio")->assertForbidden();
    }
}
