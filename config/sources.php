<?php

/*
| Подключаемые источники звонков (ТЗ, разд. 4.1; ADR-0008).
*/
return [
    // Порций за один сеанс синхронизации; остаток заберёт следующий сеанс.
    'max_batches_per_run' => (int) env('SOURCES_MAX_BATCHES_PER_RUN', 20),
    // Максимум событий в одном webhook-запросе.
    'max_webhook_events' => (int) env('SOURCES_MAX_WEBHOOK_EVENTS', 100),
];
