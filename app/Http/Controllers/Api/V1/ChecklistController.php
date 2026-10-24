<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\AiProviders\ProviderException;
use App\Domain\AiProviders\ProviderKind;
use App\Domain\AiProviders\Services\ProviderSelector;
use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\Checklist;
use App\Domain\Evaluation\Models\ChecklistCriterion;
use App\Domain\Evaluation\Models\ChecklistVersion;
use App\Domain\Evaluation\Services\AnalysisService;
use App\Domain\Evaluation\Services\ChecklistService;
use App\Domain\Identity\Permission;
use App\Domain\Transcription\Models\TranscriptVersion;
use App\Domain\Workflow\Services\DesignAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Конструктор чек-листов (ТЗ, разд. 7). */
class ChecklistController extends Controller
{
    public function __construct(private readonly ChecklistService $checklists, private readonly DesignAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $scopes = $this->access->scopes($request->user(), Permission::ChecklistDesign, Permission::ChecklistPublish, Permission::WorkflowDesign);
        abort_unless($scopes['global'] || $scopes['companies'] !== [], 403, 'Нет доступа к конструктору чек-листов.');

        $items = Checklist::query()->with(['versions' => fn ($q) => $q->withCount('criteria'), 'company:id,name'])
            ->where(fn ($q) => $q->when($scopes['global'], fn ($w) => $w->orWhereNull('company_id'))->orWhereIn('company_id', $scopes['companies']))
            ->orderBy('is_archived')->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn (Checklist $c) => $this->summary($c))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', 'unique:checklists,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);
        $this->access->authorize($request->user(), Permission::ChecklistDesign, $data['company_id'] ?? null);

        return response()->json($this->summary($this->checklists->create($request->user(), $data)->load(['versions', 'company'])), 201);
    }

    public function show(Request $request, Checklist $checklist): JsonResponse
    {
        $this->assertVisible($request, $checklist);
        $checklist->load(['versions' => fn ($q) => $q->withCount('criteria'), 'company:id,name']);
        $selected = $request->filled('version')
            ? $checklist->versions->firstWhere('id', $request->integer('version'))
            : ($checklist->versions->firstWhere('status', 'draft') ?? $checklist->versions->first());

        return response()->json([
            ...$this->summary($checklist),
            'versions' => $checklist->versions->map(fn (ChecklistVersion $v) => [
                'id' => $v->id, 'version' => $v->version, 'status' => $v->status,
                'published_at' => $v->published_at?->toIso8601String(), 'criteria_count' => $v->criteria_count,
            ])->values(),
            'selected' => $selected ? $this->version($selected) : null,
            'can' => [
                'design' => $this->access->can($request->user(), Permission::ChecklistDesign, $checklist->company_id),
                'publish' => $this->access->can($request->user(), Permission::ChecklistPublish, $checklist->company_id),
            ],
        ]);
    }

    public function update(Request $request, Checklist $checklist): JsonResponse
    {
        $this->access->authorize($request->user(), Permission::ChecklistDesign, $checklist->company_id);
        $checklist->update($request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_archived' => ['sometimes', 'boolean'],
        ]));

        return response()->json($this->summary($checklist->load(['versions', 'company'])));
    }

    public function saveDraft(Request $request, Checklist $checklist, ChecklistVersion $version): JsonResponse
    {
        $this->access->authorize($request->user(), Permission::ChecklistDesign, $checklist->company_id);
        $data = $request->validate([
            'lock_version' => ['required', 'integer'],
            'zero_on_critical' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'criteria' => ['present', 'array', 'max:100'],
            'criteria.*.code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'criteria.*.title' => ['required', 'string', 'max:255'],
            'criteria.*.description' => ['nullable', 'string', 'max:2000'],
            'criteria.*.ai_hint' => ['nullable', 'string', 'max:2000'],
            'criteria.*.weight' => ['required', 'numeric', 'min:0', 'max:1000'],
            'criteria.*.is_required' => ['boolean'],
            'criteria.*.is_critical' => ['boolean'],
            'criteria.*.applicability' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($this->version($this->checklists->saveDraft($version, $data)));
    }

    public function publish(Request $request, Checklist $checklist, ChecklistVersion $version): JsonResponse
    {
        $this->access->authorize($request->user(), Permission::ChecklistPublish, $checklist->company_id);

        return response()->json($this->version($this->checklists->publish($request->user(), $version)));
    }

    public function newDraft(Request $request, Checklist $checklist): JsonResponse
    {
        $this->access->authorize($request->user(), Permission::ChecklistDesign, $checklist->company_id);

        return response()->json($this->version($this->checklists->newDraft($request->user(), $checklist)), 201);
    }

    public function compare(Request $request, Checklist $checklist): JsonResponse
    {
        $this->assertVisible($request, $checklist);
        $data = $request->validate(['from' => ['required', 'integer'], 'to' => ['required', 'integer']]);
        $from = $checklist->versions()->findOrFail($data['from']);
        $to = $checklist->versions()->findOrFail($data['to']);

        return response()->json(['from' => $from->version, 'to' => $to->version, 'changes' => $this->checklists->compare($from, $to)]);
    }

    /** Пробная оценка версии на звонке (результат не сохраняется в звонок). */
    public function test(Request $request, Checklist $checklist, ChecklistVersion $version, ProviderSelector $providers, AnalysisService $analysis): JsonResponse
    {
        $this->access->authorize($request->user(), Permission::ChecklistDesign, $checklist->company_id);
        $data = $request->validate(['call_id' => ['required', 'integer']]);
        $call = Call::query()->visibleTo($request->user(), Permission::CallsView)->find($data['call_id'])
            ?? throw ValidationException::withMessages(['call_id' => 'Звонок не найден в вашей области.']);
        $transcript = TranscriptVersion::query()->where('call_id', $call->id)->orderByDesc('version')->first()
            ?? throw ValidationException::withMessages(['call_id' => 'У звонка нет расшифровки.']);
        $config = $providers->primary(ProviderKind::Llm)
            ?? throw ValidationException::withMessages(['call_id' => 'Провайдер оценки не включён.']);
        if ($version->criteria()->doesntExist()) {
            throw ValidationException::withMessages(['call_id' => 'В версии нет критериев.']);
        }

        try {
            $result = $analysis->preview($call, $transcript, $config, $version);
        } catch (ProviderException $e) {
            throw ValidationException::withMessages(['call_id' => 'Провайдер оценки: '.$e->getMessage()]);
        }

        return response()->json([
            'synthetic' => $transcript->is_synthetic,
            'score' => $result['score'],
            'summary' => $result['summary'],
            'criteria' => array_map(fn (array $c) => [
                'title' => $c['criterion']->title, 'status' => $c['status'], 'reason' => $c['reason'],
                'evidence_valid' => $c['evidence_valid'], 'issues' => $c['issues'],
            ], $result['criteria']),
        ]);
    }

    private function assertVisible(Request $request, Checklist $checklist): void
    {
        abort_unless($this->access->visible($request->user(), $checklist->company_id, Permission::ChecklistDesign, Permission::ChecklistPublish, Permission::WorkflowDesign), 404);
    }

    /** @return array<string, mixed> */
    private function summary(Checklist $c): array
    {
        $published = $c->versions->where('status', 'published')->sortByDesc('version')->first();

        return [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'description' => $c->description,
            'is_demo' => $c->is_demo, 'is_archived' => $c->is_archived,
            'company' => $c->company ? ['id' => $c->company->id, 'name' => $c->company->name] : null,
            'published_version' => $published?->version,
            'has_draft' => $c->versions->contains('status', 'draft'),
        ];
    }

    /** @return array<string, mixed> */
    private function version(ChecklistVersion $v): array
    {
        return [
            'id' => $v->id, 'version' => $v->version, 'status' => $v->status, 'zero_on_critical' => $v->zero_on_critical,
            'notes' => $v->notes, 'lock_version' => $v->lock_version, 'published_at' => $v->published_at?->toIso8601String(),
            'criteria' => $v->criteria()->get()->map(fn (ChecklistCriterion $c) => [
                'id' => $c->id, 'code' => $c->code, 'title' => $c->title, 'description' => $c->description, 'ai_hint' => $c->ai_hint,
                'weight' => (float) $c->weight, 'is_required' => $c->is_required, 'is_critical' => $c->is_critical, 'applicability' => $c->applicability,
            ])->values(),
        ];
    }
}
