<?php

namespace App\Domain\AiProviders\Drivers\TBank;

use App\Domain\AiProviders\Contracts\AudioInput;
use App\Domain\AiProviders\Contracts\SpeechToTextProviderInterface;
use App\Domain\AiProviders\Contracts\TranscriptionResult;
use App\Domain\AiProviders\Contracts\TranscriptSegmentData;
use App\Domain\AiProviders\Drivers\Concerns\LabelsSpeakers;
use App\Domain\AiProviders\Drivers\Concerns\MapsHttpErrors;
use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * T-Bank VoiceKit (бывш. Tinkoff), REST-шлюз gRPC. Сверено с официальными proto и примером
 * авторизации Tinkoff/voicekit-examples (stt/v1, longrunning/v1, python/auth.py), ADR-0005:
 * 1) POST {endpoint}/v1/stt:longrunningrecognize — config (MPEG_AUDIO, частота, каналы) + audio.content;
 * 2) GET {endpoint}/v1/operations/{id} до state DONE/FAILED; ответ — RecognizeResponse.results:
 *    фразы с channel, start_time/end_time («1.200s»), alternatives[0].transcript.
 * Авторизация — JWT HS256 (kid = API-ключ, подпись секретным ключом в base64url, aud = tinkoff.cloud.stt).
 * Разделения говорящих в моно нет: говорящие различаются только по каналам стереозаписи.
 */
final class TBankVoiceKit implements SpeechToTextProviderInterface
{
    use LabelsSpeakers, MapsHttpErrors;

    public function transcribe(ProviderConfig $config, AudioInput $audio): TranscriptionResult
    {
        $keepChannels = (bool) $config->setting('multichannel', true);
        $channels = $audio->channelsFor($keepChannels);
        $file = $audio->encode('mp3', 16000, $keepChannels, '48k');

        $operation = $this->guarded(fn () => $this->http($config)->post($this->url($this->endpoint($config), '/v1/stt:longrunningrecognize'), [
            'config' => [
                'encoding' => 'MPEG_AUDIO',
                'sampleRateHertz' => 16000,
                'numChannels' => $channels,
                'languageCode' => (string) $config->setting('language', 'ru-RU'),
                'maxAlternatives' => 1,
                'enableAutomaticPunctuation' => true,
                'enableDenormalization' => true,
            ] + ($config->model ? ['model' => $config->model] : []),
            'audio' => ['content' => base64_encode((string) file_get_contents($file))],
        ]));
        $id = (string) ($operation->json('id') ?? throw new ProviderException(ProviderException::BAD_RESPONSE, 'T-Bank не вернул идентификатор операции.'));

        $deadline = now()->getTimestamp() + (int) $config->setting('max_wait_seconds', 600);
        $done = $operation;
        while (! $this->finished($done)) {
            if (now()->getTimestamp() >= $deadline) {
                throw new ProviderException(ProviderException::TIMEOUT, 'Распознавание в T-Bank не завершилось за отведённое время.');
            }
            Sleep::for((int) $config->setting('poll_seconds', 2))->seconds();
            $done = $this->guarded(fn () => $this->http($config)->get($this->url($this->endpoint($config), "/v1/operations/{$id}")));
        }
        if ($done->json('error') || $done->json('state') === 'FAILED') {
            throw new ProviderException(ProviderException::BAD_RESPONSE, 'T-Bank не смог распознать запись: '.$this->safeMessage((string) $done->json('error.message', 'без описания')));
        }

        $phrases = [];
        foreach ((array) $done->json('response.results') as $r) {
            $phrases[] = [
                'text' => trim((string) ($r['alternatives'][0]['transcript'] ?? '')),
                'start' => $this->ms($r['startTime'] ?? '0s'),
                'end' => $this->ms($r['endTime'] ?? '0s'),
                'channel' => (string) ($r['channel'] ?? 0),
                'confidence' => isset($r['alternatives'][0]['confidence']) ? (float) $r['alternatives'][0]['confidence'] : null,
            ];
        }
        // Сначала порядок во времени, потом метки: кто заговорил первым — S1.
        usort($phrases, fn (array $a, array $b) => [$a['start'], $a['channel']] <=> [$b['start'], $b['channel']]);
        $segments = [];
        foreach ($phrases as $p) {
            if ($p['text'] !== '' && $p['end'] > $p['start']) {
                $segments[] = new TranscriptSegmentData($p['start'], $p['end'], $this->speakerLabel($p['channel']), $p['text'], $p['confidence']);
            }
        }
        if ($segments === []) {
            throw new ProviderException(ProviderException::BAD_RESPONSE, 'В записи не распознано ни одной фразы.');
        }

        return new TranscriptionResult($segments, (string) $config->setting('language', 'ru-RU'), $audio->durationMs / 1000 * $channels);
    }

    public function testConnection(ProviderConfig $config): void
    {
        // Список операций — лёгкий запрос, проверяющий ключи и подпись.
        $this->guarded(fn () => $this->http($config)->get($this->url($this->endpoint($config), '/v1/operations')));
    }

    public function isExternal(): bool
    {
        return true;
    }

    private function finished(Response $operation): bool
    {
        return in_array($operation->json('state'), ['DONE', 'FAILED'], true) || $operation->json('response') !== null || $operation->json('error') !== null;
    }

    /** Длительность protobuf в JSON: «1.200s». */
    private function ms(string|int|float $duration): int
    {
        return (int) round(((float) rtrim((string) $duration, 's')) * 1000);
    }

    private function http(ProviderConfig $config): PendingRequest
    {
        return Http::withToken($this->jwt($config))->acceptJson()
            ->timeout((int) $config->setting('timeout_seconds', 120))->connectTimeout(10)
            ->withOptions(['allow_redirects' => false]);
    }

    /** JWT по образцу официального примера voicekit-examples/python/auth.py. */
    private function jwt(ProviderConfig $config): string
    {
        $keys = json_decode((string) $config->secret, true);
        $apiKey = (string) ($keys['api_key'] ?? '');
        $secretKey = (string) ($keys['secret_key'] ?? '');
        if ($apiKey === '' || $secretKey === '') {
            throw new ProviderException(ProviderException::CONFIG_ERROR, 'Не заданы API-ключ и секретный ключ T-Bank.');
        }

        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $apiKey], JSON_UNESCAPED_SLASHES));
        $payload = $b64((string) json_encode(['iss' => 'avtoklass-ai', 'sub' => 'avtoklass-ai', 'aud' => 'tinkoff.cloud.stt', 'exp' => time() + 600], JSON_UNESCAPED_SLASHES));
        $secret = base64_decode(strtr($secretKey, '-_', '+/'), true);
        if ($secret === false) {
            throw new ProviderException(ProviderException::CONFIG_ERROR, 'Секретный ключ T-Bank должен быть в base64.');
        }

        return "{$header}.{$payload}.".$b64(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));
    }

    private function endpoint(ProviderConfig $config): string
    {
        return (string) ($config->endpoint ?: 'https://api.tinkoff.ai');
    }
}
