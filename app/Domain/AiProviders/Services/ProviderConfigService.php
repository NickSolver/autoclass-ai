<?php

namespace App\Domain\AiProviders\Services;

use App\Domain\AiProviders\Drivers\Mock\MockScenario;
use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\Models\SystemSetting;
use App\Domain\AiProviders\ProviderException;
use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\ProviderRole;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProviderConfigService
{
    public function __construct(
        private readonly DriverRegistry $drivers,
        private readonly ExternalProcessingGate $gate,
        private readonly ProviderInvoker $invoker,
        private readonly AuditLogger $audit,
        private readonly EndpointGuard $endpoints,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): ProviderConfig
    {
        $kind = ProviderKind::from($data['kind']);
        $this->assertDriver($kind, $data['driver']);
        $this->assertConnection($data['driver'], ($data['endpoint'] ?? null) ?: $this->drivers->defaultEndpoint($data['driver']), $data['model'] ?? null);

        return ProviderConfig::create([
            'kind' => $kind,
            'driver' => $data['driver'],
            'name' => $data['name'],
            'model' => $data['model'] ?? null,
            'endpoint' => ($data['endpoint'] ?? null) ?: $this->drivers->defaultEndpoint($data['driver']),
            'settings' => $this->settings($kind, $data['driver'], $data['settings'] ?? []),
            'role' => ProviderRole::Inactive,
            'created_by' => $actor->id,
        ]);
    }

    /** @param array<string, mixed> $data */
    public function update(ProviderConfig $config, array $data): ProviderConfig
    {
        return DB::transaction(function () use ($config, $data) {
            $updated = ProviderConfig::query()->whereKey($config->id)->where('lock_version', $data['lock_version'])->increment('lock_version');
            if ($updated === 0) {
                throw ValidationException::withMessages(['lock_version' => 'Настройки изменены другим пользователем. Обновите страницу.']);
            }
            $config->refresh();
            $config->fill(array_intersect_key($data, array_flip(['name', 'model', 'endpoint'])));
            $this->assertConnection($config->driver, $config->endpoint, $config->model);
            if (array_key_exists('settings', $data)) {
                $config->settings = $this->settings($config->kind, $config->driver, $data['settings']);
            }
            $config->save();

            return $config;
        });
    }

    /**
     * Секрет задаётся отдельной операцией и никогда не возвращается (ТЗ, разд. 8).
     * Для драйверов с несколькими ключами (T-Bank: API-ключ и секретный ключ) хранится JSON.
     *
     * @param  string|array<string, string>|null  $secret
     */
    public function setSecret(ProviderConfig $config, string|array|null $secret): ProviderConfig
    {
        if (is_array($secret)) {
            $fields = $this->drivers->secretFields($config->driver);
            $missing = array_filter($fields, fn (string $f) => trim((string) ($secret[$f] ?? '')) === '');
            if ($missing !== []) {
                throw ValidationException::withMessages(['secrets' => 'Заполните все ключи.']);
            }
            $secret = (string) json_encode(array_intersect_key($secret, array_flip($fields)));
        }
        $config->forceFill(['secret' => $secret, 'secret_set_at' => $secret === null ? null : now()])->saveQuietly();
        $this->audit->record($secret === null ? 'provider_config.secret_cleared' : 'provider_config.secret_set', $config);

        return $config;
    }

    /** Основной и резервный — не больше одного на вид. Внешний драйвер — только при разрешённой внешней обработке. */
    public function setRole(ProviderConfig $config, ProviderRole $role): ProviderConfig
    {
        if ($role !== ProviderRole::Inactive && $this->drivers->isExternal($config->driver) && ! $this->gate->isAllowed()) {
            throw ValidationException::withMessages(['role' => 'Передача записей внешним провайдерам не разрешена. Сначала включите её в настройках (после юридической проверки).']);
        }

        DB::transaction(function () use ($config, $role) {
            if ($role !== ProviderRole::Inactive) {
                ProviderConfig::query()->where('kind', $config->kind)->where('role', $role)->whereKeyNot($config->id)
                    ->each(fn (ProviderConfig $other) => $other->update(['role' => ProviderRole::Inactive]));
            }
            $config->update(['role' => $role]);
        });

        return $config->refresh();
    }

    /** @return array{ok: bool, message: string} */
    public function test(ProviderConfig $config): array
    {
        try {
            $this->invoker->invoke($config, 'test', function () use ($config) {
                $config->kind === ProviderKind::Asr
                    ? $this->drivers->speechToText($config->driver)->testConnection($config)
                    : $this->drivers->evaluation($config->driver)->testConnection($config);

                return true;
            }, fn () => []);

            return ['ok' => true, 'message' => 'Провайдер отвечает.'];
        } catch (ProviderException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function setExternalProcessing(User $actor, bool $enabled, string $reason): void
    {
        $before = $this->gate->isAllowed();
        SystemSetting::query()->updateOrCreate(
            ['key' => ExternalProcessingGate::KEY],
            ['value' => ['enabled' => $enabled, 'reason' => $reason], 'updated_by' => $actor->id],
        );
        if (! $enabled) {
            // Выключение сразу снимает внешних провайдеров с ролей — записи наружу больше не уходят.
            ProviderConfig::query()->where('role', '!=', ProviderRole::Inactive)->get()
                ->filter(fn (ProviderConfig $c) => $this->drivers->isExternal($c->driver))
                ->each(fn (ProviderConfig $c) => $c->update(['role' => ProviderRole::Inactive]));
        }
        $this->audit->record('ai.external_processing_changed', null, ['enabled' => ['old' => $before, 'new' => $enabled]], $reason);
    }

    /** Внешнему провайдеру нужны безопасный адрес и модель. */
    private function assertConnection(string $driver, ?string $endpoint, ?string $model): void
    {
        if (! $this->drivers->isExternal($driver)) {
            return;
        }
        $this->endpoints->assertSafe($endpoint);
        if ($this->drivers->modelRequired($driver) && trim((string) $model) === '') {
            throw ValidationException::withMessages(['model' => 'Укажите модель.']);
        }
    }

    private function assertDriver(ProviderKind $kind, string $driver): void
    {
        if (! $this->drivers->exists($kind, $driver)) {
            throw ValidationException::withMessages(['driver' => 'Такой драйвер для этого вида провайдера недоступен.']);
        }
    }

    /**
     * Только известные поля настроек; секреты здесь не допускаются.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function settings(ProviderKind $kind, string $driver, array $input): array
    {
        $settings = [
            'timeout_seconds' => (int) ($input['timeout_seconds'] ?? 120),
            'requests_per_minute' => isset($input['requests_per_minute']) ? (int) $input['requests_per_minute'] : null,
            'currency' => 'RUB',
        ];
        if ($kind === ProviderKind::Asr) {
            $settings['price_per_minute'] = isset($input['price_per_minute']) ? (float) $input['price_per_minute'] : null;
        } else {
            $settings['price_per_1k_tokens_in'] = isset($input['price_per_1k_tokens_in']) ? (float) $input['price_per_1k_tokens_in'] : null;
            $settings['price_per_1k_tokens_out'] = isset($input['price_per_1k_tokens_out']) ? (float) $input['price_per_1k_tokens_out'] : null;
        }
        if ($kind === ProviderKind::Asr && $driver !== 'mock') {
            $settings['language'] = (string) ($input['language'] ?? ($driver === 'openai' ? 'ru' : 'ru-RU'));
            $settings['max_wait_seconds'] = max(60, min(3600, (int) ($input['max_wait_seconds'] ?? 600)));
            $settings['poll_seconds'] = max(1, min(30, (int) ($input['poll_seconds'] ?? 2)));
        }
        if (in_array($driver, ['yandex', 'tbank'], true)) {
            // Стерео: каналы — говорящие (в телефонной записи сотрудник и клиент часто на разных каналах).
            $settings['multichannel'] = (bool) ($input['multichannel'] ?? true);
        }
        if ($driver === 'yandex') {
            $settings['speaker_labeling'] = (bool) ($input['speaker_labeling'] ?? true);
            $folder = trim((string) ($input['folder_id'] ?? ''));
            $settings['folder_id'] = $folder !== '' ? $folder : null;
        }
        if ($driver === 'openai_compatible') {
            $settings['json_mode'] = (bool) ($input['json_mode'] ?? true);
            $settings['temperature'] = max(0.0, min(2.0, (float) ($input['temperature'] ?? 0)));
            $settings['max_tokens'] = isset($input['max_tokens']) ? max(256, (int) $input['max_tokens']) : null;
        }
        if ($driver === 'mock') {
            $scenario = MockScenario::tryFrom((string) ($input['mock_scenario'] ?? 'normal'));
            if ($scenario === null || ! in_array($scenario, MockScenario::forKind($kind->value), true)) {
                throw ValidationException::withMessages(['settings.mock_scenario' => 'Неизвестный режим тестового провайдера.']);
            }
            $settings['mock_scenario'] = $scenario->value;
        }

        return $settings;
    }
}
