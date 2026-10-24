<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\CallIntake\Models\Call;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Review\Models\ManualReview;
use App\Domain\Review\Services\ReviewService;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReviewPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Очередь ручных проверок (ТЗ, разд. 11). */
class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviews) {}

    /**
     * Видимые проверки: в области права решать или поручать.
     *
     * @return Builder<ManualReview>
     */
    public static function visible(User $user): Builder
    {
        return ManualReview::query()->where(fn (Builder $q) => $q
            ->whereIn('id', ManualReview::query()->visibleTo($user, Permission::ReviewResolve)->select('id'))
            ->orWhereIn('id', ManualReview::query()->visibleTo($user, Permission::ReviewAssign)->select('id')));
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(Permission::ReviewResolve) || $user->hasPermission(Permission::ReviewAssign), 403, 'Нет доступа к проверкам.');
        $data = $request->validate([
            'status' => ['nullable', 'in:open,pending,in_progress,resolved,cancelled,all'],
            'assignee' => ['nullable', 'in:me,unassigned'],
            'company_id' => ['nullable', 'integer'],
            'source' => ['nullable', 'in:ai,workflow,manual,appeal'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $status = $data['status'] ?? 'open';

        $page = self::visible($user)
            ->with(['assignee:id,name', 'resolver:id,name', 'call'])
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ManualReview::OPEN))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when(($data['assignee'] ?? null) === 'me', fn ($q) => $q->where('assignee_id', $user->id))
            ->when(($data['assignee'] ?? null) === 'unassigned', fn ($q) => $q->whereNull('assignee_id'))
            ->when($data['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($data['source'] ?? null, fn ($q, $s) => $q->where('source', $s))
            ->orderByRaw("case status when 'in_progress' then 0 when 'pending' then 1 else 2 end")
            ->orderBy('created_at')
            ->paginate($data['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (ManualReview $r) => [
                ...ReviewPresenter::review($r, $user),
                'call' => [
                    'id' => $r->call->id, 'direction' => $r->call->direction->value, 'occurred_at' => $r->call->occurred_at->toIso8601String(),
                    'org' => [
                        'company' => $r->call->org_snapshot['company']['name'] ?? null,
                        'dealership' => $r->call->org_snapshot['dealership']['name'] ?? null,
                        'department' => $r->call->org_snapshot['department']['name'] ?? null,
                        'employee' => $r->call->org_snapshot['employee']['full_name'] ?? null,
                    ],
                ],
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
            'counts' => [
                'pending' => self::visible($user)->where('status', 'pending')->count(),
                'in_progress' => self::visible($user)->where('status', 'in_progress')->count(),
                'mine' => self::visible($user)->whereIn('status', ManualReview::OPEN)->where('assignee_id', $user->id)->count(),
            ],
        ]);
    }

    public function store(Request $request, Call $call): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $review = $this->reviews->request($request->user(), $call, $data['reason']);

        return response()->json(ReviewPresenter::review($review->load(['assignee', 'resolver']), $request->user()), 201);
    }

    public function assignees(Request $request, ManualReview $review): JsonResponse
    {
        abort_unless($review->isVisibleTo($request->user(), Permission::ReviewAssign), 403, 'Нет права поручать проверки.');

        return response()->json(['data' => $this->reviews->assignees($review->orgPath())->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()]);
    }

    public function assign(Request $request, ManualReview $review): JsonResponse
    {
        $data = $request->validate(['assignee_id' => ['required', 'integer', 'exists:users,id'], 'lock_version' => ['required', 'integer']]);
        $review = $this->reviews->assign($request->user(), $review, User::query()->findOrFail($data['assignee_id']), $data['lock_version']);

        return response()->json(ReviewPresenter::review($review->load(['assignee', 'resolver']), $request->user()));
    }

    public function take(Request $request, ManualReview $review): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer']]);

        return response()->json(ReviewPresenter::review($this->reviews->take($request->user(), $review, $data['lock_version'])->load(['assignee', 'resolver']), $request->user()));
    }

    public function resolve(Request $request, ManualReview $review): JsonResponse
    {
        $data = $request->validate([
            'lock_version' => ['required', 'integer'],
            'comment' => ['required', 'string', 'min:3', 'max:2000'],
            'decisions' => ['present', 'array', 'max:20'],
            'decisions.*.result_id' => ['required', 'integer'],
            'decisions.*.decision' => ['required', 'in:confirmed,corrected'],
            'decisions.*.changes' => ['sometimes', 'array', 'max:100'],
            'decisions.*.changes.*.criterion_result_id' => ['required', 'integer'],
            'decisions.*.changes.*.status' => ['required', 'in:pass,fail,not_applicable,insufficient_data'],
            'decisions.*.changes.*.comment' => ['required', 'string', 'max:1000'],
        ]);
        $review = $this->reviews->resolve($request->user(), $review, $data['decisions'], $data['comment'], $data['lock_version']);

        return response()->json(ReviewPresenter::review($review->load(['assignee', 'resolver']), $request->user()));
    }

    public function cancel(Request $request, ManualReview $review): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000'], 'lock_version' => ['required', 'integer']]);
        $review = $this->reviews->cancel($request->user(), $review, $data['reason'], $data['lock_version']);

        return response()->json(ReviewPresenter::review($review->load(['assignee', 'resolver']), $request->user()));
    }
}
