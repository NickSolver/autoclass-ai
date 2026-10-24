<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\Checklist;
use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;
use App\Domain\Workflow\Graph\FieldCatalog;
use App\Domain\Workflow\Graph\NodeTypes;
use App\Domain\Workflow\Models\WorkflowDefinition;
use App\Domain\Workflow\Models\WorkflowVersion;
use App\Domain\Workflow\Services\DesignAccess;
use App\Domain\Workflow\Services\WorkflowService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Визуальный конструктор бизнес-процессов (ТЗ, разд. 6). */
class WorkflowController extends Controller
{
    public function __construct(private readonly WorkflowService $workflows, private readonly DesignAccess $access) {}

    /** Палитра узлов, белый список полей и операторов, доступные чек-листы и подразделения области. */
    public function catalog(Request $request): JsonResponse
    {
        $scopes = $this->assertAnyAccess($request);
        $companyId = $request->filled('company_id') ? $request->integer('company_id') : null;
        abort_if($companyId !== null && ! $scopes['global'] && ! in_array($companyId, $scopes['companies'], true), 404);
        // Подразделения — только из области пользователя: без утечки названий чужих компаний.
        $companies = $companyId !== null ? [$companyId] : ($scopes['global'] ? null : $scopes['companies']);

        return response()->json([
            'node_types' => NodeTypes::all(),
            'triggers' => NodeTypes::TRIGGERS,
            'priorities' => NodeTypes::PRIORITIES,
            'fields' => FieldCatalog::fields(),
            'operators' => FieldCatalog::OPERATORS,
            'operator_labels' => FieldCatalog::OPERATOR_LABELS,
            'checklists' => Checklist::query()->where('is_archived', false)
                ->where(fn ($q) => $q->whereNull('company_id')->when($companyId, fn ($w) => $w->orWhere('company_id', $companyId)))
                ->orderBy('name')->get()
                ->map(fn (Checklist $c) => ['id' => $c->id, 'name' => $c->name, 'is_demo' => $c->is_demo, 'published_version' => $c->latestPublished()?->version])
                ->values(),
            'org' => [
                'company' => Company::query()->when($companies !== null, fn ($q) => $q->whereIn('id', $companies))->orderBy('name')->get(['id', 'name']),
                'dealership' => Dealership::query()->when($companies !== null, fn ($q) => $q->whereIn('company_id', $companies))->orderBy('name')->get(['id', 'name']),
                'department' => Department::query()->with('dealership:id,name')->when($companies !== null, fn ($q) => $q->whereIn('company_id', $companies))->orderBy('name')->get()
                    ->map(fn (Department $d) => ['id' => $d->id, 'name' => trim(($d->dealership?->name ? $d->dealership->name.' / ' : '').$d->name)])->values(),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $scopes = $this->assertAnyAccess($request);
        $items = WorkflowDefinition::query()->with(['company:id,name', 'versions'])
            ->where(fn ($q) => $q->when($scopes['global'], fn ($w) => $w->orWhereNull('company_id'))->orWhereIn('company_id', $scopes['companies']))
            ->orderBy('is_archived')->orderByRaw('company_id is null desc')->orderBy('priority')->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn (WorkflowDefinition $d) => $this->summary($d))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);
        $this->access->authorize($request->user(), Permission::WorkflowDesign, $data['company_id'] ?? null);

        return response()->json($this->summary($this->workflows->create($request->user(), $data)->load(['company', 'versions'])), 201);
    }

    public function show(Request $request, WorkflowDefinition $workflow): JsonResponse
    {
        $this->assertVisible($request, $workflow);
        $workflow->load(['company:id,name', 'versions']);
        $selected = $request->filled('version')
            ? $workflow->versions->firstWhere('id', $request->integer('version'))
            : ($workflow->versions->firstWhere('status', 'draft') ?? $workflow->versions->first());

        return response()->json([
            ...$this->summary($workflow),
            'versions' => $workflow->versions->map(fn (WorkflowVersion $v) => [
                'id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'published_at' => $v->published_at?->toIso8601String(),
            ])->values(),
            'selected' => $selected ? $this->version($selected) : null,
            'can' => [
                'design' => $this->access->can($request->user(), Permission::WorkflowDesign, $workflow->company_id),
                'publish' => $this->access->can($request->user(), Permission::WorkflowPublish, $workflow->company_id),
            ],
        ]);
    }

    public function update(Request $request, WorkflowDefinition $workflow): JsonResponse
    {
        $this->access->authorize($request->user(), Permission::WorkflowDesign, $workflow->company_id);
        $workflow->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'is_archived' => ['sometimes', 'boolean'],
        ]));

        return response()->json($this->summary($workflow->load(['company', 'versions'])));
    }

    public function saveDraft(Request $request, WorkflowDefinition $workflow, WorkflowVersion $version): JsonResponse
    {
        $this->access->authorize($request->user(), Permission::WorkflowDesign, $workflow->company_id);
        $data = $request->validate(['graph' => ['required', 'array'], 'graph.nodes' => ['present', 'array'], 'graph.edges' => ['present', 'array'], 'lock_version' => ['required', 'integer']]);

        return response()->json($this->version($this->workflows->saveDraft($version, $data)));
    }

    public function validateVersion(Request $request, WorkflowDefinition $workflow, WorkflowVersion $version): JsonResponse
    {
        $this->assertVisible($request, $workflow);

        return response()->json(['errors' => $this->workflows->validate($version)]);
    }

    public function simulate(Request $request, WorkflowDefinition $workflow, WorkflowVersion $version): JsonResponse
    {
        $this->assertVisible($request, $workflow);
        $data = $request->validate(['input' => ['sometimes', 'array'], 'call_id' => ['sometimes', 'nullable', 'integer']]);
        $call = null;
        if (! empty($data['call_id'])) {
            $call = Call::query()->visibleTo($request->user(), Permission::CallsView)->find($data['call_id'])
                ?? throw ValidationException::withMessages(['call_id' => 'Звонок не найден в вашей области.']);
        }

        return response()->json(['errors' => $this->workflows->validate($version), ...$this->workflows->simulate($version, $data['input'] ?? [], $call)]);
    }

    public function publish(Request $request, WorkflowDefinition $workflow, WorkflowVersion $version): JsonResponse
    {
        $this->access->authorize($request->user(), Permission::WorkflowPublish, $workflow->company_id);

        return response()->json($this->version($this->workflows->publish($request->user(), $version)));
    }

    public function newDraft(Request $request, WorkflowDefinition $workflow): JsonResponse
    {
        $this->access->authorize($request->user(), Permission::WorkflowDesign, $workflow->company_id);

        return response()->json($this->version($this->workflows->newDraft($request->user(), $workflow)), 201);
    }

    /** @return array{global: bool, companies: list<int>} */
    private function assertAnyAccess(Request $request): array
    {
        $scopes = $this->access->scopes($request->user(), Permission::WorkflowDesign, Permission::WorkflowPublish);
        abort_unless($scopes['global'] || $scopes['companies'] !== [], 403, 'Нет доступа к конструктору процессов.');

        return $scopes;
    }

    private function assertVisible(Request $request, WorkflowDefinition $workflow): void
    {
        abort_unless($this->access->visible($request->user(), $workflow->company_id, Permission::WorkflowDesign, Permission::WorkflowPublish), 404);
    }

    /** @return array<string, mixed> */
    private function summary(WorkflowDefinition $d): array
    {
        $published = $d->versions->where('status', 'published')->sortByDesc('version')->first();

        return [
            'id' => $d->id, 'name' => $d->name, 'description' => $d->description, 'trigger' => $d->trigger,
            'priority' => $d->priority, 'is_archived' => $d->is_archived,
            'company' => $d->company ? ['id' => $d->company->id, 'name' => $d->company->name] : null,
            'published_version' => $published?->version,
            'has_draft' => $d->versions->contains('status', 'draft'),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function version(WorkflowVersion $v): array
    {
        return [
            'id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'graph' => $v->graph,
            'lock_version' => $v->lock_version, 'published_at' => $v->published_at?->toIso8601String(),
        ];
    }
}
