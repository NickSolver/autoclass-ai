<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Этап B: приём звонков (ручная загрузка) и аудио. ТЗ, разд. 4.1, 4.2, 5; ADR-0003.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Источники звонков: ручная загрузка — ядро; Calltouch и «Лягушка» — отдельные модули (этап F).
        Schema::create('call_sources', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
        DB::table('call_sources')->insert([
            'code' => 'manual', 'name' => 'Ручная загрузка', 'is_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::create('calls', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('source_id')->constrained('call_sources')->restrictOnDelete();
            // Идемпотентность: source + external_id. Для ручной загрузки — ключ идемпотентности запроса.
            $table->string('external_id', 191);
            $table->string('direction', 16)->default('unknown');
            $table->timestamp('started_at')->nullable();
            $table->string('started_at_tz', 64)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            // Путь в оргструктуре на момент приёма (ADR-0001) и снимок названий (ТЗ, разд. 3).
            $table->foreignId('group_id')->constrained('company_groups')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('dealership_id')->nullable()->constrained('dealerships')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('attribution_status', 24);
            $table->json('org_snapshot');
            $table->text('note')->nullable();

            // Технический статус конвейера (не бизнес-статус оценки).
            $table->string('status', 32);
            $table->timestamp('status_changed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->string('excluded_reason', 500)->nullable();
            $table->timestamp('excluded_at')->nullable();
            $table->foreignId('excluded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('duplicate_of_call_id')->nullable()->constrained('calls')->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Дата звонка, если известна, иначе дата загрузки — для сортировки и фильтра по периоду.
            $table->timestamp('occurred_at')->storedAs('COALESCE(`started_at`, `created_at`)');

            $table->unique(['source_id', 'external_id']);
            $table->index(['company_id', 'occurred_at']);
            $table->index(['dealership_id', 'occurred_at']);
            $table->index(['department_id', 'occurred_at']);
            $table->index(['employee_id', 'occurred_at']);
            $table->index(['status', 'occurred_at']);
            $table->index('attribution_status');
            $table->index('occurred_at');
        });

        Schema::create('audio_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            // original — файл как загружен (неизменяем); normalized — копия для распознавания (моно, 16 кГц, PCM).
            $table->string('kind', 16);
            $table->string('disk', 32);
            $table->string('path', 500);
            $table->string('original_name')->nullable();
            $table->string('format', 16);
            $table->string('mime', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->index();
            $table->unsignedInteger('duration_ms');
            $table->string('codec', 32)->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedTinyInteger('channels')->nullable();
            $table->unsignedInteger('bit_rate')->nullable();
            $table->timestamps();

            $table->unique(['call_id', 'kind']);
        });

        // История попыток обработки: шаг, номер попытки, время, ошибка (ТЗ, разд. 5).
        Schema::create('call_processing_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->string('step', 32);
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 16);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['call_id', 'step', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_processing_attempts');
        Schema::dropIfExists('audio_files');
        Schema::dropIfExists('calls');
        Schema::dropIfExists('call_sources');
    }
};
