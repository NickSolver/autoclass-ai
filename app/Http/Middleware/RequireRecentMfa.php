<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MfaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Критичные действия требуют кода, введённого недавно (auth.mfa.step_up_minutes). */
final class RequireRecentMfa
{
    public function __construct(private readonly MfaService $mfa) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && ! $this->mfa->isRecentlyConfirmed($user, $request->session())) {
            return response()->json([
                'message' => 'Подтвердите действие кодом двухфакторной аутентификации.',
                'code' => 'mfa_confirmation_required',
            ], 403);
        }

        return $next($request);
    }
}
