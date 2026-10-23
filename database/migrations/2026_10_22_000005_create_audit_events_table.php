<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал аудита. Только добавление записей; из приложения не изменяется и не удаляется.
 * Путь в оргструктуре нужен для фильтрации по области права audit.view.
 * FK на users не ставится намеренно: запись аудита должна пережить любые изменения пользователей.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at', 3);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('actor_login', 64)->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('dealership_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->index('occurred_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_user_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index(['company_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
