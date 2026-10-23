<?php

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Employee;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrg();
        $this->adminA = $this->userWithRole('company_admin', $this->companyA);
    }

    #[Test]
    public function admin_creates_user_with_temporary_password_in_own_company(): void
    {
        $response = $this->actingAs($this->adminA)->postJson('/api/v1/users', [
            'login' => 'New.Manager',
            'name' => 'Новый менеджер',
            'email' => 'manager@example.test',
            'password' => 'Temporary-Pass-1',
            'department_id' => $this->salesA->id,
        ])->assertCreated();

        $response->assertJsonPath('login', 'new.manager')
            ->assertJsonPath('must_change_password', true)
            ->assertJsonPath('home.department.id', $this->salesA->id)
            ->assertJsonMissingPath('password');

        $user = User::query()->where('login', 'new.manager')->firstOrFail();
        $this->assertSame($this->companyA->id, $user->company_id);
        $this->assertSame($this->dealershipA->id, $user->dealership_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.created', 'subject_id' => $user->id, 'actor_user_id' => $this->adminA->id]);
    }

    #[Test]
    public function inconsistent_org_path_is_rejected(): void
    {
        $this->actingAs($this->superadmin())->postJson('/api/v1/users', [
            'login' => 'broken', 'name' => 'X', 'password' => 'Temporary-Pass-1',
            'company_id' => $this->companyB->id, 'department_id' => $this->salesA->id,
        ])->assertStatus(422)->assertJsonValidationErrors('company_id');
    }

    #[Test]
    public function login_and_email_are_unique(): void
    {
        User::factory()->homeAt($this->companyA)->create(['login' => 'taken', 'email' => 'taken@example.test']);

        $this->actingAs($this->adminA)->postJson('/api/v1/users', [
            'login' => 'TAKEN', 'name' => 'X', 'email' => 'Taken@Example.test', 'password' => 'Temporary-Pass-1',
            'company_id' => $this->companyA->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['login', 'email']);
    }

    #[Test]
    public function disabling_a_user_terminates_sessions_and_requires_reason(): void
    {
        $target = User::factory()->homeAt($this->companyA)->create();
        DB::table('sessions')->insert(['id' => 's1', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($this->adminA);

        $this->postJson("/api/v1/users/{$target->id}/status", ['is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->postJson("/api/v1/users/{$target->id}/status", ['is_active' => false, 'reason' => 'Уволен'])
            ->assertOk()->assertJsonPath('is_active', false);

        $this->assertDatabaseMissing('sessions', ['id' => 's1']);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.disabled', 'subject_id' => $target->id, 'reason' => 'Уволен']);

        $this->postJson('/api/v1/auth/logout');
        $this->postJson('/api/v1/auth/login', ['login' => $target->login, 'password' => UserFactory::PASSWORD])->assertStatus(422);
    }

    #[Test]
    public function admin_sets_temporary_password(): void
    {
        $target = User::factory()->homeAt($this->companyA)->create();

        $this->actingAs($this->adminA)->postJson("/api/v1/users/{$target->id}/password", ['password' => 'Temporary-Pass-2'])
            ->assertOk();

        $this->assertTrue($target->refresh()->must_change_password);
        $this->assertDatabaseHas('audit_events', ['action' => 'user.password_set_by_admin', 'subject_id' => $target->id]);
    }

    #[Test]
    public function admin_cannot_move_user_to_another_company(): void
    {
        $target = User::factory()->homeAt($this->companyA)->create();

        $this->actingAs($this->adminA)->patchJson("/api/v1/users/{$target->id}", ['company_id' => $this->companyB->id])
            ->assertForbidden();
        $this->assertSame($this->companyA->id, $target->refresh()->company_id);
    }

    #[Test]
    public function employee_can_be_linked_to_only_one_account(): void
    {
        $employee = Employee::factory()->create(['company_id' => $this->companyA->id]);
        $first = User::factory()->homeAt($this->companyA)->create(['employee_id' => $employee->id]);
        $second = User::factory()->homeAt($this->companyA)->create();

        $this->actingAs($this->adminA)->patchJson("/api/v1/users/{$second->id}", ['employee_id' => $employee->id])
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');
        $this->assertSame($employee->id, $first->refresh()->employee_id);
    }

    #[Test]
    public function users_list_is_paginated(): void
    {
        User::factory()->count(30)->homeAt($this->companyA)->create();

        $this->actingAs($this->adminA)->getJson('/api/v1/users?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 31);
    }
}
