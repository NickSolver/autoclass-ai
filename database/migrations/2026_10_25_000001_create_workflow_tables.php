<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Этап D: конструкторы чек-листов и бизнес-процессов (ТЗ, разд. 6, 7; ADR-0006).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Чек-лист: общий (company_id = NULL) или компании. Черновик версии — с оптимистической блокировкой.
        Schema::table('checklists', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->restrictOnDelete();
            $table->boolean('is_archived')->default(false)->after('is_demo');
            $table->foreignId('created_by')->nullable()->after('is_archived')->constrained('users')->nullOnDelete();
        });
        Schema::table('checklist_versions', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('zero_on_critical');
            $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1)->after('created_by');
        });

        Schema::create('workflow_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            // Область: NULL — вся группа; иначе — компания. Более узкая область важнее.
            $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('trigger', 32);
            // Среди процессов одной области выполняется первый по приоритету (меньше — раньше).
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_archived')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['trigger', 'is_archived', 'company_id']);
        });

        // Версия процесса: граф узлов и связей (JSON). Опубликованная версия неизменяема.
        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_definition_id')->constrained('workflow_definitions')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('status', 16); // draft | published | archived
            $table->json('graph');
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->unique(['workflow_definition_id', 'version']);
        });

        // Экземпляр выполнения: закреплён за версией процесса на старте (ТЗ, разд. 6.2).
        Schema::create('workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            // NULL — сработало правило по умолчанию (нет опубликованного процесса).
            $table->foreignId('workflow_version_id')->nullable()->constrained('workflow_versions')->restrictOnDelete();
            $table->string('trigger', 32);
            $table->string('status', 24); // running | waiting_analysis | completed | failed
            $table->json('context');
            $table->json('plan');
            $table->string('current_node_id', 64)->nullable();
            $table->foreignId('transcript_version_id')->nullable()->constrained('transcript_versions')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('created_at');

            $table->index(['call_id', 'id']);
        });

        Schema::create('workflow_node_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_run_id')->constrained('workflow_runs')->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->string('node_type', 32);
            $table->string('branch', 64)->nullable();
            $table->json('output')->nullable();
            $table->string('status', 16); // succeeded | failed
            $table->string('error_message', 500)->nullable();
            $table->timestamp('created_at', 3);
        });

        // Действия процесса над звонком: метки, приоритет, задача на проверку.
        Schema::table('calls', function (Blueprint $table) {
            $table->json('labels')->nullable()->after('note');
            $table->string('priority', 16)->default('normal')->after('labels');
            $table->string('review_reason', 500)->nullable()->after('review_required');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropColumn(['labels', 'priority', 'review_reason']);
        });
        Schema::dropIfExists('workflow_node_runs');
        Schema::dropIfExists('workflow_runs');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflow_definitions');
        Schema::table('checklist_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['notes', 'lock_version']);
        });
        Schema::table('checklists', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('is_archived');
        });
    }
};
