<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditLogger;
use App\Domain\CallIntake\Models\Call;
use App\Http\Controllers\Controller;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Прослушивание и скачивание записи — только через этот адрес с проверкой прав (без публичных ссылок).
 * Для локального диска поддерживается перемотка (HTTP Range).
 */
class CallAudioController extends Controller
{
    public function __invoke(Request $request, Call $call, AuditLogger $audit): BinaryFileResponse|StreamedResponse
    {
        $download = $request->boolean('download');
        $this->authorize($download ? 'download' : 'play', $call);

        $file = $call->original()->firstOrFail();
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404, 'Файл записи не найден в хранилище.');

        // Скачивание аудируется всегда; прослушивание — по настройке и только начало воспроизведения.
        $range = (string) $request->header('Range');
        if ($download) {
            $audit->record('call.audio_downloaded', $call);
        } elseif (config('calls.audit_playback') && ($range === '' || str_starts_with($range, 'bytes=0-'))) {
            $audit->record('call.audio_played', $call);
        }

        $name = "call-{$call->id}.{$file->format}";
        $disposition = $download ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE;

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return response()->file($disk->path($file->path), [
                'Content-Type' => $file->mime,
                'Cache-Control' => 'private, no-store',
            ])->setContentDisposition($disposition, $name);
        }

        // Удалённое хранилище: потоковая отдача без перемотки (ADR-0003).
        return $disk->response($file->path, $name, ['Content-Type' => $file->mime, 'Cache-Control' => 'private, no-store'], $disposition);
    }
}
