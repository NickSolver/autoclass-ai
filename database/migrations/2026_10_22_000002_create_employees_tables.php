<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сотрудник — не учётная запись. Учётная запись (users.employee_id) опциональна.
 * Назначения хранят историю работы в подразделениях с датами начала и окончания.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('company_groups')->restrictOnDelete();
            // Основная компания-работодатель; определяет, кто управляет карточкой сотрудника.
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('last_name', 100);
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('personnel_number', 64)->nullable();
            $table->string('email')->nullable();
            $table->string('work_phone', 32)->nullable();
            // Добавочный номер АТС — пригодится для сопоставления звонков (этапы B/F).
            $table->string('phone_extension', 16)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'personnel_number']);
            $table->index(['company_id', 'is_active']);
            $table->index(['last_name', 'first_name']);
        });

        Schema::create('employee_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('company_groups')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('dealership_id')->nullable()->constrained('dealerships')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('position')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'starts_on']);
            $table->index(['company_id', 'starts_on']);
            $table->index(['department_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_assignments');
        Schema::dropIfExists('employees');
    }
};
