<?php

namespace App\Domain\Sources\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Models\SourceEvent;
use App\Domain\Sources\Models\SourceSyncRun;
use App\Domain\Sources\SourceException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Синхронизация подключения (pull): порции по курсору, импорт каждого звонка, курсор сохраняется после
 * каждой порции — повтор после сбоя продолжает с места остановки, а дубли отсекает идемпотентность.
 */
final class SourceSync
{
    public function __construct(
        private readonly SourceDriverRegistry $drivers,
        private readonly SourceImporter $importer,
    ) {}

    public function run(SourceConnection $connection, string $trigger, ?User $actor = null): SourceSyncRun
    {
        $lock = Cache::lock("source-sync:{$connection->id}", 900);
        if (! $lock->get()) {
            throw new ConflictHttpException('Синхронизация этого подключения уже идёт.');
        }

        $run = SourceSyncRun::create([
            'source_connection_id' => $connection->id, 'trigger' => $trigger, 'status' => 'running',
            'cursor_from' => $connection->cursor, 'triggered_by' => $actor?->id, 'started_at' => now(),
        ]);
        $error = null;
        try {
            $driver = $this->drivers->driver($connection->source->code);
            if (! $driver->getCapabilities()['pull']) {
                throw new SourceException(SourceException::NOT_SUPPORTED, 'Источник не поддерживает выгрузку по запросу — только приём событий.');
            }
            $cursor = $connection->cursor;
            for ($i = 0; $i < (int) config('sources.max_batches_per_run', 20); $i++) {
                $batch = $driver->fetchBatch($connection, $cursor);
                foreach ($batch->payloads as $payload) {
                    $this->count($run, $this->importer->import($connection, $payload, 'pull', $run->id));
                }
                $cursor = $batch->nextCursor;
                $connection->forceFill(['cursor' => $cursor])->save();
                if (! $batch->hasMore) {
                    break;
                }
            }
        } catch (SourceException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            report($e);
            $error = 'Внутренняя ошибка синхронизации: '.Str::limit($e->getMessage(), 200);
        } finally {
            $lock->release();
        }

        $run->refresh();
        $status = $error !== null ? 'failed' : ($run->failed > 0 ? 'partial' : 'succeeded');
        $run->update(['status' => $status, 'cursor_to' => $connection->cursor, 'error_message' => $error ? Str::limit($error, 490) : null, 'finished_at' => now()]);
        $connection->forceFill([
            'last_sync_at' => now(),
            'last_success_at' => $error === null ? now() : $connection->last_success_at,
            'last_error' => $error ? Str::limit($error, 490) : null,
        ])->save();

        return $run->refresh();
    }

    /** Обработка событий, принятых через webhook, в рамках сеанса «webhook». */
    public function processPushed(SourceConnection $connection, array $eventIds): SourceSyncRun
    {
        $run = SourceSyncRun::create([
            'source_connection_id' => $connection->id, 'trigger' => 'webhook', 'status' => 'running', 'started_at' => now(),
        ]);
        SourceEvent::query()->whereIn('id', $eventIds)->where('source_connection_id', $connection->id)->where('status', 'pending')->get()
            ->each(function (SourceEvent $event) use ($connection, $run) {
                $event->update(['source_sync_run_id' => $run->id]);
                $this->count($run, $this->importer->process($connection, $event));
            });
        $run->refresh();
        $run->update(['status' => $run->failed > 0 ? 'partial' : 'succeeded', 'finished_at' => now()]);
        $connection->forceFill(['last_sync_at' => now()])->save();

        return $run->refresh();
    }

    private function count(SourceSyncRun $run, SourceEvent $event): void
    {
        $column = match ($event->status) {
            'imported' => 'created',
            'duplicate' => 'duplicates',
            'without_recording' => 'without_recording',
            default => 'failed',
        };
        $run->increment('fetched');
        $run->increment($column);
        if ($event->status === 'without_recording') {
            $run->increment('created');
        }
    }
}
