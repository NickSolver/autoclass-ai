<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Оргструктура: группа компаний → компания → дилерский центр → отдел.
 * Таблица группы названа company_groups: GROUPS — зарезервированное слово MySQL 8.
 * Путь (group_id, company_id, dealership_id) денормализован для фильтрации по области доступа (ADR-0001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('company_groups')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['group_id', 'code']);
            $table->index(['group_id', 'is_active']);
        });

        Schema::create('dealerships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('company_groups')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('company_groups')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            // NULL — отдел уровня компании (например, общий колл-центр).
            $table->foreignId('dealership_id')->nullable()->constrained('dealerships')->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('kind', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['dealership_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
        Schema::dropIfExists('dealerships');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('company_groups');
    }
};
