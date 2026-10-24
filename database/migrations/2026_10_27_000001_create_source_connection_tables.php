<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Этап F (каркас): подключаемые источники звонков по единому договору адаптера (ТЗ, разд. 4.1).
 * Реальные адаптеры Calltouch и «Лягушки» — после документации; до тех пор — тестовый источник.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('call_sources')->restrictOnDelete();
            $table->string('name');
            // Область подключения: звонки попадают в компанию (и подразделение по умолчанию, если задано).
            $table->foreignId('group_id')->constrained('company_groups')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('dealership_id')->nullable()->constrained('dealerships')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->json('settings');
            $table->text('secret')->nullable(); // зашифровано (APP_KEY), наружу не отдаётся
            $table->timestamp('secret_set_at')->nullable();
            // Адрес приёма событий (push): случайный токен в URL + подпись тела запроса.
            $table->string('webhook_token', 64)->unique();
            $table->text('webhook_secret')->nullable(); // зашифровано
            $table->boolean('is_enabled')->default(false);
            $table->unsignedSmallInteger('sync_interval_minutes')->default(15);
            $table->string('cursor', 500)->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['is_enabled', 'last_sync_at']);
        });

        Schema::create('source_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_connection_id')->constrained('source_connections')->cascadeOnDelete();
            $table->string('trigger', 16); // schedule | manual | webhook
            $table->string('status', 16); // running | succeeded | partial | failed
            $table->string('cursor_from', 500)->nullable();
            $table->string('cursor_to', 500)->nullable();
            $table->unsignedInteger('fetched')->default(0);
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->unsignedInteger('without_recording')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('error_message', 500)->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index(['source_connection_id', 'started_at']);
        });

        // Каждое полученное событие: внешний ID, результат импорта, сырой payload (зашифрован).
        Schema::create('source_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_connection_id')->constrained('source_connections')->cascadeOnDelete();
            $table->foreignId('source_sync_run_id')->nullable()->constrained('source_sync_runs')->nullOnDelete();
            $table->string('channel', 8); // pull | push
            $table->string('external_id', 191)->nullable();
            $table->string('status', 24); // imported | duplicate | without_recording | failed | rejected
            $table->foreignId('call_id')->nullable()->constrained('calls')->nullOnDelete();
            $table->longText('payload')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('received_at');

            $table->index(['source_connection_id', 'received_at']);
            $table->index(['source_connection_id', 'external_id']);
        });

        Schema::table('calls', function (Blueprint $table) {
            $table->foreignId('source_connection_id')->nullable()->after('source_id')->constrained('source_connections')->nullOnDelete();
            // Номер клиента — только маскированный (ТЗ, разд. 12); полный остаётся в зашифрованном payload события.
            $table->string('client_phone_masked', 32)->nullable()->after('duration_ms');
        });
    }

    public function down(): void
    {
        Schema::table('calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_connection_id');
            $table->dropColumn('client_phone_masked');
        });
        Schema::dropIfExists('source_events');
        Schema::dropIfExists('source_sync_runs');
        Schema::dropIfExists('source_connections');
    }
};
