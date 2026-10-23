<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Этап C: провайдеры ИИ, распознавание, чек-листы (модель данных; конструктор — этап D), оценка.
 * ТЗ, разд. 5, 7, 8; ADR-0004.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Глобальные настройки системы (например, разрешение передачи записей внешним провайдерам).
        Schema::create('system_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->json('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('provider_configs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 8); // asr | llm
            $table->string('driver', 32);
            $table->string('name');
            $table->string('model', 100)->nullable();
            $table->string('endpoint', 500)->nullable();
            // Таймауты, лимиты, тарифы, режим заглушки. Без секретов.
            $table->json('settings');
            // Ключ API — только в зашифрованном виде, наружу не отдаётся (ТЗ, разд. 2, 8).
            $table->text('secret')->nullable();
            $table->timestamp('secret_set_at')->nullable();
            $table->string('role', 16)->default('inactive'); // primary | fallback | inactive
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['kind', 'role']);
        });

        // Журнал вызовов провайдеров: безопасные метаданные, без содержимого запросов (ТЗ, разд. 8).
        Schema::create('ai_provider_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_config_id')->nullable()->constrained('provider_configs')->nullOnDelete();
            $table->foreignId('call_id')->nullable()->constrained('calls')->nullOnDelete();
            $table->string('kind', 8);
            $table->string('driver', 32);
            $table->string('model', 100)->nullable();
            $table->string('operation', 16); // transcribe | evaluate | test
            $table->string('prompt_version', 32)->nullable();
            $table->char('input_hash', 64)->nullable();
            $table->timestamp('started_at', 3);
            $table->unsignedInteger('duration_ms');
            $table->string('status', 8); // ok | error
            $table->string('error_code', 32)->nullable();
            $table->string('error_message', 300)->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->decimal('audio_seconds', 10, 2)->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->decimal('cost', 12, 4)->nullable();
            $table->char('currency', 3)->default('RUB');

            $table->index(['provider_config_id', 'started_at']);
            $table->index(['call_id', 'started_at']);
            $table->index('started_at');
        });

        Schema::create('transcription_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->foreignId('provider_config_id')->nullable()->constrained('provider_configs')->nullOnDelete();
            $table->string('driver', 32);
            $table->string('status', 16);
            $table->string('language', 16)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('created_at');
        });

        // Версии расшифровки неизменяемы; правка человеком (этап E) создаёт новую версию.
        Schema::create('transcript_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->foreignId('transcription_run_id')->nullable()->constrained('transcription_runs')->nullOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('source', 16); // asr | human
            // Синтетическая расшифровка тестового провайдера — не распознанная речь.
            $table->boolean('is_synthetic')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at');

            $table->unique(['call_id', 'version']);
        });

        Schema::create('transcript_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transcript_version_id')->constrained('transcript_versions')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->unsignedInteger('start_ms');
            $table->unsignedInteger('end_ms');
            $table->string('speaker', 16);
            // Роль говорящего — гипотеза (ТЗ, разд. 5 п. 6), не верификация личности.
            $table->string('speaker_role', 16)->default('unknown'); // agent | client | unknown
            $table->text('text');
            $table->decimal('confidence', 4, 3)->nullable();

            $table->index(['transcript_version_id', 'position']);
        });

        Schema::create('checklists', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // DEMO — тестовые критерии и веса, не корпоративный стандарт (ТЗ, разд. 7.3).
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
        });

        Schema::create('checklist_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_id')->constrained('checklists')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('status', 16); // draft | published | archived
            $table->string('scoring_rule', 16)->default('v1');
            // «Оценка обнуляется при критическом нарушении» — выключено по умолчанию (ТЗ, разд. 7.1).
            $table->boolean('zero_on_critical')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['checklist_id', 'version']);
        });

        Schema::create('checklist_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_version_id')->constrained('checklist_versions')->cascadeOnDelete();
            $table->string('code', 64);
            $table->unsignedSmallInteger('position');
            $table->string('title');
            $table->text('description')->nullable();
            $table->text('ai_hint')->nullable();
            $table->decimal('weight', 6, 2);
            $table->boolean('is_required')->default(true);
            $table->boolean('is_critical')->default(false);
            $table->string('type', 16)->default('binary');
            $table->text('applicability')->nullable();

            $table->unique(['checklist_version_id', 'code']);
        });

        Schema::create('analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->foreignId('transcript_version_id')->constrained('transcript_versions')->restrictOnDelete();
            $table->foreignId('provider_config_id')->nullable()->constrained('provider_configs')->nullOnDelete();
            $table->string('driver', 32);
            $table->string('status', 16); // running | succeeded | failed
            $table->string('prompt_version', 32);
            // Какие чек-листы выбраны и почему (до конструктора процессов — правило из config/analysis.php).
            $table->json('routing');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('created_at');

            $table->index(['call_id', 'id']);
        });

        Schema::create('evaluation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_run_id')->constrained('analysis_runs')->cascadeOnDelete();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->foreignId('checklist_version_id')->constrained('checklist_versions')->restrictOnDelete();
            $table->string('role', 16); // primary | additional
            // Итоговая оценка 0–100 — только при «Полной оценке»; иначе null (ТЗ, разд. 7.1).
            $table->unsignedTinyInteger('score')->nullable();
            // Предварительная оценка по разрешённым критериям — для проверяющего, не для рейтингов.
            $table->unsignedTinyInteger('provisional_score')->nullable();
            $table->boolean('critical_violation')->default(false);
            $table->string('business_status', 32); // full_assessment | human_intervention_required
            $table->string('review_state', 16); // not_required | pending | in_progress | resolved
            $table->string('approval_state', 16); // unreviewed | human_confirmed | human_overridden
            $table->text('summary')->nullable();
            $table->json('recommendations');
            $table->json('risks');
            $table->json('issues');
            $table->timestamp('created_at');

            $table->index(['call_id', 'role']);
            $table->index(['business_status', 'review_state']);
        });

        Schema::create('criterion_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluation_result_id')->constrained('evaluation_results')->cascadeOnDelete();
            $table->foreignId('checklist_criterion_id')->constrained('checklist_criteria')->restrictOnDelete();
            $table->string('status', 24); // pass | fail | not_applicable | insufficient_data
            $table->text('reason')->nullable();
            // Внутренняя эвристика модели, не статистическая гарантия (ТЗ, разд. 8).
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('evidence_valid')->default(true);
            $table->json('issues');
        });

        Schema::create('evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('criterion_result_id')->constrained('criterion_results')->cascadeOnDelete();
            $table->string('type', 24); // quote | time_range | source_metadata | manual_note
            $table->foreignId('transcript_segment_id')->nullable()->constrained('transcript_segments')->nullOnDelete();
            $table->text('quote')->nullable();
            $table->unsignedInteger('start_ms')->nullable();
            $table->unsignedInteger('end_ms')->nullable();
            $table->string('speaker', 16)->nullable();
            $table->boolean('is_valid');
            $table->string('issue', 300)->nullable();
        });
    }

    public function down(): void
    {
        foreach (['evidence', 'criterion_results', 'evaluation_results', 'analysis_runs', 'checklist_criteria',
            'checklist_versions', 'checklists', 'transcript_segments', 'transcript_versions', 'transcription_runs',
            'ai_provider_calls', 'provider_configs', 'system_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
