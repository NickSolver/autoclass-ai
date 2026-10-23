<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Services\PasswordResetService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PasswordResetConfirmRequest;
use App\Http\Requests\Api\V1\PasswordResetRequestRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $resets) {}

    /**
     * Запросить ссылку для сброса пароля. Ответ одинаков независимо от того, найден ли пользователь.
     *
     * @unauthenticated
     */
    public function request(PasswordResetRequestRequest $request): JsonResponse
    {
        $this->resets->request($request->string('login')->toString());

        return response()->json([
            'message' => 'Если учётная запись существует и для неё указан e-mail, на него отправлена ссылка для смены пароля.',
        ], 202);
    }

    /**
     * Задать новый пароль по одноразовому токену из письма.
     *
     * @unauthenticated
     */
    public function confirm(PasswordResetConfirmRequest $request): Response
    {
        $this->resets->reset($request->string('token')->toString(), $request->string('password')->toString());

        return response()->noContent();
    }
}
