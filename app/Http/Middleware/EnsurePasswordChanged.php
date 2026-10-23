<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пока временный пароль не сменён, доступны только маршруты профиля/выхода
 * (они не используют этот middleware).
 */
final class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->must_change_password) {
            return response()->json([
                'message' => 'Необходимо сменить временный пароль.',
                'code' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
