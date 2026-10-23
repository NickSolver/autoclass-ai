<?php

namespace Tests\Feature;

use App\Domain\AiProviders\Contracts\AudioInput;
use App\Domain\AiProviders\Drivers\OpenAi\OpenAiTranscription;
use App\Domain\AiProviders\Drivers\TBank\TBankVoiceKit;
use App\Domain\AiProviders\Drivers\Yandex\YandexSpeechKit;
use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderException;
use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\ProviderRole;
use App\Domain\AiProviders\Services\ProviderConfigService;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Transcription\Models\TranscriptVersion;
use Database\Seeders\DemoChecklistSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AudioFixtures;
use Tests\TestCase;

/**
 * Драйверы распознавания (ADR-0005). Сеть подменена; формы запросов и ответов — по официальным
 * спецификациям: openai-openapi, yandex-cloud/cloudapi (stt v3), Tinkoff/voicekit-examples.
 */
class AsrDriversTest extends TestCase
{
    use AudioFixtures;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake(syncWithCarbon: true);
        $this->workDir = sys_get_temp_dir().'/asr-test-'.Str::random(6);
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->workDir}/*") ?: []);
        @rmdir($this->workDir);
        $this->tearDownAudioFixtures();
        parent::tearDown();
    }

    /** Стереозапись (фикстуры генерируются с двумя каналами). */
    private function input(int $channels = 2): AudioInput
    {
        $wav = $this->audioFile('wav', 4.0)->getRealPath();

        return new AudioInput($this->workDir, $wav, $wav, 'wav', $channels, 4000);
    }

    private function config(string $driver, array $attributes = [], array $settings = [], ?string $secret = 'test-key-123456'): ProviderConfig
    {
        $config = ProviderConfig::create([
            'kind' => ProviderKind::Asr, 'driver' => $driver, 'name' => $driver, 'role' => ProviderRole::Inactive,
            'settings' => ['timeout_seconds' => 30, 'max_wait_seconds' => 60, 'poll_seconds' => 1, ...$settings], ...$attributes,
        ]);
        $config->forceFill(['secret' => $secret])->save();

        return $config;
    }

    // --- OpenAI ---

    #[Test]
    public function openai_diarize_returns_segments_with_speakers(): void
    {
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response([
            'task' => 'transcribe', 'duration' => 4.0, 'text' => '…',
            'segments' => [
                ['type' => 'transcript.text.segment', 'id' => 'seg_001', 'start' => 0.0, 'end' => 1.5, 'text' => 'Здравствуйте, автосалон.', 'speaker' => 'A'],
                ['type' => 'transcript.text.segment', 'id' => 'seg_002', 'start' => 1.6, 'end' => 3.2, 'text' => 'Добрый день.', 'speaker' => 'B'],
                ['type' => 'transcript.text.segment', 'id' => 'seg_003', 'start' => 3.3, 'end' => 3.9, 'text' => 'Слушаю вас.', 'speaker' => 'A'],
            ],
            'usage' => ['type' => 'duration', 'seconds' => 4],
        ])]);
        $config = $this->config('openai', ['endpoint' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-transcribe-diarize'], ['language' => 'ru']);

        $result = app(OpenAiTranscription::class)->transcribe($config, $this->input());

        $this->assertSame(['S1', 'S2', 'S1'], array_map(fn ($s) => $s->speaker, $result->segments));
        $this->assertSame([0, 1500], [$result->segments[0]->startMs, $result->segments[0]->endMs]);
        $this->assertFalse($result->synthetic);
        Http::assertSent(function (Request $r) {
            $fields = collect($r->data())->pluck('contents', 'name');

            return $r->isMultipart()
                && $r->hasHeader('Authorization', 'Bearer test-key-123456')
                && $fields['model'] === 'gpt-4o-transcribe-diarize'
                && $fields['response_format'] === 'diarized_json'
                && $fields['chunking_strategy'] === 'auto'
                && $fields['language'] === 'ru';
        });
    }

    #[Test]
    public function openai_whisper_has_timecodes_but_one_speaker_and_other_models_are_refused(): void
    {
        Http::fake(['*' => Http::response(['language' => 'russian', 'duration' => 4.0, 'text' => '…', 'segments' => [
            ['id' => 0, 'seek' => 0, 'start' => 0.0, 'end' => 2.0, 'text' => ' Алло. ', 'tokens' => [], 'temperature' => 0, 'avg_logprob' => -0.2, 'compression_ratio' => 1, 'no_speech_prob' => 0],
        ]])]);
        $whisper = $this->config('openai', ['endpoint' => 'https://api.openai.com/v1', 'model' => 'whisper-1']);

        $result = app(OpenAiTranscription::class)->transcribe($whisper, $this->input());
        $this->assertSame('Алло.', $result->segments[0]->text);
        $this->assertSame('S1', $result->segments[0]->speaker);
        Http::assertSent(fn (Request $r) => collect($r->data())->pluck('contents', 'name')['response_format'] === 'verbose_json');

        $plain = $this->config('openai', ['endpoint' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-transcribe']);
        $this->expectExceptionMessage('не возвращает таймкоды');
        app(OpenAiTranscription::class)->transcribe($plain, $this->input());
    }

    // --- Яндекс ---

    #[Test]
    public function yandex_runs_async_recognition_and_uses_channels_as_speakers(): void
    {
        $line = fn (string $channel, int $index, string $event) => json_encode(['result' => [
            'channelTag' => $channel, 'audioCursors' => ['finalIndex' => (string) $index],
        ] + json_decode($event, true)], JSON_UNESCAPED_UNICODE);
        $body = implode("\n", [
            $line('1', 0, '{"final":{"alternatives":[{"text":"добрый день","startTimeMs":"1800","endTimeMs":"3000","confidence":0.9}]}}'),
            $line('0', 0, '{"final":{"alternatives":[{"text":"здравствуйте автосалон","startTimeMs":"0","endTimeMs":"1500"}]}}'),
            $line('0', 0, '{"finalRefinement":{"finalIndex":"0","normalizedText":{"alternatives":[{"text":"Здравствуйте, автосалон!"}]}}}'),
        ]);
        Http::fake([
            'stt.api.cloud.yandex.net/stt/v3/recognizeFileAsync' => Http::response(['id' => 'op-1', 'done' => false]),
            'operation.api.cloud.yandex.net/operations/op-1' => Http::sequence()->push(['id' => 'op-1', 'done' => false])->push(['id' => 'op-1', 'done' => true]),
            'stt.api.cloud.yandex.net/stt/v3/getRecognition*' => Http::response($body),
        ]);
        $config = $this->config('yandex', ['endpoint' => 'https://stt.api.cloud.yandex.net', 'model' => 'general'], ['folder_id' => 'b1gfolder', 'multichannel' => true]);

        $result = app(YandexSpeechKit::class)->transcribe($config, $this->input(2));

        $this->assertSame(['Здравствуйте, автосалон!', 'добрый день'], array_map(fn ($s) => $s->text, $result->segments), 'Нормализованный текст, по времени');
        $this->assertSame(['S1', 'S2'], array_map(fn ($s) => $s->speaker, $result->segments), 'Каналы — говорящие');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/recognizeFileAsync')
            && $r->hasHeader('Authorization', 'Api-Key test-key-123456')
            && $r->hasHeader('x-folder-id', 'b1gfolder')
            && $r['recognitionModel']['audioFormat']['containerAudio']['containerAudioType'] === 'OGG_OPUS'
            && ! isset($r['speakerLabeling']) // стерео — разметка не нужна
            && base64_decode($r['content'], true) !== false);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'getRecognition?operationId=op-1'));
    }

    #[Test]
    public function yandex_enables_speaker_labeling_for_mono_and_reports_failures(): void
    {
        Http::fake([
            '*/recognizeFileAsync' => Http::response(['id' => 'op-2']),
            '*/operations/op-2' => Http::response(['id' => 'op-2', 'done' => true, 'error' => ['code' => 3, 'message' => 'audio is too long']]),
        ]);
        $config = $this->config('yandex', ['endpoint' => 'https://stt.api.cloud.yandex.net'], ['multichannel' => false]);

        try {
            app(YandexSpeechKit::class)->transcribe($config, $this->input(1));
            $this->fail('Ожидалась ошибка');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderException::BAD_RESPONSE, $e->errorCode);
            $this->assertStringContainsString('audio is too long', $e->getMessage());
        }
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/recognizeFileAsync') && $r['speakerLabeling']['speakerLabeling'] === 'SPEAKER_LABELING_ENABLED');
    }

    #[Test]
    public function yandex_gives_up_after_max_wait(): void
    {
        Http::fake(['*/recognizeFileAsync' => Http::response(['id' => 'op-3']), '*/operations/op-3' => Http::response(['id' => 'op-3', 'done' => false])]);
        $config = $this->config('yandex', ['endpoint' => 'https://stt.api.cloud.yandex.net'], ['max_wait_seconds' => 60]);

        $this->expectExceptionMessage('не завершилось за отведённое время');
        app(YandexSpeechKit::class)->transcribe($config, $this->input());
    }

    // --- T-Bank ---

    #[Test]
    public function tbank_signs_a_jwt_and_reads_longrunning_results(): void
    {
        $secret = base64_encode('super-secret-bytes');
        Http::fake([
            'api.tinkoff.ai/v1/stt:longrunningrecognize' => Http::response(['id' => 'tb-1', 'state' => 'ENQUEUED']),
            'api.tinkoff.ai/v1/operations/tb-1' => Http::sequence()
                ->push(['id' => 'tb-1', 'state' => 'PROCESSING'])
                ->push(['id' => 'tb-1', 'state' => 'DONE', 'response' => [
                    '@type' => 'type.googleapis.com/tinkoff.cloud.stt.v1.RecognizeResponse',
                    'results' => [
                        ['alternatives' => [['transcript' => 'Добрый день.', 'confidence' => 0.8]], 'channel' => 1, 'startTime' => '1.800s', 'endTime' => '3s'],
                        ['alternatives' => [['transcript' => 'Автосалон, здравствуйте!', 'confidence' => 0.9]], 'channel' => 0, 'startTime' => '0.100s', 'endTime' => '1.500s'],
                    ],
                ]]),
        ]);
        $config = $this->config('tbank', ['endpoint' => 'https://api.tinkoff.ai'], [], json_encode(['api_key' => 'tb-api-key', 'secret_key' => $secret]));

        $result = app(TBankVoiceKit::class)->transcribe($config, $this->input(2));

        $this->assertSame(['Автосалон, здравствуйте!', 'Добрый день.'], array_map(fn ($s) => $s->text, $result->segments));
        $this->assertSame(['S1', 'S2'], array_map(fn ($s) => $s->speaker, $result->segments), 'Кто заговорил первым — S1');
        $this->assertSame([100, 1500], [$result->segments[0]->startMs, $result->segments[0]->endMs]);

        Http::assertSent(function (Request $r) use ($secret) {
            if (! str_ends_with($r->url(), 'stt:longrunningrecognize')) {
                return false;
            }
            [$h, $p, $s] = explode('.', substr($r->header('Authorization')[0], 7));
            $header = json_decode(base64_decode(strtr($h, '-_', '+/')), true);
            $payload = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
            $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$h}.{$p}", base64_decode($secret), true)), '+/', '-_'), '=');

            return $header['kid'] === 'tb-api-key' && $header['alg'] === 'HS256' && $payload['aud'] === 'tinkoff.cloud.stt'
                && hash_equals($expected, $s)
                && $r['config']['encoding'] === 'MPEG_AUDIO' && $r['config']['numChannels'] === 2 && $r['config']['sampleRateHertz'] === 16000;
        });
    }

    #[Test]
    public function tbank_requires_both_keys(): void
    {
        $config = $this->config('tbank', ['endpoint' => 'https://api.tinkoff.ai'], [], json_encode(['api_key' => 'only-api']));

        $this->expectExceptionMessage('Не заданы API-ключ и секретный ключ T-Bank.');
        app(TBankVoiceKit::class)->transcribe($config, $this->input());
    }

    #[Test]
    public function auth_errors_are_not_retried_and_hide_keys(): void
    {
        foreach (['openai' => 'Bearer', 'yandex' => 'Api-Key'] as $driver => $scheme) {
            Http::swap(new Factory);
            Http::fake(['*' => Http::response(['error' => ['message' => "Invalid credentials {$scheme} test-key-123456"]], 401)]);
            $config = $this->config($driver, ['endpoint' => $driver === 'openai' ? 'https://api.openai.com/v1' : 'https://stt.api.cloud.yandex.net', 'model' => $driver === 'openai' ? 'whisper-1' : 'general']);
            $class = $driver === 'openai' ? OpenAiTranscription::class : YandexSpeechKit::class;
            try {
                app($class)->transcribe($config, $this->input());
                $this->fail("{$driver}: ожидалась ошибка");
            } catch (ProviderException $e) {
                $this->assertFalse($e->isRetryable(), $driver);
                $this->assertStringNotContainsString('test-key-123456', $e->getMessage(), $driver);
            }
        }
    }

    // --- Настройка и конвейер ---

    #[Test]
    public function admin_configures_tbank_with_two_keys_and_planned_drivers_are_not_selectable(): void
    {
        $this->buildOrg();
        $this->actingAs($this->superadmin());

        $id = $this->postJson('/api/v1/ai-providers', ['kind' => 'asr', 'driver' => 'tbank', 'name' => 'T-Bank'])->assertCreated()
            ->assertJsonPath('endpoint', 'https://api.tinkoff.ai')->json('id');
        $this->putJson("/api/v1/ai-providers/{$id}/secret", ['secrets' => ['api_key' => 'a']])->assertStatus(422);
        $this->putJson("/api/v1/ai-providers/{$id}/secret", ['secrets' => ['api_key' => 'tb-key', 'secret_key' => base64_encode('s')]])
            ->assertOk()->assertJsonPath('secret_set', true);
        $this->assertSame(['api_key' => 'tb-key', 'secret_key' => base64_encode('s')], json_decode(ProviderConfig::query()->findOrFail($id)->secret, true));

        $this->postJson('/api/v1/ai-providers', ['kind' => 'asr', 'driver' => 'salute', 'name' => 'Сбер'])->assertStatus(422)->assertJsonValidationErrors('driver');
        $drivers = collect($this->getJson('/api/v1/ai-providers')->json('drivers.asr'))->keyBy('code');
        $this->assertTrue($drivers['yandex']['available']);
        $this->assertFalse($drivers['salute']['available']);
        $this->assertNotEmpty($drivers['mts']['reason']);
    }

    #[Test]
    public function stereo_call_is_transcribed_by_yandex_end_to_end(): void
    {
        Storage::fake('calls');
        $this->seed(DemoChecklistSeeder::class);
        $this->buildOrg();
        $root = $this->superadmin();
        app(ProviderConfigService::class)->setExternalProcessing($root, true, 'Юридическая проверка пройдена (тест)');
        $this->config('yandex', ['endpoint' => 'https://stt.api.cloud.yandex.net', 'model' => 'general', 'role' => ProviderRole::Primary]);
        Http::fake([
            '*/recognizeFileAsync' => Http::response(['id' => 'op-e2e']),
            '*/operations/op-e2e' => Http::response(['id' => 'op-e2e', 'done' => true]),
            '*/getRecognition*' => Http::response(implode("\n", [
                '{"result":{"channelTag":"0","audioCursors":{"finalIndex":"0"},"final":{"alternatives":[{"text":"Здравствуйте, автосалон","startTimeMs":"0","endTimeMs":"1500"}]}}}',
                '{"result":{"channelTag":"1","audioCursors":{"finalIndex":"0"},"final":{"alternatives":[{"text":"Добрый день","startTimeMs":"1600","endTimeMs":"2900"}]}}}',
            ])),
        ]);

        $admin = $this->userWithRole('company_admin', $this->companyA);
        $id = $this->actingAs($admin)->withHeader('Idempotency-Key', Str::uuid()->toString())
            ->post('/api/v1/calls/uploads', ['file' => $this->audioFile('mp3', 3.0), 'department_id' => $this->salesA->id], ['Accept' => 'application/json'])
            ->assertCreated()->json('id');

        $this->assertSame('awaiting_evaluation', Call::query()->findOrFail($id)->status->value);
        $version = TranscriptVersion::query()->where('call_id', $id)->firstOrFail();
        $this->assertFalse($version->is_synthetic, 'Настоящее распознавание — не тестовые данные');
        $this->assertSame(['S1', 'S2'], $version->segments->pluck('speaker')->all());
    }
}
