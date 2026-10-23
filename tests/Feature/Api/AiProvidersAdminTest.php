<?php

namespace Tests\Feature\Api;

use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Настройка провайдеров ИИ из админки (ТЗ, разд. 8). */
class AiProvidersAdminTest extends TestCase
{
    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrg();
        $this->root = $this->superadmin();
    }

    /** @param array<string, mixed> $overrides */
    private function createProvider(array $overrides = []): int
    {
        return $this->actingAs($this->root)->postJson('/api/v1/ai-providers', [
            'kind' => 'llm', 'driver' => 'mock', 'name' => 'Тестовый', ...$overrides,
        ])->assertCreated()->json('id');
    }

    #[Test]
    public function only_group_level_ai_managers_configure_providers(): void
    {
        $companyAdmin = $this->userWithRole('company_admin', $this->companyA);

        $this->actingAs($companyAdmin)->getJson('/api/v1/ai-providers')->assertForbidden();
        $this->actingAs($companyAdmin)->postJson('/api/v1/ai-providers', ['kind' => 'llm', 'driver' => 'mock', 'name' => 'x'])->assertForbidden();
        $this->actingAs($companyAdmin)->getJson('/api/v1/ai-provider-calls')->assertForbidden();

        $this->actingAs($this->root)->getJson('/api/v1/ai-providers')->assertOk()
            ->assertJsonPath('external_processing.enabled', false)
            ->assertJsonPath('presets.openai_compatible.0.name', 'DeepSeek');
    }

    #[Test]
    public function api_key_is_write_only_and_never_logged(): void
    {
        $id = $this->createProvider();
        $secret = 'sk-very-secret-key-123456';

        $response = $this->putJson("/api/v1/ai-providers/{$id}/secret", ['secret' => $secret])->assertOk()
            ->assertJsonPath('secret_set', true);
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString($secret, $this->getJson('/api/v1/ai-providers')->getContent());

        $stored = (string) DB::table('provider_configs')->where('id', $id)->value('secret');
        $this->assertNotSame($secret, $stored, 'В БД ключ зашифрован');
        $this->assertSame($secret, ProviderConfig::query()->findOrFail($id)->secret);

        $this->assertStringNotContainsString($secret, AuditEvent::query()->get()->toJson());
        $this->assertDatabaseHas('audit_events', ['action' => 'provider_config.secret_set', 'subject_id' => $id]);
    }

    #[Test]
    public function one_primary_per_kind(): void
    {
        $a = $this->createProvider(['name' => 'A']);
        $b = $this->createProvider(['name' => 'B']);

        $this->postJson("/api/v1/ai-providers/{$a}/role", ['role' => 'primary'])->assertOk();
        $this->postJson("/api/v1/ai-providers/{$b}/role", ['role' => 'primary'])->assertOk();

        $this->assertSame('inactive', ProviderConfig::query()->findOrFail($a)->role->value);
        $this->assertSame('primary', ProviderConfig::query()->findOrFail($b)->role->value);
    }

    #[Test]
    public function external_provider_cannot_be_activated_until_external_processing_is_allowed(): void
    {
        $id = $this->createProvider(['driver' => 'openai_compatible', 'name' => 'DeepSeek', 'endpoint' => 'https://api.deepseek.com', 'model' => 'deepseek-chat']);

        $this->postJson("/api/v1/ai-providers/{$id}/role", ['role' => 'primary'])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->putJson('/api/v1/ai-settings/external-processing', ['enabled' => true])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->putJson('/api/v1/ai-settings/external-processing', ['enabled' => true, 'reason' => 'Юридическая проверка пройдена, договор №1'])
            ->assertOk()->assertJsonPath('enabled', true);
        $this->postJson("/api/v1/ai-providers/{$id}/role", ['role' => 'primary'])->assertOk();

        // Выключение сразу снимает внешнего провайдера с роли.
        $this->putJson('/api/v1/ai-settings/external-processing', ['enabled' => false, 'reason' => 'Приостановлено до продления договора'])->assertOk();
        $this->assertSame('inactive', ProviderConfig::query()->findOrFail($id)->role->value);
        $this->assertDatabaseHas('audit_events', ['action' => 'ai.external_processing_changed', 'reason' => 'Приостановлено до продления договора']);
    }

    #[Test]
    public function external_endpoint_must_be_https_and_public(): void
    {
        $this->actingAs($this->root);
        foreach (['http://api.deepseek.com', 'https://localhost:8080', 'https://10.0.0.5', 'https://192.168.1.10/v1', 'https://user:pass@api.deepseek.com', 'https://metadata.internal'] as $endpoint) {
            $this->postJson('/api/v1/ai-providers', [
                'kind' => 'llm', 'driver' => 'openai_compatible', 'name' => 'x', 'endpoint' => $endpoint, 'model' => 'm',
            ])->assertStatus(422)->assertJsonValidationErrors('endpoint');
        }
        $this->postJson('/api/v1/ai-providers', ['kind' => 'llm', 'driver' => 'openai_compatible', 'name' => 'x', 'endpoint' => 'https://api.deepseek.com'])
            ->assertStatus(422)->assertJsonValidationErrors('model');
    }

    #[Test]
    public function drivers_are_checked_per_kind(): void
    {
        // У OpenAI-совместимого API (DeepSeek) нет распознавания речи.
        $this->actingAs($this->root)->postJson('/api/v1/ai-providers', [
            'kind' => 'asr', 'driver' => 'openai_compatible', 'name' => 'x', 'endpoint' => 'https://api.deepseek.com', 'model' => 'm',
        ])->assertStatus(422)->assertJsonValidationErrors('driver');
    }

    #[Test]
    public function connection_test_reports_and_logs_the_result(): void
    {
        $ok = $this->createProvider();
        $broken = $this->createProvider(['settings' => ['mock_scenario' => 'server_error']]);

        $this->postJson("/api/v1/ai-providers/{$ok}/test")->assertOk()->assertJsonPath('ok', true);
        $this->postJson("/api/v1/ai-providers/{$broken}/test")->assertOk()->assertJsonPath('ok', false);

        $this->getJson('/api/v1/ai-provider-calls')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/ai-providers')->assertJsonPath('data.0.stats.calls_30d', 1);
    }

    #[Test]
    public function settings_update_uses_optimistic_locking(): void
    {
        $id = $this->createProvider();
        $version = ProviderConfig::query()->findOrFail($id)->lock_version;

        $this->patchJson("/api/v1/ai-providers/{$id}", ['name' => 'Новое', 'lock_version' => $version])->assertOk();
        $this->patchJson("/api/v1/ai-providers/{$id}", ['name' => 'Устаревшее', 'lock_version' => $version])
            ->assertStatus(422)->assertJsonValidationErrors('lock_version');
    }
}
