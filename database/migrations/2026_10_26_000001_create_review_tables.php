<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Этап E: ручная проверка, правки и апелляции (ТЗ, разд. 11).
 * Правка человека — новая ревизия результата; результат ИИ не изменяется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evaluation_results', function (Blueprint $table) {
            $table->string('origin', 8)->default('ai')->after('role'); // ai | human
            $table->unsignedSmallInteger('revision')->default(1)->after('origin');
            $table->foreignId('supersedes_id')->nullable()->after('revision')->constrained('evaluation_results')->restrictOnDelete();
            // Последняя ревизия результата по чек-листу в анализе — итоговая.
            $table->boolean('is_current')->default(true)->after('supersedes_id');
            $table->foreignId('created_by')->nullable()->after('issues')->constrained('users')->nullOnDelete();
            $table->text('change_reason')->nullable()->after('created_by');

            $table->index(['call_id', 'role', 'is_current']);
        });

        Schema::table('criterion_results', function (Blueprint $table) {
            $table->string('origin', 8)->default('ai')->after('status');
            $table->string('previous_status', 24)->nullable()->after('origin');
        });

        Schema::table('calls', function (Blueprint $table) {
            // Анализ, результаты которого действуют для звонка (последний успешный).
            $table->foreignId('current_analysis_run_id')->nullable()->constrained('analysis_runs')->nullOnDelete();
        });

        Schema::create('manual_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('company_groups')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('dealership_id')->nullable()->constrained('dealerships')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('analysis_run_id')->nullable()->constrained('analysis_runs')->nullOnDelete();
            $table->string('source', 16); // ai | workflow | manual | appeal
            $table->string('reason', 500);
            $table->string('status', 16); // pending | in_progress | resolved | cancelled
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 16)->nullable(); // confirmed | corrected | cancelled
            $table->text('resolution_comment')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['status', 'company_id']);
            $table->index(['assignee_id', 'status']);
            $table->index(['call_id', 'status']);
        });

        Schema::create('appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('call_id')->constrained('calls')->cascadeOnDelete();
            $table->foreignId('evaluation_result_id')->constrained('evaluation_results')->restrictOnDelete();
            $table->foreignId('group_id')->constrained('company_groups')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('dealership_id')->nullable()->constrained('dealerships')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 16); // open | in_review | accepted | rejected | withdrawn
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_comment')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['status', 'company_id']);
            $table->index(['created_by', 'status']);
        });

        Schema::create('review_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manual_review_id')->nullable()->constrained('manual_reviews')->cascadeOnDelete();
            $table->foreignId('appeal_id')->nullable()->constrained('appeals')->cascadeOnDelete();
            $table->foreignId('evaluation_result_id')->constrained('evaluation_results')->restrictOnDelete();
            $table->foreignId('new_evaluation_result_id')->nullable()->constrained('evaluation_results')->restrictOnDelete();
            $table->string('decision', 16); // confirmed | corrected | rejected
            $table->text('reason');
            // Изменения критериев: код, было, стало, комментарий.
            $table->json('changes');
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_decisions');
        Schema::dropIfExists('appeals');
        Schema::dropIfExists('manual_reviews');
        Schema::table('calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_analysis_run_id');
        });
        Schema::table('criterion_results', function (Blueprint $table) {
            $table->dropColumn(['origin', 'previous_status']);
        });
        Schema::table('evaluation_results', function (Blueprint $table) {
            $table->dropIndex(['call_id', 'role', 'is_current']);
            $table->dropConstrainedForeignId('supersedes_id');
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['origin', 'revision', 'is_current', 'change_reason']);
        });
    }
};
