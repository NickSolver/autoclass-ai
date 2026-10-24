<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Permission;
use App\Domain\Sources\Jobs\SyncSourceConnection;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Models\SourceEvent;
use App\Domain\Sources\Models\SourceSyncRun;
use App\Domain\Sources\Services\SourceConnectionService;
use App\Domain\Sources\Services\SourceDriverRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Источники звонков: подключения, синхронизация, журнал (ТЗ, разд. 4.1). */
class SourceConnectionController extends Controller
{
    public function __construct(private readonly SourceConnectionService $connections, private readonly SourceDriverRegistry $drivers) {}

    /** Адаптеры: доступные и ожидающие документации, с полями настроек. */
    public function drivers(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permission::IntegrationsManage), 403, 'Нет доступа к источникам звонков.');

        return response()->json(['data' => collect($this->drivers->all())->map(fn (array $d, string $code) => [
            'code' => $code, ...$d,
            'capabilities' => $d['available'] ? $this->drivers->driver($code)->getCapabilities() : null,
            'fields' => $d['available'] ? $this->drivers->driver($code)->configFields() : [],
        ])->values()]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permission::IntegrationsManage), 403, 'Нет доступа к источникам звонков.');
        $items = SourceConnection::query()->visibleTo($request->user(), Permission::IntegrationsManage)
            ->with(['source', 'company:id,name'])->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn (SourceConnection $c) => $this->summary($c))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'driver' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'required_without_all:dealership_id,department_id', 'integer'],
            'dealership_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'settings' => ['sometimes', 'array'],
            'sync_interval_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
        ]);

        return response()->json($this->detail($this->connections->create($request->user(), $data)), 201);
    }

    public function show(SourceConnection $sourceConnection): JsonResponse
    {
        return response()->json($this->detail($sourceConnection));
    }

    public function update(Request $request, SourceConnection $sourceConnection): JsonResponse
    {
        $data = $request->validate([
            'lock_version' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:255'],
            'dealership_id' => ['sometimes', 'nullable', 'integer'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'settings' => ['sometimes', 'array'],
            'sync_interval_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'is_enabled' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->detail($this->connections->update($request->user(), $sourceConnection, $data)));
    }

    public function setSecret(Request $request, SourceConnection $sourceConnection): JsonResponse
    {
        $data = $request->validate(['secret' => ['nullable', 'string', 'max:4000']]);

        return response()->json($this->detail($this->connections->setSecret($sourceConnection, $data['secret'] ?? null)));
    }

    /** Новый секрет подписи — в ответе один раз. */
    public function regenerateWebhookSecret(SourceConnection $sourceConnection): JsonResponse
    {
        $secret = $this->connections->regenerateWebhookSecret($sourceConnection);

        return response()->json(['webhook_secret' => $secret, 'connection' => $this->detail($sourceConnection->refresh())]);
    }

    public function test(SourceConnection $sourceConnection): JsonResponse
    {
        return response()->json($this->connections->test($sourceConnection));
    }

    public function sync(Request $request, SourceConnection $sourceConnection): JsonResponse
    {
        SyncSourceConnection::dispatch($sourceConnection->id, 'manual', $request->user()->id);

        return response()->json(['message' => 'Синхронизация запущена.', 'connection' => $this->detail($sourceConnection->refresh())], 202);
    }

    public function events(Request $request, SourceConnection $sourceConnection): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:imported,duplicate,without_recording,failed,pending'], 'page' => ['nullable', 'integer']]);
        $page = $sourceConnection->events()->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (SourceEvent $e) => [
                'id' => $e->id, 'channel' => $e->channel, 'external_id' => $e->external_id, 'status' => $e->status,
                'call_id' => $e->call_id, 'error_message' => $e->error_message, 'received_at' => $e->received_at->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(SourceConnection $c): array
    {
        $driver = $this->drivers->all()[$c->source->code] ?? null;

        return [
            'id' => $c->id, 'name' => $c->name, 'driver' => $c->source->code, 'driver_name' => $driver['name'] ?? $c->source->name,
            'driver_available' => (bool) ($driver['available'] ?? false),
            'company' => ['id' => $c->company->id, 'name' => $c->company->name],
            'is_enabled' => $c->is_enabled, 'sync_interval_minutes' => $c->sync_interval_minutes,
            'last_sync_at' => $c->last_sync_at?->toIso8601String(), 'last_success_at' => $c->last_success_at?->toIso8601String(),
            'last_error' => $c->last_error,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(SourceConnection $c): array
    {
        $c->loadMissing(['source', 'company:id,name']);
        $summary = $this->summary($c);
        $driver = $summary['driver_available'] ? $this->drivers->driver($c->source->code) : null;

        return [
            ...$summary,
            'dealership_id' => $c->dealership_id, 'department_id' => $c->department_id,
            'settings' => $c->settings, 'lock_version' => $c->lock_version, 'cursor' => $c->cursor,
            'has_secret' => $c->secret !== null, 'secret_set_at' => $c->secret_set_at?->toIso8601String(),
            'capabilities' => $driver?->getCapabilities(),
            'fields' => $driver?->configFields() ?? [],
            // Адрес приёма событий виден только тем, кто управляет подключением.
            'webhook' => $driver !== null && $driver->getCapabilities()['push'] ? [
                'url' => url("/api/v1/source-webhooks/{$c->webhook_token}"),
                'has_secret' => $c->webhook_secret !== null,
            ] : null,
            'runs' => $c->syncRuns()->orderByDesc('id')->limit(10)->get()->map(fn (SourceSyncRun $r) => [
                'id' => $r->id, 'trigger' => $r->trigger, 'status' => $r->status, 'fetched' => $r->fetched, 'created' => $r->created,
                'duplicates' => $r->duplicates, 'without_recording' => $r->without_recording, 'failed' => $r->failed,
                'error_message' => $r->error_message, 'started_at' => $r->started_at->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
