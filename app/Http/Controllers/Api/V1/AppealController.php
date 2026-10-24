<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\CallIntake\Models\Call;
use App\Domain\Evaluation\Models\EvaluationResult;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Review\Models\Appeal;
use App\Domain\Review\Services\AppealService;
use App\Domain\Review\Services\ReviewService;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ReviewPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Апелляции на оценки (ТЗ, разд. 11). */
class AppealController extends Controller
{
    private const RELATIONS = ['author:id,name', 'assignee:id,name', 'resolver:id,name', 'result.checklistVersion.checklist'];

    public function __construct(private readonly AppealService $appeals) {}

    /**
     * Видимые апелляции: свои (own), по области права подавать, решать или поручать.
     *
     * @return Builder<Appeal>
     */
    public static function visible(User $user): Builder
    {
        return Appeal::query()->where(fn (Builder $q) => $q
            ->whereIn('id', Appeal::query()->visibleTo($user, Permission::AppealCreate)->select('id'))
            ->orWhereIn('id', Appeal::query()->visibleTo($user, Permission::AppealResolve)->select('id'))
            ->orWhereIn('id', Appeal::query()->visibleTo($user, Permission::ReviewAssign)->select('id')));
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->hasPermission(Permission::AppealCreate) || $user->hasPermission(Permission::AppealResolve) || $user->hasPermission(Permission::ReviewAssign),
            403, 'Нет доступа к апелляциям.',
        );
        $data = $request->validate([
            'status' => ['nullable', 'in:open,in_review,accepted,rejected,withdrawn,active,all'],
            'mine' => ['nullable', 'in:author,assignee'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $status = $data['status'] ?? 'active';

        $page = self::visible($user)->with(self::RELATIONS)
            ->when($status === 'active', fn ($q) => $q->whereIn('status', Appeal::OPEN))
            ->when(! in_array($status, ['active', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when(($data['mine'] ?? null) === 'author', fn ($q) => $q->where('created_by', $user->id))
            ->when(($data['mine'] ?? null) === 'assignee', fn ($q) => $q->where('assignee_id', $user->id))
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Appeal $a) => ReviewPresenter::appeal($a, $user))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'from' => $page->firstItem(), 'to' => $page->lastItem()],
        ]);
    }

    public function store(Request $request, Call $call): JsonResponse
    {
        $data = $request->validate(['result_id' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:10', 'max:2000']]);
        $result = EvaluationResult::query()->where('call_id', $call->id)->findOrFail($data['result_id']);
        $appeal = $this->appeals->create($request->user(), $call, $result, $data['reason']);

        return response()->json(ReviewPresenter::appeal($appeal->load(self::RELATIONS), $request->user()), 201);
    }

    public function assignees(Request $request, Appeal $appeal, ReviewService $reviews): JsonResponse
    {
        abort_unless($appeal->isVisibleTo($request->user(), Permission::ReviewAssign), 403, 'Нет права поручать апелляции.');
        $users = $reviews->assignees($appeal->orgPath(), Permission::AppealResolve)
            ->filter(fn (User $u) => $this->appeals->conflict($u, $appeal) === null);

        return response()->json(['data' => $users->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()]);
    }

    public function assign(Request $request, Appeal $appeal): JsonResponse
    {
        $data = $request->validate(['assignee_id' => ['required', 'integer', 'exists:users,id'], 'lock_version' => ['required', 'integer']]);
        $appeal = $this->appeals->assign($request->user(), $appeal, User::query()->findOrFail($data['assignee_id']), $data['lock_version']);

        return response()->json(ReviewPresenter::appeal($appeal->load(self::RELATIONS), $request->user()));
    }

    public function take(Request $request, Appeal $appeal): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer']]);

        return response()->json(ReviewPresenter::appeal($this->appeals->take($request->user(), $appeal, $data['lock_version'])->load(self::RELATIONS), $request->user()));
    }

    public function withdraw(Request $request, Appeal $appeal): JsonResponse
    {
        $data = $request->validate(['lock_version' => ['required', 'integer']]);

        return response()->json(ReviewPresenter::appeal($this->appeals->withdraw($request->user(), $appeal, $data['lock_version'])->load(self::RELATIONS), $request->user()));
    }

    public function resolve(Request $request, Appeal $appeal): JsonResponse
    {
        $data = $request->validate([
            'lock_version' => ['required', 'integer'],
            'decision' => ['required', 'in:accepted,rejected'],
            'comment' => ['required', 'string', 'min:3', 'max:2000'],
            'changes' => ['required_if:decision,accepted', 'array', 'max:100'],
            'changes.*.criterion_result_id' => ['required', 'integer'],
            'changes.*.status' => ['required', 'in:pass,fail,not_applicable,insufficient_data'],
            'changes.*.comment' => ['required', 'string', 'max:1000'],
        ]);
        $appeal = $this->appeals->resolve($request->user(), $appeal, $data['decision'], $data['changes'] ?? [], $data['comment'], $data['lock_version']);

        return response()->json(ReviewPresenter::appeal($appeal->load(self::RELATIONS), $request->user()));
    }
}
