<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Services\SessionService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Собственные сессии пользователя. */
class SessionController extends Controller
{
    public function __construct(private readonly SessionService $sessions) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->sessions->listFor($request->user(), $request->session()->getId()),
        ]);
    }

    public function destroy(Request $request, string $session): Response
    {
        abort_unless($this->sessions->terminate($request->user(), $session), 404, 'Сессия не найдена.');

        return response()->noContent();
    }

    /** Завершить все сессии, кроме текущей. */
    public function destroyOthers(Request $request): JsonResponse
    {
        $count = $this->sessions->terminateAll($request->user(), $request->session()->getId());

        return response()->json(['terminated' => $count]);
    }
}
