<?php

/*
 * Приём звонков и аудио (этап B, ADR-0003). Лимиты — рабочие значения до пилота (ТЗ, разд. 4.2, 17 п. 7):
 * окончательные выбираются по реальным файлам. Лимит размера должен быть согласован с
 * upload_max_filesize/post_max_size (Dockerfile) и client_max_body_size (docker/nginx.conf).
 */
return [
    // Приватный диск для записей. В эксплуатации — S3-совместимое хранилище (тогда без перемотки, см. ADR-0003).
    'disk' => env('CALLS_DISK', 'calls'),

    'max_file_size_mb' => (int) env('CALLS_MAX_FILE_SIZE_MB', 100),
    'min_duration_seconds' => (int) env('CALLS_MIN_DURATION_SECONDS', 1),
    'max_duration_minutes' => (int) env('CALLS_MAX_DURATION_MINUTES', 120),
    'max_files_per_batch' => (int) env('CALLS_MAX_FILES_PER_BATCH', 50),

    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),

    // Нормализованная копия для распознавания речи.
    'normalized' => ['sample_rate' => 16000, 'channels' => 1],

    // Аудит прослушивания (скачивание аудируется всегда). ТЗ, разд. 12: «по настройке».
    'audit_playback' => (bool) env('CALLS_AUDIT_PLAYBACK', false),
];
