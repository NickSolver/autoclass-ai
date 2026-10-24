<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Звонки, оценённые до этапа E: действующий анализ — последний успешный.
 * Отдельная миграция данных; откат ничего не удаляет.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE calls c
            JOIN (SELECT call_id, MAX(id) AS run_id FROM analysis_runs WHERE status = 'succeeded' GROUP BY call_id) r ON r.call_id = c.id
            SET c.current_analysis_run_id = r.run_id
            WHERE c.current_analysis_run_id IS NULL
            SQL);
    }

    public function down(): void {}
};
