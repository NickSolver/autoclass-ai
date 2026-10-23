<?php

namespace App\Domain\AiProviders\Drivers\OpenAi;

use App\Domain\AiProviders\Contracts\AudioInput;
use App\Domain\AiProviders\Contracts\SpeechToTextProviderInterface;
use App\Domain\AiProviders\Contracts\TranscriptionResult;
use App\Domain\AiProviders\Contracts\TranscriptSegmentData;
use App\Domain\AiProviders\Drivers\Concerns\LabelsSpeakers;
use App\Domain\AiProviders\Drivers\Concerns\MapsHttpErrors;
use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Распознавание через OpenAI Audio API: POST {endpoint}/audio/transcriptions (multipart).
 * Сверено с официальной спецификацией openai-openapi (createTranscription), ADR-0005.
 * - gpt-4o-transcribe-diarize: response_format=diarized_json, chunking_strategy=auto —
 *   реплики с таймкодами и говорящими;
 * - whisper-1: response_format=verbose_json, timestamp_granularities[]=segment — таймкоды без говорящих.
 * Остальные модели (gpt-4o-transcribe и др.) таймкодов не возвращают — для звонков не подходят.
 */
final class OpenAiTranscription implements SpeechToTextProviderInterface
{
    use LabelsSpeakers, MapsHttpErrors;

    public const MODELS = ['gpt-4o-transcribe-diarize', 'whisper-1'];

    /** Лимит размера файла OpenAI. */
    private const MAX_BYTES = 25 * 1024 * 1024;

    public function transcribe(ProviderConfig $config, AudioInput $audio): TranscriptionResult
    {
        $model = (string) $config->model;
        if (! in_array($model, self::MODELS, true)) {
            throw new ProviderException(ProviderException::CONFIG_ERROR, "Модель «{$model}» не возвращает таймкоды. Используйте: ".implode(', ', self::MODELS).'.');
        }

        $file = $audio->encode('mp3', 16000, keepChannels: false, bitrate: '48k');
        if (filesize($file) > self::MAX_BYTES) {
            throw new ProviderException(ProviderException::CONFIG_ERROR, 'Запись слишком длинная для OpenAI (лимит 25 МБ на файл).');
        }

        $diarize = $model === 'gpt-4o-transcribe-diarize';
        $fields = [
            'model' => $model,
            'language' => (string) $config->setting('language', 'ru'),
            'response_format' => $diarize ? 'diarized_json' : 'verbose_json',
        ];
        if ($diarize) {
            $fields['chunking_strategy'] = 'auto';
        } else {
            $fields['timestamp_granularities[]'] = 'segment';
        }

        $response = $this->guarded(fn () => $this->http($config)
            ->attach('file', (string) file_get_contents($file), 'call.mp3', ['Content-Type' => 'audio/mpeg'])
            ->post($this->url((string) $config->endpoint, '/audio/transcriptions'), $fields));

        $segments = [];
        foreach ((array) $response->json('segments') as $s) {
            $text = trim((string) ($s['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $segments[] = new TranscriptSegmentData(
                (int) round(((float) ($s['start'] ?? 0)) * 1000),
                (int) round(((float) ($s['end'] ?? 0)) * 1000),
                $diarize ? $this->speakerLabel((string) ($s['speaker'] ?? '?')) : 'S1',
                $text,
            );
        }
        if ($segments === []) {
            throw new ProviderException(ProviderException::BAD_RESPONSE, 'Провайдер не вернул ни одной реплики с таймкодами.');
        }

        return new TranscriptionResult(
            $segments,
            (string) ($response->json('language') ?? $config->setting('language', 'ru')),
            (float) ($response->json('usage.seconds') ?? $response->json('duration') ?? $audio->durationMs / 1000),
        );
    }

    public function testConnection(ProviderConfig $config): void
    {
        $models = collect((array) $this->guarded(fn () => $this->http($config)->get($this->url((string) $config->endpoint, '/models')))->json('data'))
            ->pluck('id')->filter();
        if ($models->isNotEmpty() && ! $models->contains($config->model)) {
            throw new ProviderException(ProviderException::CONFIG_ERROR, "Ключ работает, но модели «{$config->model}» нет в вашем аккаунте.");
        }
    }

    public function isExternal(): bool
    {
        return true;
    }

    private function http(ProviderConfig $config): PendingRequest
    {
        $secret = $config->secret ?: throw new ProviderException(ProviderException::CONFIG_ERROR, 'Не задан ключ API.');

        return Http::withToken($secret)->acceptJson()
            ->timeout((int) $config->setting('timeout_seconds', 600))->connectTimeout(10)
            ->withOptions(['allow_redirects' => false]);
    }
}
