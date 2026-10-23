<?php

namespace App\Domain\AiProviders\Drivers\Yandex;

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
use Illuminate\Support\Sleep;

/**
 * Yandex SpeechKit API v3, асинхронное распознавание файла. Сверено с официальными
 * proto yandex-cloud/cloudapi (ai/stt/v3, operation), ADR-0005:
 * 1) POST {endpoint}/stt/v3/recognizeFileAsync — RecognizeFileRequest (content в base64) → Operation;
 * 2) GET {operations}/operations/{id} до done;
 * 3) GET {endpoint}/stt/v3/getRecognition?operationId= — поток StreamingResponse
 *    (JSON-объекты построчно, в обёртке {"result": …}); берём события final и
 *    final_refinement (нормализованный текст), говорящий — channel_tag.
 * Авторизация — ключ API сервисного аккаунта: «Authorization: Api-Key <ключ>».
 * Стерео отправляется как есть (каналы = говорящие); для моно включается разметка говорящих.
 */
final class YandexSpeechKit implements SpeechToTextProviderInterface
{
    use LabelsSpeakers, MapsHttpErrors;

    public function transcribe(ProviderConfig $config, AudioInput $audio): TranscriptionResult
    {
        $keepChannels = (bool) $config->setting('multichannel', true);
        $channels = $audio->channelsFor($keepChannels);
        $file = $audio->encode('ogg_opus', 16000, $keepChannels, '32k');

        $request = [
            'content' => base64_encode((string) file_get_contents($file)),
            'recognitionModel' => [
                'model' => (string) ($config->model ?: 'general'),
                'audioFormat' => ['containerAudio' => ['containerAudioType' => 'OGG_OPUS']],
                'textNormalization' => ['textNormalization' => 'TEXT_NORMALIZATION_ENABLED', 'profanityFilter' => false, 'literatureText' => true],
                'languageRestriction' => ['restrictionType' => 'WHITELIST', 'languageCode' => [(string) $config->setting('language', 'ru-RU')]],
                'audioProcessingType' => 'FULL_DATA',
            ],
        ];
        if ($channels === 1 && $config->setting('speaker_labeling', true)) {
            $request['speakerLabeling'] = ['speakerLabeling' => 'SPEAKER_LABELING_ENABLED'];
        }

        $operation = $this->guarded(fn () => $this->http($config)->post($this->url($this->endpoint($config), '/stt/v3/recognizeFileAsync'), $request));
        $id = (string) ($operation->json('id') ?? throw new ProviderException(ProviderException::BAD_RESPONSE, 'Яндекс не вернул идентификатор операции.'));
        $this->waitFor($config, $id);

        $result = $this->guarded(fn () => $this->http($config)->get($this->url($this->endpoint($config), '/stt/v3/getRecognition'), ['operationId' => $id]));
        $segments = $this->parse($result->body());
        if ($segments === []) {
            throw new ProviderException(ProviderException::BAD_RESPONSE, 'В записи не распознано ни одной фразы.');
        }

        return new TranscriptionResult($segments, (string) $config->setting('language', 'ru-RU'), $audio->durationMs / 1000 * $channels);
    }

    public function testConnection(ProviderConfig $config): void
    {
        // Отдельного метода проверки у API нет: запрос несуществующей операции — 404 при верном ключе, 401/403 — при неверном.
        $response = $this->http($config)->get($this->url($this->operations($config), '/operations/connection-check'));
        if (in_array($response->status(), [401, 403], true)) {
            $this->guarded(fn () => $response);
        }
    }

    public function isExternal(): bool
    {
        return true;
    }

    private function waitFor(ProviderConfig $config, string $id): void
    {
        $deadline = now()->getTimestamp() + (int) $config->setting('max_wait_seconds', 600);
        do {
            Sleep::for((int) $config->setting('poll_seconds', 2))->seconds();
            $operation = $this->guarded(fn () => $this->http($config)->get($this->url($this->operations($config), "/operations/{$id}")));
            if ($operation->json('error')) {
                throw new ProviderException(ProviderException::BAD_RESPONSE, 'Яндекс не смог распознать запись: '.$this->safeMessage((string) $operation->json('error.message')));
            }
            if ($operation->json('done') === true) {
                return;
            }
        } while (now()->getTimestamp() < $deadline);

        throw new ProviderException(ProviderException::TIMEOUT, 'Распознавание в Яндексе не завершилось за отведённое время.');
    }

    /** @return list<TranscriptSegmentData> */
    private function parse(string $body): array
    {
        $finals = [];
        foreach (preg_split('/\r?\n/', trim($body)) ?: [] as $line) {
            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }
            $event = $event['result'] ?? $event;
            $channel = (string) ($event['channelTag'] ?? '0');
            $index = (int) ($event['audioCursors']['finalIndex'] ?? count($finals));

            if (isset($event['final']['alternatives'][0])) {
                $alt = $event['final']['alternatives'][0];
                $finals["{$channel}:{$index}"] = [
                    'channel' => $channel,
                    'start' => (int) ($alt['startTimeMs'] ?? 0),
                    'end' => (int) ($alt['endTimeMs'] ?? 0),
                    'text' => (string) ($alt['text'] ?? ''),
                    'confidence' => isset($alt['confidence']) ? (float) $alt['confidence'] : null,
                ];
            }
            if (isset($event['finalRefinement']['normalizedText']['alternatives'][0])) {
                $key = "{$channel}:".(int) ($event['finalRefinement']['finalIndex'] ?? $index);
                if (isset($finals[$key])) {
                    $finals[$key]['text'] = (string) $event['finalRefinement']['normalizedText']['alternatives'][0]['text'];
                }
            }
        }

        usort($finals, fn (array $a, array $b) => [$a['start'], $a['channel']] <=> [$b['start'], $b['channel']]);
        $segments = [];
        foreach ($finals as $f) {
            if (trim($f['text']) !== '' && $f['end'] > $f['start']) {
                $segments[] = new TranscriptSegmentData($f['start'], $f['end'], $this->speakerLabel($f['channel']), trim($f['text']), $f['confidence']);
            }
        }

        return $segments;
    }

    private function http(ProviderConfig $config): PendingRequest
    {
        $key = $config->secret ?: throw new ProviderException(ProviderException::CONFIG_ERROR, 'Не задан ключ API.');
        $headers = ['Authorization' => "Api-Key {$key}"];
        if ($folder = $config->setting('folder_id')) {
            $headers['x-folder-id'] = (string) $folder;
        }

        return Http::withHeaders($headers)->acceptJson()
            ->timeout((int) $config->setting('timeout_seconds', 120))->connectTimeout(10)
            ->withOptions(['allow_redirects' => false]);
    }

    private function endpoint(ProviderConfig $config): string
    {
        return (string) ($config->endpoint ?: 'https://stt.api.cloud.yandex.net');
    }

    private function operations(ProviderConfig $config): string
    {
        return (string) $config->setting('operations_endpoint', 'https://operation.api.cloud.yandex.net');
    }
}
