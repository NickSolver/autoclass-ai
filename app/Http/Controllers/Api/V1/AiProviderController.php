<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\AiProviders\Drivers\Mock\MockScenario;
use App\Domain\AiProviders\Models\ProviderCall;
use App\Domain\AiProviders\Models\ProviderConfig;
use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\ProviderRole;
use App\Domain\AiProviders\Services\DriverRegistry;
use App\Domain\AiProviders\Services\ExternalProcessingGate;
use App\Domain\AiProviders\Services\ProviderConfigService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProviderConfigResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Провайдеры ИИ (ТЗ, разд. 8). Настройка глобальная — право ai.manage на уровне группы.
 */
class AiProviderController extends Controller
{
    public function __construct(private readonly ProviderConfigService $configs) {}

    public function index(DriverRegistry $drivers, ExternalProcessingGate $gate): JsonResponse
    {
        $this->authorize('ai.manage');

        // Статистика вызовов за 30 дней — по каждой конфигурации.
        $stats = ProviderCall::query()
            ->where('started_at', '>=', now()->subDays(30))
            ->whereNotNull('provider_config_id')
            ->groupBy('provider_config_id')
            ->select('provider_config_id', DB::raw('count(*) as calls'), DB::raw("sum(status = 'error') as errors"),
                DB::raw('sum(cost) as cost'), DB::raw('avg(duration_ms) as avg_ms'), DB::raw('max(started_at) as last_at'))
            ->get()->keyBy('provider_config_id');

        $configs = ProviderConfig::query()->orderBy('kind')->orderBy('name')->get()->each(function (ProviderConfig $c) use ($stats) {
            $s = $stats->get($c->id);
            $c->setAttribute('stats', [
                'calls_30d' => (int) $s?->getAttribute('calls'),
                'errors_30d' => (int) $s?->getAttribute('errors'),
                'cost_30d' => $s?->getAttribute('cost') !== null ? round((float) $s->getAttribute('cost'), 2) : null,
                'avg_duration_ms' => $s ? (int) $s->getAttribute('avg_ms') : null,
                'last_call_at' => $s?->getAttribute('last_at'),
                'last_error' => ProviderCall::query()->where('provider_config_id', $c->id)->where('status', 'error')
                    ->latest('started_at')->first(['error_message', 'started_at'])?->only(['error_message', 'started_at']),
            ]);
        });

        return response()->json([
            'data' => ProviderConfigResource::collection($configs),
            'drivers' => collect(ProviderKind::cases())->mapWithKeys(fn (ProviderKind $k) => [$k->value => $drivers->available($k)]),
            'presets' => DriverRegistry::PRESETS,
            'mock_scenarios' => collect(ProviderKind::cases())->mapWithKeys(fn (ProviderKind $k) => [
                $k->value => array_map(fn (MockScenario $s) => ['code' => $s->value, 'name' => $s->label()], MockScenario::forKind($k->value)),
            ]),
            'external_processing' => $gate->state(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('ai.manage');
        $data = $request->validate([
            'kind' => ['required', Rule::enum(ProviderKind::class)],
            'driver' => ['required', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:100'],
            'endpoint' => ['nullable', 'string', 'max:500'],
            'settings' => ['sometimes', 'array'],
        ]);

        return (new ProviderConfigResource($this->configs->create($request->user(), $data)))->response()->setStatusCode(201);
    }

    public function update(Request $request, ProviderConfig $provider): ProviderConfigResource
    {
        $this->authorize('ai.manage');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'endpoint' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings' => ['sometimes', 'array'],
            'lock_version' => ['required', 'integer'],
        ]);

        return new ProviderConfigResource($this->configs->update($provider, $data));
    }

    /** Задать ключ API. Значение не возвращается и не пишется в журнал. */
    public function setSecret(Request $request, ProviderConfig $provider): ProviderConfigResource
    {
        $this->authorize('ai.manage');
        $data = $request->validate([
            'secret' => ['required_without:secrets', 'nullable', 'string', 'min:8', 'max:500'],
            // Несколько ключей (T-Bank: api_key + secret_key).
            'secrets' => ['required_without:secret', 'nullable', 'array'],
            'secrets.*' => ['string', 'max:500'],
        ]);

        return new ProviderConfigResource($this->configs->setSecret($provider, $data['secrets'] ?? $data['secret']));
    }

    public function clearSecret(ProviderConfig $provider): ProviderConfigResource
    {
        $this->authorize('ai.manage');

        return new ProviderConfigResource($this->configs->setSecret($provider, null));
    }

    public function role(Request $request, ProviderConfig $provider): ProviderConfigResource
    {
        $this->authorize('ai.manage');
        $data = $request->validate(['role' => ['required', Rule::enum(ProviderRole::class)]]);

        return new ProviderConfigResource($this->configs->setRole($provider, ProviderRole::from($data['role'])));
    }

    public function test(ProviderConfig $provider): JsonResponse
    {
        $this->authorize('ai.manage');

        return response()->json($this->configs->test($provider));
    }

    /** Разрешение передачи записей внешним провайдерам — с причиной, повторным кодом MFA и аудитом. */
    public function externalProcessing(Request $request, ExternalProcessingGate $gate): JsonResponse
    {
        $this->authorize('ai.manage');
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        $this->configs->setExternalProcessing($request->user(), (bool) $data['enabled'], $data['reason']);

        return response()->json($gate->state());
    }
}
