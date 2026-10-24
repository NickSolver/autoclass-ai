<?php

namespace App\Domain\Sources\Contracts;

use App\Domain\Sources\Data\NormalizedCall;
use App\Domain\Sources\Data\Recording;
use App\Domain\Sources\Data\SourceBatch;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\SourceException;
use Illuminate\Http\Request;

/**
 * Единый договор адаптера источника звонков (ТЗ, разд. 4.1). Неподдерживаемые методы объявляются
 * в capabilities() и не вызываются ядром. Адаптер не пишет в БД — нормализует и отдаёт данные.
 */
interface CallSourceDriver
{
    /** Код источника (call_sources.code). */
    public function code(): string;

    /** @return array{pull: bool, push: bool, recordings: bool} */
    public function getCapabilities(): array;

    /**
     * Поля настроек для формы подключения (секрет задаётся отдельно).
     *
     * @return list<array{key: string, label: string, type: string, required?: bool, options?: array<string, string>, hint?: string}>
     */
    public function configFields(): array;

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, string> ошибки по полям
     */
    public function validateConfiguration(array $settings): array;

    /** @throws SourceException */
    public function testConnection(SourceConnection $connection): string;

    /**
     * Порция звонков после курсора. Курсор — непрозрачная строка адаптера.
     *
     * @throws SourceException
     */
    public function fetchBatch(SourceConnection $connection, ?string $cursor): SourceBatch;

    /**
     * Запись звонка во временный локальный файл или null, если записи нет.
     *
     * @throws SourceException
     */
    public function resolveRecording(SourceConnection $connection, NormalizedCall $call): ?Recording;

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws SourceException payload не по схеме источника
     */
    public function normalize(array $payload): NormalizedCall;

    /** Подлинность входящего события (только при capabilities.push). */
    public function verifyWebhook(Request $request, SourceConnection $connection): bool;

    /**
     * События из тела webhook-запроса (уже проверенного).
     *
     * @return list<array<string, mixed>>
     */
    public function webhookPayloads(Request $request): array;
}
