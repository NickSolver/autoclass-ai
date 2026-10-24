<?php

namespace App\Domain\Sources\Jobs;

use App\Domain\Identity\Models\User;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Services\SourceSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Синхронизация подключения в очереди. Повтор — следующим запуском по расписанию. */
class SyncSourceConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $connectionId, public readonly string $trigger, public readonly ?int $userId = null)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'source-sync:'.$this->connectionId;
    }

    public function handle(SourceSync $sync): void
    {
        $connection = SourceConnection::query()->find($this->connectionId);
        if ($connection === null || ($this->trigger === 'schedule' && ! $connection->is_enabled)) {
            return;
        }
        try {
            $sync->run($connection, $this->trigger, $this->userId ? User::query()->find($this->userId) : null);
        } catch (ConflictHttpException) {
            // Уже идёт другой сеанс — этот не нужен.
        }
    }
}
