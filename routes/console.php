<?php

use App\Domain\Sources\Jobs\SyncSourceConnection;
use App\Domain\Sources\Models\SourceConnection;
use App\Domain\Sources\Services\SourceDriverRegistry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Синхронизация подключённых источников звонков: подключения, у которых подошёл интервал.
Artisan::command('sources:sync-due', function () {
    $due = SourceConnection::query()->where('is_enabled', true)->get()
        ->filter(fn ($c) => $c->last_sync_at === null || $c->last_sync_at->lte(now()->subMinutes($c->sync_interval_minutes)))
        // Только источники с выгрузкой по запросу; push-only получают события сами.
        ->filter(fn ($c) => rescue(fn () => app(SourceDriverRegistry::class)->driver($c->source->code)->getCapabilities()['pull'], false, false));
    foreach ($due as $connection) {
        SyncSourceConnection::dispatch($connection->id, 'schedule');
    }
    $this->info('Поставлено в очередь: '.$due->count());
})->purpose('Синхронизировать источники звонков по расписанию');

Schedule::command('sources:sync-due')->everyMinute()->withoutOverlapping();
