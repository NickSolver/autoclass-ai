<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Двухфакторная аутентификация (TOTP). ТЗ, разд. 3.1; решения — docs/adr/0002-mfa.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Секрет TOTP зашифрован ключом приложения (cast encrypted). Смена APP_KEY делает секреты нечитаемыми.
            $table->text('mfa_secret')->nullable()->after('password');
            $table->timestamp('mfa_enabled_at')->nullable()->after('mfa_secret');
            // Временной шаг последнего принятого кода — защита от повторного использования кода.
            $table->unsignedBigInteger('mfa_last_timestep')->nullable()->after('mfa_enabled_at');
            // Начало льготного периода на подключение MFA (для тех, кому она обязательна).
            $table->timestamp('mfa_grace_started_at')->nullable()->after('mfa_last_timestep');
        });

        Schema::create('mfa_recovery_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at');
        });

        Schema::create('mfa_trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mfa_trusted_devices');
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_enabled_at', 'mfa_last_timestep', 'mfa_grace_started_at']);
        });
    }
};
