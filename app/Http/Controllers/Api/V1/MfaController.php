<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Services\MfaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/** Двухфакторная аутентификация текущего пользователя (ADR-0002). */
class MfaController extends Controller
{
    public function __construct(private readonly MfaService $mfa) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->mfa->summary($request->user(), $request->session()));
    }

    /** Начать подключение: QR-код и секрет для ручного ввода. Секрет сохраняется только после подтверждения. */
    public function setup(Request $request): JsonResponse
    {
        return response()->json($this->mfa->beginSetup($request->user(), $request->session()));
    }

    /** Подтвердить подключение кодом. Резервные коды возвращаются один раз. */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:16']]);
        $codes = $this->mfa->confirmSetup($request->user(), $request->session(), $data['code']);

        return response()->json(['recovery_codes' => $codes, 'mfa' => $this->mfa->summary($request->user(), $request->session())]);
    }

    /** Ввести код (при входе или для подтверждения критичного действия). */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:16'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
            'trust_device' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $this->mfa->verify($user, $request->session(), $data['code'] ?? null, $data['recovery_code'] ?? null);

        $response = response()->json(['mfa' => $this->mfa->summary($user, $request->session())]);
        if ($request->boolean('trust_device')) {
            $response->withCookie($this->mfa->trustDevice($user, $request));
        }

        return $response;
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        return response()->json(['recovery_codes' => $this->mfa->regenerateRecoveryCodes($request->user())]);
    }

    public function forgetDevices(Request $request): JsonResponse
    {
        $count = $this->mfa->forgetDevices($request->user());

        return response()->json(['forgotten' => $count])->withCookie(Cookie::forget(MfaService::TRUST_COOKIE));
    }

    public function disable(Request $request): JsonResponse
    {
        $this->mfa->disable($request->user());

        return response()->json(['mfa' => $this->mfa->summary($request->user(), $request->session())]);
    }
}
