<?php

namespace App\Http\Controllers;

use App\Domain\Sources\Jobs\ProcessPushedEvents;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Models\SourceEvent;
use App\Domain\Sources\Services\SourceDriverRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Приём событий источника (push). Без сессии и CSRF: подлинность — токен в адресе и подпись адаптера.
 * События сохраняются (payload зашифрован) и импортируются в очереди; ответ — сразу.
 * На любую проблему с адресом отвечаем одинаково (404), не раскрывая, существует ли подключение.
 */
class SourceWebhookController extends Controller
{
    public function __invoke(Request $request, string $token, SourceDriverRegistry $drivers): JsonResponse
    {
        $connection = SourceConnection::query()->where('webhook_token', $token)->where('is_enabled', true)->first();
        $driver = $connection ? rescue(fn () => $drivers->driver($connection->source->code), null, false) : null;
        if ($connection === null || $driver === null || ! $driver->getCapabilities()['push']) {
            return response()->json(['message' => 'Не найдено.'], 404);
        }
        if (! $driver->verifyWebhook($request, $connection)) {
            Log::warning('Источник: событие с неверной подписью', ['connection_id' => $connection->id, 'ip' => $request->ip()]);

            return response()->json(['message' => 'Подпись не подтверждена.'], 401);
        }

        try {
            $payloads = array_slice($driver->webhookPayloads($request), 0, (int) config('sources.max_webhook_events', 100));
        } catch (Throwable) {
            $payloads = [];
        }
        if ($payloads === []) {
            return response()->json(['message' => 'Событий нет.'], 422);
        }

        $ids = [];
        foreach ($payloads as $payload) {
            $ids[] = SourceEvent::create([
                'source_connection_id' => $connection->id, 'channel' => 'push',
                'external_id' => isset($payload['id']) && is_scalar($payload['id']) ? Str::limit((string) $payload['id'], 180, '') : null,
                'status' => 'pending', 'payload' => $payload, 'received_at' => now(),
            ])->id;
        }
        ProcessPushedEvents::dispatch($connection->id, $ids);

        return response()->json(['accepted' => count($ids)], 202);
    }
}
