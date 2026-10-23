<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Access\AccessResolver;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRoleScope;
use App\Domain\Identity\Permission;
use App\Domain\Identity\Services\MfaService;
use App\Domain\Identity\Services\UserService;
use App\Domain\Organization\Services\OrgPathResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ChangePasswordRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Вход по логину и паролю. Публичной регистрации нет.
 */
class AuthController extends Controller
{
    private const GENERIC_ERROR = 'Неверный логин или пароль.';

    public function __construct(
        private readonly AccessResolver $resolver,
        private readonly AuditLogger $audit,
        private readonly MfaService $mfa,
    ) {}

    /**
     * Вход. Ограничение попыток — на пару «логин + IP»; ответ не раскрывает, существует ли логин.
     *
     * @unauthenticated
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $login = mb_strtolower(trim($request->string('login')->toString()));
        $key = 'login:'.hash('sha256', $login.'|'.$request->ip());
        $max = (int) config('auth.login_max_attempts', 5);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'login' => "Слишком много попыток входа. Повторите через {$seconds} сек.",
            ])->status(429);
        }

        $user = User::query()->where('login', $login)->first();
        // Хеш проверяется и для несуществующего логина — время ответа не выдаёт наличие учётной записи.
        $passwordOk = Hash::check($request->string('password')->toString(), $user->password ?? self::dummyHash());

        if ($user === null || ! $passwordOk || ! $user->is_active) {
            RateLimiter::hit($key, 60);
            $this->audit->record('auth.login_failed', $user, [
                'reason' => $user === null ? 'unknown_login' : (! $passwordOk ? 'bad_password' : 'inactive'),
            ], actor: $user);
            throw ValidationException::withMessages(['login' => self::GENERIC_ERROR]);
        }

        RateLimiter::clear($key);
        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => $request->string('password')->toString()])->saveQuietly();
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        $mfa = $this->mfa->onLogin($user, $request);
        $this->audit->record('auth.login', $user, ['mfa' => $mfa], actor: $user);

        return response()->json($this->presentMe($user, $request));
    }

    public function logout(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->audit->record('auth.logout', $user);

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /** Текущий пользователь, его права и области действия. */
    public function me(Request $request): JsonResponse
    {
        return response()->json($this->presentMe($request->user(), $request));
    }

    public function changePassword(ChangePasswordRequest $request, UserService $users): Response
    {
        $users->changeOwnPassword(
            $request->user(),
            $request->string('current_password')->toString(),
            $request->string('password')->toString(),
            $request->session()->getId(),
        );
        $request->session()->regenerate();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function presentMe(User $user, Request $request): array
    {
        $user->load(['employee', 'group', 'company', 'dealership', 'department', 'roleScopes.role']);
        $paths = app(OrgPathResolver::class);

        return [
            'user' => [
                'id' => $user->id,
                'login' => $user->login,
                'name' => $user->name,
                'email' => $user->email,
                'must_change_password' => $user->must_change_password,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'employee' => $user->employee ? ['id' => $user->employee->id, 'full_name' => $user->employee->fullName()] : null,
                'home' => implode(' / ', array_filter([
                    $user->group->name, $user->company?->name, $user->dealership?->name, $user->department?->name,
                ])),
            ],
            // Для интерфейса: какие разделы показывать. Сервер всё равно проверяет каждый запрос.
            'permissions' => array_keys($this->resolver->scopesOf($user)),
            'role_assignments' => $user->roleScopes->map(fn (UserRoleScope $s) => [
                'role' => $s->role->name,
                'scope_type' => $s->scope_type->value,
                'scope_label' => $paths->labelFor($s->scope_type, $s->scope_id),
            ])->values(),
            'can' => [
                'manage_roles' => $user->can('create', Role::class),
                'view_roles' => $user->can('viewAny', Role::class),
            ],
            'mfa' => $this->mfa->summary($user, $request->session()),
            'available_permissions' => collect(Permission::cases())
                ->filter(fn (Permission $p) => $p->isModuleAvailable())
                ->map(fn (Permission $p) => $p->value)
                ->values(),
        ];
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('timing-equalizer-'.random_int(0, PHP_INT_MAX));
    }
}
