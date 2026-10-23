<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\CallIntake\AttributionStatus;
use App\Domain\CallIntake\CallDirection;
use App\Domain\CallIntake\CallStatus;
use App\Domain\CallIntake\Models\Call;
use App\Domain\CallIntake\Services\CallActionsService;
use App\Domain\Identity\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CallUpdateRequest;
use App\Http\Resources\CallResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class CallController extends Controller
{
    private const LIST_RELATIONS = ['original', 'duplicateOf', 'primaryEvaluation'];

    private const DETAIL_RELATIONS = ['source', 'original', 'normalized', 'attempts', 'uploader', 'duplicateOf', 'primaryEvaluation'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Call::class);

        return CallResource::collection(
            $this->filtered($request)->with(self::LIST_RELATIONS)
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->paginate($this->perPage($request))
        );
    }

    /** Счётчики по статусам в области пользователя (с теми же фильтрами, кроме статуса). */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Call::class);
        $counts = $this->filtered($request, withStatus: false)
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $needsEmployee = $this->filtered($request, withStatus: false)
            ->where('attribution_status', AttributionStatus::NeedsEmployee)
            ->where('status', '!=', CallStatus::Excluded)
            ->count();

        $sum = fn (array $statuses) => (int) collect($statuses)->sum(fn (CallStatus $s) => $counts[$s->value] ?? 0);

        return response()->json([
            'total' => (int) $counts->sum(),
            'in_progress' => $sum(CallStatus::inProgress()),
            'awaiting_transcription' => $sum([CallStatus::AwaitingTranscription]),
            'completed' => $sum([CallStatus::Completed]),
            'failed' => $sum([CallStatus::Failed]),
            'excluded' => $sum([CallStatus::Excluded]),
            'needs_employee' => $needsEmployee,
            'review_required' => $this->filtered($request, withStatus: false)->where('review_required', true)->where('status', '!=', CallStatus::Excluded)->count(),
            'awaiting_evaluation' => $sum([CallStatus::AwaitingEvaluation]),
        ]);
    }

    public function show(Call $call): CallResource
    {
        return new CallResource($call->load(self::DETAIL_RELATIONS));
    }

    public function update(CallUpdateRequest $request, Call $call, CallActionsService $actions): CallResource
    {
        $this->authorize('update', $call);

        return new CallResource($actions->update($request->user(), $call, $request->validated())->load(self::DETAIL_RELATIONS));
    }

    public function reprocess(Request $request, Call $call, CallActionsService $actions): CallResource
    {
        $this->authorize('reprocess', $call);

        return new CallResource($actions->reprocess($request->user(), $call)->load(self::DETAIL_RELATIONS));
    }

    public function exclude(Request $request, Call $call, CallActionsService $actions): CallResource
    {
        $this->authorize('exclude', $call);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return new CallResource($actions->exclude($request->user(), $call, $data['reason'])->load(self::DETAIL_RELATIONS));
    }

    /** @return Builder<Call> */
    private function filtered(Request $request, bool $withStatus = true): Builder
    {
        $f = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::in([...array_column(CallStatus::cases(), 'value'), 'in_progress'])],
            'direction' => ['sometimes', 'nullable', Rule::enum(CallDirection::class)],
            'company_id' => ['sometimes', 'nullable', 'integer'],
            'dealership_id' => ['sometimes', 'nullable', 'integer'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'employee_id' => ['sometimes', 'nullable', 'integer'],
            'needs_employee' => ['sometimes', 'boolean'],
            'review_required' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
        ]);

        return Call::query()
            ->visibleTo($request->user(), Permission::CallsView)
            ->when($f['q'] ?? null, fn (Builder $q, string $s) => $q->where(fn (Builder $w) => $w
                ->when(ctype_digit($s), fn (Builder $x) => $x->orWhere('calls.id', (int) $s))
                ->orWhere('note', 'like', $this->like($s))
                ->orWhereHas('original', fn (Builder $a) => $a->where('original_name', 'like', $this->like($s)))))
            ->when($withStatus && ($f['status'] ?? null), fn (Builder $q) => $f['status'] === 'in_progress'
                ? $q->whereIn('status', CallStatus::inProgress())
                : $q->where('status', $f['status']))
            ->when($f['direction'] ?? null, fn (Builder $q, string $v) => $q->where('direction', $v))
            ->when($f['company_id'] ?? null, fn (Builder $q, int $v) => $q->where('company_id', $v))
            ->when($f['dealership_id'] ?? null, fn (Builder $q, int $v) => $q->where('dealership_id', $v))
            ->when($f['department_id'] ?? null, fn (Builder $q, int $v) => $q->where('department_id', $v))
            ->when($f['employee_id'] ?? null, fn (Builder $q, int $v) => $q->where('employee_id', $v))
            ->when($request->boolean('needs_employee'), fn (Builder $q) => $q->where('attribution_status', AttributionStatus::NeedsEmployee))
            ->when($request->boolean('review_required'), fn (Builder $q) => $q->where('review_required', true))
            ->when($f['from'] ?? null, fn (Builder $q, string $v) => $q->where('occurred_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($f['to'] ?? null, fn (Builder $q, string $v) => $q->where('occurred_at', '<=', strlen($v) === 10 ? Carbon::parse($v)->endOfDay() : Carbon::parse($v)));
    }
}
