<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MfaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Без пройденного второго фактора (или после окончания льготного периода — без подключённой MFA)
 * доступны только маршруты входа/подключения MFA (они не используют этот middleware).
 */
final class EnsureMfaPassed
{
    public function __construct(private readonly MfaService $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            $status = $this->mfa->status($user, $request->session());
            if ($status === MfaService::STATUS_VERIFY_REQUIRED) {
                return response()->json(['message' => 'Введите код двухфакторной аутентификации.', 'code' => 'mfa_verification_required'], 403);
            }
            if ($status === MfaService::STATUS_SETUP_REQUIRED) {
                return response()->json(['message' => 'Подключите двухфакторную аутентификацию.', 'code' => 'mfa_setup_required'], 403);
            }
        }

        return $next($request);
    }
}
