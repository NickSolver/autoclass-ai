<?php

namespace App\Domain\Sources\Drivers;

use App\Domain\CallIntake\CallDirection;
use App\Domain\Sources\Contracts\CallSourceDriver;
use App\Domain\Sources\Data\NormalizedCall;
use App\Domain\Sources\Data\Recording;
use App\Domain\Sources\Data\SourceBatch;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\SourceException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Тестовый источник: детерминированные синтетические звонки (номер по курсору), записи-тоны без речи.
 * Формат payload и подпись событий — собственные, только для проверки ядра; это не API внешней системы.
 */
final class MockSourceDriver implements CallSourceDriver
{
    public const SCENARIOS = [
        'normal' => 'Обычный режим',
        'no_recording' => 'Каждый третий звонок без записи',
        'auth_error' => 'Ошибка: неверный ключ',
        'unavailable' => 'Ошибка: источник недоступен',
        'bad_payload' => 'Ошибка: запись не по схеме',
    ];

    public function code(): string
    {
        return 'mock';
    }

    public function getCapabilities(): array
    {
        return ['pull' => true, 'push' => true, 'recordings' => true];
    }

    public function configFields(): array
    {
        return [
            ['key' => 'scenario', 'label' => 'Режим тестового источника', 'type' => 'select', 'options' => self::SCENARIOS, 'required' => true],
            ['key' => 'calls_per_batch', 'label' => 'Звонков за запрос', 'type' => 'number', 'required' => true, 'hint' => 'От 1 до 20.'],
            ['key' => 'total_calls', 'label' => 'Всего звонков в источнике', 'type' => 'number', 'required' => true, 'hint' => 'От 1 до 500.'],
            ['key' => 'extensions', 'label' => 'Добавочные сотрудников', 'type' => 'text', 'hint' => 'Через запятую; сопоставляются с добавочными сотрудников компании.'],
        ];
    }

    public function validateConfiguration(array $settings): array
    {
        $errors = [];
        if (! isset(self::SCENARIOS[(string) ($settings['scenario'] ?? '')])) {
            $errors['scenario'] = 'Выберите режим.';
        }
        $perBatch = filter_var($settings['calls_per_batch'] ?? null, FILTER_VALIDATE_INT);
        if ($perBatch === false || $perBatch < 1 || $perBatch > 20) {
            $errors['calls_per_batch'] = 'От 1 до 20.';
        }
        $total = filter_var($settings['total_calls'] ?? null, FILTER_VALIDATE_INT);
        if ($total === false || $total < 1 || $total > 500) {
            $errors['total_calls'] = 'От 1 до 500.';
        }
        if (! preg_match('/^[0-9, ]*$/', (string) ($settings['extensions'] ?? ''))) {
            $errors['extensions'] = 'Только цифры и запятые.';
        }

        return $errors;
    }

    public function testConnection(SourceConnection $connection): string
    {
        $this->failIfScenario($connection);

        return 'Тестовый источник отвечает. Звонки синтетические.';
    }

    public function fetchBatch(SourceConnection $connection, ?string $cursor): SourceBatch
    {
        $this->failIfScenario($connection);
        $from = max(0, (int) $cursor);
        $total = (int) $connection->setting('total_calls', 10);
        $to = min($total, $from + (int) $connection->setting('calls_per_batch', 3));
        $payloads = [];
        for ($n = $from + 1; $n <= $to; $n++) {
            $payloads[] = $this->payload($connection, $n);
        }

        return new SourceBatch($payloads, $to > $from ? (string) $to : $cursor, $to < $total);
    }

    public function resolveRecording(SourceConnection $connection, NormalizedCall $call): ?Recording
    {
        if ($call->recordingRefs === []) {
            return null;
        }
        $seconds = max(1, (int) round(($call->durationMs ?? 5000) / 1000));
        $path = tempnam(sys_get_temp_dir(), 'src-mock-');
        file_put_contents($path, self::sineWav($seconds, 300 + (crc32($call->externalId) % 500)));

        return new Recording($path, "{$call->externalId}.wav");
    }

    public function normalize(array $payload): NormalizedCall
    {
        if (! isset($payload['id'], $payload['started_at']) || ! is_string($payload['id']) || ! is_numeric($payload['duration_sec'] ?? null)) {
            throw new SourceException(SourceException::BAD_PAYLOAD, 'Запись тестового источника не по схеме: нет id, started_at или duration_sec.');
        }

        return new NormalizedCall(
            externalId: $payload['id'],
            direction: CallDirection::tryFrom((string) ($payload['direction'] ?? '')) ?? CallDirection::Unknown,
            startedAt: Carbon::parse((string) $payload['started_at']),
            timezone: (string) ($payload['timezone'] ?? 'Europe/Moscow'),
            durationMs: (int) round((float) $payload['duration_sec'] * 1000),
            clientPhone: isset($payload['client_phone']) ? (string) $payload['client_phone'] : null,
            extensions: isset($payload['extension']) && $payload['extension'] !== '' ? [(string) $payload['extension']] : [],
            recordingRefs: ! empty($payload['has_recording']) ? [$payload['id']] : [],
            metadata: array_filter(['campaign' => $payload['campaign'] ?? null]),
            raw: $payload,
        );
    }

    public function verifyWebhook(Request $request, SourceConnection $connection): bool
    {
        $secret = (string) $connection->webhook_secret;
        $signature = (string) $request->header('X-Mock-Signature', '');

        return $secret !== '' && $signature !== '' && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    public function webhookPayloads(Request $request): array
    {
        $calls = $request->json('calls');

        return is_array($calls) ? array_values(array_filter($calls, 'is_array')) : [];
    }

    /** @return array<string, mixed> */
    public function payload(SourceConnection $connection, int $n): array
    {
        $extensions = array_values(array_filter(array_map('trim', explode(',', (string) $connection->setting('extensions', '')))));
        $noRecording = $connection->setting('scenario') === 'no_recording' && $n % 3 === 0;
        $payload = [
            'id' => "mock-{$n}",
            'direction' => $n % 2 === 0 ? 'outgoing' : 'incoming',
            'started_at' => now()->subMinutes(10 * $n)->startOfMinute()->toIso8601String(),
            'timezone' => 'Europe/Moscow',
            'duration_sec' => $noRecording ? 0 : 20 + ($n % 5) * 8,
            'client_phone' => sprintf('+7900000%04d', $n),
            'extension' => $extensions !== [] ? $extensions[$n % count($extensions)] : null,
            'has_recording' => ! $noRecording,
            'campaign' => 'Демо-кампания',
        ];
        if ($connection->setting('scenario') === 'bad_payload' && $n === 1) {
            unset($payload['started_at']);
        }

        return $payload;
    }

    private function failIfScenario(SourceConnection $connection): void
    {
        match ($connection->setting('scenario')) {
            'auth_error' => throw new SourceException(SourceException::AUTH, 'Источник отклонил ключ доступа (тестовый режим).'),
            'unavailable' => throw new SourceException(SourceException::UNAVAILABLE, 'Источник недоступен (тестовый режим).'),
            default => null,
        };
    }

    /** Синтетическая запись: синус, PCM 16 бит, моно, 8 кГц — без речи. */
    public static function sineWav(int $seconds, int $frequency): string
    {
        $rate = 8000;
        $samples = $seconds * $rate;
        $data = '';
        for ($i = 0; $i < $samples; $i++) {
            $data .= pack('v', (int) round(sin(2 * M_PI * $frequency * $i / $rate) * 12000) & 0xFFFF);
        }

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($data)).$data;
    }
}
