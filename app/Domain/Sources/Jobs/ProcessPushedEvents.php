<?php

namespace App\Domain\Sources\Jobs;

use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Services\SourceSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Импорт событий, принятых через webhook (payload уже сохранён зашифрованным; в очереди — только ID). */
class ProcessPushedEvents implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    /** @param list<int> $eventIds */
    public function __construct(public readonly int $connectionId, public readonly array $eventIds)
    {
        $this->onQueue('default');
    }

    public function handle(SourceSync $sync): void
    {
        $connection = SourceConnection::query()->find($this->connectionId);
        if ($connection !== null) {
            $sync->processPushed($connection, $this->eventIds);
        }
    }
}
