<?php

namespace App\Domain\Sources\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Organization\OrgPath;
use App\Domain\Organization\Services\OrgPathResolver;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\SourceException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Подключения источников: создание в области права integrations.manage, настройки по полям адаптера,
 * секреты (только запись), адрес приёма событий, проверка связи, включение.
 */
final class SourceConnectionService
{
    public function __construct(
        private readonly SourceDriverRegistry $drivers,
        private readonly OrgPathResolver $paths,
        private readonly Authorizer $authorizer,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array{driver: string, name: string, company_id?: ?int, dealership_id?: ?int, department_id?: ?int, settings?: array<string, mixed>, sync_interval_minutes?: int} $data */
    public function create(User $actor, array $data): SourceConnection
    {
        $driver = $this->drivers->driver($data['driver']);
        $path = $this->path($actor, $data);
        $settings = $this->settings($data['settings'] ?? [], $driver->configFields());
        $this->assertValid($driver->validateConfiguration($settings));

        return SourceConnection::create([
            'source_id' => $this->drivers->source($data['driver'])->id,
            'name' => $data['name'],
            ...$path->toColumns(),
            'settings' => $settings,
            'webhook_token' => Str::random(48),
            'is_enabled' => false,
            'sync_interval_minutes' => $data['sync_interval_minutes'] ?? 15,
            'created_by' => $actor->id,
        ])->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, SourceConnection $connection, array $data): SourceConnection
    {
        $driver = $this->drivers->driver($connection->source->code);

        return DB::transaction(function () use ($actor, $connection, $data, $driver) {
            if (SourceConnection::query()->whereKey($connection->id)->where('lock_version', $data['lock_version'])->increment('lock_version') === 0) {
                throw ValidationException::withMessages(['lock_version' => 'Подключение изменено другим пользователем. Обновите страницу.']);
            }
            $attributes = array_intersect_key($data, array_flip(['name', 'sync_interval_minutes', 'is_enabled']));
            if (array_intersect_key($data, array_flip(['dealership_id', 'department_id'])) !== []) {
                $attributes += $this->path($actor, ['company_id' => $connection->company_id, ...$data])->toColumns();
            }
            if (array_key_exists('settings', $data)) {
                $settings = $this->settings((array) $data['settings'], $driver->configFields());
                $this->assertValid($driver->validateConfiguration($settings));
                $attributes['settings'] = $settings;
            }
            $connection->refresh()->update($attributes);

            return $connection->refresh();
        });
    }

    public function setSecret(SourceConnection $connection, ?string $secret): SourceConnection
    {
        $connection->forceFill(['secret' => $secret !== null && $secret !== '' ? $secret : null, 'secret_set_at' => $secret ? now() : null])->save();

        return $connection->refresh();
    }

    /** Новый секрет подписи событий; показывается один раз. Прежний перестаёт действовать. */
    public function regenerateWebhookSecret(SourceConnection $connection): string
    {
        $secret = Str::random(48);
        $connection->forceFill(['webhook_secret' => $secret])->save();

        return $secret;
    }

    public function test(SourceConnection $connection): array
    {
        try {
            $message = $this->drivers->driver($connection->source->code)->testConnection($connection);
            $this->audit->record('source_connection.tested', $connection, ['result' => ['old' => null, 'new' => 'ok']]);

            return ['ok' => true, 'message' => $message];
        } catch (SourceException $e) {
            $this->audit->record('source_connection.tested', $connection, ['result' => ['old' => null, 'new' => 'error']]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @param array<string, mixed> $data */
    private function path(User $actor, array $data): OrgPath
    {
        $path = $this->paths->fromUnits(null, isset($data['company_id']) ? (int) $data['company_id'] : null, $data['dealership_id'] ?? null, $data['department_id'] ?? null);
        if (! $this->authorizer->covers($actor, Permission::IntegrationsManage, $path)) {
            throw new AuthorizationException('Нет права управлять источниками в этом подразделении.');
        }

        return $path;
    }

    /**
     * Только поля, объявленные адаптером.
     *
     * @param  array<string, mixed>  $input
     * @param  list<array{key: string}>  $fields
     * @return array<string, mixed>
     */
    private function settings(array $input, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field['key'], $input)) {
                $out[$field['key']] = is_scalar($input[$field['key']]) || $input[$field['key']] === null ? $input[$field['key']] : null;
            }
        }

        return $out;
    }

    /** @param array<string, string> $errors */
    private function assertValid(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($m, $k) => ["settings.{$k}" => $m])->all());
        }
    }
}
