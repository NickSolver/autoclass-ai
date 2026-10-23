<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\AiProviders\Models\ProviderCall;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Журнал вызовов провайдеров — отдельное право ai.logs.view (ТЗ, разд. 8). Только метаданные. */
class AiProviderCallController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('ai.logs');
        $f = $request->validate([
            'provider_config_id' => ['sometimes', 'integer'],
            'call_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'in:ok,error'],
        ]);

        $page = ProviderCall::query()->with('config:id,name')
            ->when($f['provider_config_id'] ?? null, fn ($q, $v) => $q->where('provider_config_id', $v))
            ->when($f['call_id'] ?? null, fn ($q, $v) => $q->where('call_id', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('started_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($page->items())->map(fn (ProviderCall $c) => [
                'id' => $c->id,
                'provider' => $c->config?->name,
                'kind' => $c->kind,
                'driver' => $c->driver,
                'model' => $c->model,
                'operation' => $c->operation,
                'call_id' => $c->call_id,
                'prompt_version' => $c->prompt_version,
                'started_at' => $c->started_at->toIso8601String(),
                'duration_ms' => $c->duration_ms,
                'status' => $c->status,
                'error_code' => $c->error_code,
                'error_message' => $c->error_message,
                'attempt' => $c->attempt,
                'audio_seconds' => $c->audio_seconds !== null ? (float) $c->audio_seconds : null,
                'tokens_in' => $c->tokens_in,
                'tokens_out' => $c->tokens_out,
                'cost' => $c->cost !== null ? (float) $c->cost : null,
                'currency' => $c->currency,
            ]),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
        ]);
    }
}
