<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Identity\Services\MfaService;
use App\Domain\Identity\Services\SessionService;
use App\Domain\Identity\Services\UserService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UserPasswordRequest;
use App\Http\Requests\Api\V1\UserStatusRequest;
use App\Http\Requests\Api\V1\UserStoreRequest;
use App\Http\Requests\Api\V1\UserUpdateRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserController extends Controller
{
    private const RELATIONS = ['employee', 'group', 'company', 'dealership', 'department', 'roleScopes.role', 'roleScopes.user.roleScopes'];

    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'company_id' => ['sometimes', 'integer'],
            'role_id' => ['sometimes', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $users = User::query()
            ->visibleTo($request->user(), Permission::UsersView)
            ->with(self::RELATIONS)
            ->when($filters['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('login', 'like', $this->like($s))
                ->orWhere('name', 'like', $this->like($s))
                ->orWhere('email', 'like', $this->like($s))))
            ->when($filters['company_id'] ?? null, fn ($q, $id) => $q->where('company_id', $id))
            ->when($filters['role_id'] ?? null, fn ($q, $id) => $q->whereHas('roleScopes', fn ($r) => $r->where('role_id', $id)))
            ->when(array_key_exists('is_active', $filters), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return UserResource::collection($users);
    }

    public function store(UserStoreRequest $request): JsonResponse
    {
        $user = $this->users->create($request->user(), $request->validated());

        return (new UserResource($user->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return new UserResource($user->load(self::RELATIONS));
    }

    public function update(UserUpdateRequest $request, User $user): UserResource
    {
        $this->authorize('update', $user);
        $this->users->update($request->user(), $user, $request->validated());

        return new UserResource($user->load(self::RELATIONS));
    }

    /** Включить или отключить учётную запись. При отключении все сессии завершаются. */
    public function status(UserStatusRequest $request, User $user): UserResource
    {
        $this->authorize('disable', $user);
        $this->users->setActive($request->user(), $user, $request->boolean('is_active'), $request->input('reason'));

        return new UserResource($user->load(self::RELATIONS));
    }

    /** Задать временный пароль. Пользователь будет обязан сменить его при входе. */
    public function password(UserPasswordRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $this->users->setTemporaryPassword($request->user(), $user, $request->string('password')->toString());

        return response()->json(['message' => 'Временный пароль установлен, сессии пользователя завершены.']);
    }

    /**
     * Сбросить двухфакторную аутентификацию пользователя (потерян телефон и резервные коды).
     * Себе сбросить нельзя; при следующем входе пользователь подключит MFA заново.
     */
    public function resetMfa(Request $request, User $user, MfaService $mfa): UserResource
    {
        $this->authorize('resetMfa', $user);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $mfa->reset($user, $data['reason']);

        return new UserResource($user->refresh()->load(self::RELATIONS));
    }

    /** Завершить все сессии пользователя. */
    public function terminateSessions(User $user, SessionService $sessions): JsonResponse
    {
        $this->authorize('update', $user);

        return response()->json(['terminated' => $sessions->terminateAll($user)]);
    }
}
