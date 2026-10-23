<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\CallIntake\Exceptions\DuplicateAudio;
use App\Domain\CallIntake\Models\Call;
use App\Domain\CallIntake\Services\CallUploadService;
use App\Domain\Identity\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CallUploadRequest;
use App\Http\Resources\CallResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Ручная загрузка: один файл на запрос. Пакетная загрузка — последовательность запросов
 * из интерфейса, каждый со своим ключом идемпотентности (заголовок Idempotency-Key).
 */
class CallUploadController extends Controller
{
    /** Ограничения загрузки — для интерфейса (значения из config/calls.php). */
    public function settings(): JsonResponse
    {
        $this->authorize('upload', Call::class);

        return response()->json([
            'formats' => ['mp3', 'wav', 'm4a'],
            'max_file_size_mb' => (int) config('calls.max_file_size_mb'),
            'max_files_per_batch' => (int) config('calls.max_files_per_batch'),
            'min_duration_seconds' => (int) config('calls.min_duration_seconds'),
            'max_duration_minutes' => (int) config('calls.max_duration_minutes'),
        ]);
    }

    public function store(CallUploadRequest $request, CallUploadService $uploads): JsonResponse
    {
        $this->authorize('upload', Call::class);

        $key = (string) $request->header('Idempotency-Key');
        if (! preg_match('/^[A-Za-z0-9_-]{16,100}$/', $key)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Нужен заголовок Idempotency-Key (16–100 символов: латиница, цифры, «-», «_»).']);
        }

        try {
            $result = $uploads->upload($request->user(), $request->file('file'), $request->validated(), $key, $request->boolean('confirm_duplicate'));
        } catch (DuplicateAudio $e) {
            $visible = $e->existing->isVisibleTo($request->user(), Permission::CallsView);

            return response()->json([
                'message' => $visible
                    ? "Такой файл уже загружен (звонок #{$e->existing->id}). Загрузить ещё раз?"
                    : 'Такой файл уже загружался в подразделении вне вашей области. Загрузить ещё раз?',
                'code' => 'duplicate_audio',
                'duplicate' => [
                    'id' => $visible ? $e->existing->id : null,
                    'uploaded_at' => $visible ? $e->existing->created_at?->toIso8601String() : null,
                ],
            ], 409);
        }

        $call = $result['call']->load(['source', 'original', 'normalized', 'attempts', 'uploader', 'duplicateOf']);

        return (new CallResource($call))->response()->setStatusCode($result['created'] ? 201 : 200);
    }
}
