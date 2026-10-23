<?php

namespace Tests\Feature;

use App\Domain\AiProviders\Drivers\OpenAiCompatible\OpenAiCompatibleEvaluation;
use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderException;
use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\ProviderRole;
use App\Domain\AiProviders\Services\ProviderConfigService;
use App\Domain\CallIntake\Models\Call;
use Database\Seeders\DemoChecklistSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioFixtures;
use Tests\TestCase;

/**
 * Драйвер OpenAI-совместимого API на примере DeepSeek. Сеть подменена (Http::fake):
 * формат запросов и ответов — как у DeepSeek (сверено с живым API, ADR-0004).
 */
class OpenAiCompatibleDriverTest extends TestCase
{
    use AudioFixtures;

    private const KEY = 'sk-test-deepseek-key-0000';

    protected function tearDown(): void
    {
        $this->tearDownAudioFixtures();
        parent::tearDown();
    }

    private function config(array $settings = []): ProviderConfig
    {
        $config = ProviderConfig::create([
            'kind' => ProviderKind::Llm, 'driver' => 'openai_compatible', 'name' => 'DeepSeek',
            'endpoint' => 'https://api.deepseek.com', 'model' => 'deepseek-chat',
            'settings' => ['json_mode' => true, 'temperature' => 0, 'timeout_seconds' => 30, ...$settings],
            'role' => ProviderRole::Inactive,
        ]);
        $config->forceFill(['secret' => self::KEY, 'secret_set_at' => now()])->save();

        return $config;
    }

    /** @return array{system: string, user: string, schema: array<string, mixed>, payload: array<string, mixed>} */
    private function prompt(): array
    {
        return ['system' => 'system', 'user' => 'user json', 'schema' => [], 'payload' => []];
    }

    #[Test]
    public function sends_a_chat_completions_request_and_reads_content_and_usage(): void
    {
        Http::fake(['api.deepseek.com/chat/completions' => Http::response([
            'id' => 'x', 'object' => 'chat.completion', 'model' => 'deepseek-chat',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"ok":true}'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30, 'total_tokens' => 150],
        ])]);

        $response = app(OpenAiCompatibleEvaluation::class)->evaluate($this->config(), $this->prompt());

        $this->assertSame('{"ok":true}', $response->rawJson);
        $this->assertSame(120, $response->tokensIn);
        $this->assertSame(30, $response->tokensOut);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.deepseek.com/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer '.self::KEY)
            && $r['model'] === 'deepseek-chat'
            && $r['response_format'] === ['type' => 'json_object']
            && $r['temperature'] === 0.0
            && $r['messages'][0]['role'] === 'system');
    }

    #[Test]
    public function maps_http_errors_and_never_leaks_the_key(): void
    {
        $cases = [
            [401, ['error' => ['message' => 'Authentication Fails, Your api key: ****0000 is invalid', 'type' => 'authentication_error']], ProviderException::CONFIG_ERROR, false],
            [402, ['error' => ['message' => 'Insufficient Balance']], ProviderException::CONFIG_ERROR, false],
            [429, ['error' => ['message' => 'Rate limit reached']], ProviderException::RATE_LIMITED, true],
            [500, ['error' => ['message' => 'Server error']], ProviderException::SERVER_ERROR, true],
            [503, ['error' => ['message' => 'Server overloaded']], ProviderException::SERVER_ERROR, true],
        ];

        foreach ($cases as [$status, $body, $code, $retryable]) {
            Http::swap(new Factory); // новый фейк на каждый случай: заглушки Http::fake накапливаются
            Http::fake(['*' => Http::response($body, $status, $status === 429 ? ['Retry-After' => '7'] : [])]);
            try {
                app(OpenAiCompatibleEvaluation::class)->evaluate($this->config(), $this->prompt());
                $this->fail("HTTP {$status}: ожидалась ошибка");
            } catch (ProviderException $e) {
                $this->assertSame($code, $e->errorCode, "HTTP {$status}");
                $this->assertSame($retryable, $e->isRetryable(), "HTTP {$status}");
                $this->assertStringNotContainsString('0000', $e->getMessage(), "HTTP {$status}: хвост ключа");
                $this->assertStringNotContainsString(self::KEY, $e->getMessage());
                if ($status === 429) {
                    $this->assertSame(7, $e->retryAfterSeconds);
                }
            }
        }
    }

    #[Test]
    public function empty_content_and_missing_key_are_errors(): void
    {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']]])]);
        try {
            app(OpenAiCompatibleEvaluation::class)->evaluate($this->config(), $this->prompt());
            $this->fail('Ожидалась ошибка');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderException::BAD_RESPONSE, $e->errorCode);
            $this->assertStringContainsString('length', $e->getMessage());
        }

        $config = $this->config();
        $config->forceFill(['secret' => null])->save();
        $this->expectExceptionMessage('Не задан ключ API.');
        app(OpenAiCompatibleEvaluation::class)->evaluate($config, $this->prompt());
    }

    #[Test]
    public function connection_test_calls_models_endpoint(): void
    {
        Http::fake(['api.deepseek.com/models' => Http::response(['object' => 'list', 'data' => [['id' => 'deepseek-chat']]])]);

        app(OpenAiCompatibleEvaluation::class)->testConnection($this->config());

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://api.deepseek.com/models');
    }

    #[Test]
    public function full_pipeline_with_deepseek_style_responses(): void
    {
        Storage::fake('calls');
        $this->seed(DemoChecklistSeeder::class);
        $this->buildOrg();
        $root = $this->superadmin();
        $admin = $this->userWithRole('company_admin', $this->companyA);
        app(ProviderConfigService::class)->setExternalProcessing($root, true, 'Юридическая проверка пройдена (тест)');
        ProviderConfig::create(['kind' => ProviderKind::Asr, 'driver' => 'mock', 'name' => 'ASR', 'settings' => ['mock_scenario' => 'normal'], 'role' => ProviderRole::Primary]);
        $this->config()->update(['role' => ProviderRole::Primary]);

        // «Модель» отвечает по присланному чек-листу: каждый критерий выполнен, цитата — первая реплика.
        Http::fake(['api.deepseek.com/chat/completions' => function (Request $request) {
            $user = $request['messages'][1]['content'];
            $json = Str::between($user, "<<<TRANSCRIPT_AND_CHECKLIST\n", "\nTRANSCRIPT_AND_CHECKLIST>>>");
            $payload = json_decode($json, true);
            $first = $payload['transcript'][0];

            return Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'checklist_version_id' => $payload['checklist_version_id'],
                    'criteria' => array_map(fn (array $c) => [
                        'criterion_id' => $c['id'], 'status' => 'pass', 'reason' => 'Есть в разговоре.', 'confidence' => 0.8,
                        'evidence_refs' => [['type' => 'quote', 'segment_id' => $first['segment_id'], 'quote' => $first['text'], 'speaker' => $first['speaker']]],
                    ], $payload['criteria']),
                    'summary' => 'Хороший звонок.', 'risks' => [], 'recommendations' => [],
                ], JSON_UNESCAPED_UNICODE)], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 200],
            ]);
        }]);

        $id = $this->actingAs($admin)->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->post('/api/v1/calls/uploads', ['file' => $this->audioFile('mp3', 12.0), 'department_id' => $this->salesA->id], ['Accept' => 'application/json'])
            ->assertCreated()->json('id');

        $this->assertSame('completed', Call::query()->findOrFail($id)->status->value);
        $this->getJson("/api/v1/calls/{$id}/analysis")
            ->assertJsonPath('current.driver', 'openai_compatible')
            ->assertJsonPath('current.results.0.business_status', 'full_assessment')
            ->assertJsonPath('current.results.0.score', 100);
        Http::assertSentCount(2); // основной + дополнительный чек-лист
    }
}
