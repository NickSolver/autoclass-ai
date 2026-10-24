<?php

namespace Tests\Support;

use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\ProviderRole;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Employee;
use Database\Seeders\DemoChecklistSeeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Звонки, прошедшие весь конвейер на тестовых провайдерах (очередь в тестах синхронная). */
trait ProcessedCalls
{
    use AudioFixtures;

    private ?ProviderConfig $llm = null;

    protected function enableMockProviders(string $llmScenario = 'normal'): void
    {
        Storage::fake('calls');
        $this->seed(DemoChecklistSeeder::class);
        ProviderConfig::create(['kind' => ProviderKind::Asr, 'driver' => 'mock', 'name' => 'A', 'role' => ProviderRole::Primary, 'settings' => ['mock_scenario' => 'normal']]);
        $this->llm = ProviderConfig::create(['kind' => ProviderKind::Llm, 'driver' => 'mock', 'name' => 'L', 'role' => ProviderRole::Primary, 'settings' => ['mock_scenario' => $llmScenario]]);
    }

    protected function setLlmScenario(string $scenario): void
    {
        $this->llm?->update(['settings' => ['mock_scenario' => $scenario]]);
    }

    protected function processedCall(User $uploader, ?Employee $employee = null, float $seconds = 36.0): Call
    {
        $meta = ['department_id' => $this->salesA->id, 'direction' => 'incoming'];
        if ($employee !== null) {
            $meta['employee_id'] = $employee->id;
        }
        $id = $this->actingAs($uploader)->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->post('/api/v1/calls/uploads', ['file' => $this->audioFile('mp3', $seconds), ...$meta], ['Accept' => 'application/json'])
            ->assertCreated()->json('id');

        return Call::query()->findOrFail($id);
    }
}
