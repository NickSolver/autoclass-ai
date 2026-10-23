<?php

namespace Tests\Feature\Api;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Models\EmployeeAssignment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Приёмка A: директор/администратор компании A не получает записи компании B через API.
 */
class CompanyIsolationTest extends TestCase
{
    private User $directorA;

    private User $adminA;

    private Employee $employeeB;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrg();
        $this->directorA = $this->userWithRole('director', $this->companyA);
        $this->adminA = $this->userWithRole('company_admin', $this->companyA);

        $this->employeeB = Employee::factory()->create(['company_id' => $this->companyB->id, 'last_name' => 'Сотрудник-B']);
        EmployeeAssignment::factory()->for($this->employeeB)->inDepartment($this->salesB)->create();
        $this->userB = $this->userWithRole('company_admin', $this->companyB, attributes: ['login' => 'user-b']);
    }

    #[Test]
    public function lists_contain_only_own_company_records(): void
    {
        foreach ([$this->directorA, $this->adminA] as $actor) {
            $this->actingAs($actor);

            $companies = collect($this->getJson('/api/v1/companies')->assertOk()->json('data'))->pluck('id');
            $this->assertEquals([$this->companyA->id], $companies->all());

            $dealerships = collect($this->getJson('/api/v1/dealerships')->assertOk()->json('data'))->pluck('id');
            $this->assertEquals([$this->dealershipA->id], $dealerships->all());

            $departments = collect($this->getJson('/api/v1/departments')->assertOk()->json('data'))->pluck('id');
            $this->assertEquals([$this->salesA->id], $departments->all());

            $employees = collect($this->getJson('/api/v1/employees')->assertOk()->json('data'))->pluck('id');
            $this->assertNotContains($this->employeeB->id, $employees);

            $users = collect($this->getJson('/api/v1/users')->assertOk()->json('data'))->pluck('id');
            $this->assertNotContains($this->userB->id, $users);
        }
    }

    #[Test]
    public function filters_do_not_bypass_scope(): void
    {
        $this->actingAs($this->adminA);

        $this->assertSame([], $this->getJson("/api/v1/dealerships?company_id={$this->companyB->id}")->json('data'));
        $this->assertSame([], $this->getJson("/api/v1/employees?company_id={$this->companyB->id}")->json('data'));
        $this->assertSame([], $this->getJson("/api/v1/users?company_id={$this->companyB->id}")->json('data'));
        $this->assertSame([], $this->getJson("/api/v1/audit?company_id={$this->companyB->id}")->json('data'));
    }

    #[Test]
    public function foreign_records_are_404_not_403(): void
    {
        $this->actingAs($this->adminA);

        $this->getJson("/api/v1/companies/{$this->companyB->id}")->assertNotFound();
        $this->getJson("/api/v1/dealerships/{$this->dealershipB->id}")->assertNotFound();
        $this->getJson("/api/v1/departments/{$this->salesB->id}")->assertNotFound();
        $this->getJson("/api/v1/employees/{$this->employeeB->id}")->assertNotFound();
        $this->getJson("/api/v1/users/{$this->userB->id}")->assertNotFound();
        $this->getJson("/api/v1/groups/{$this->group->id}")->assertNotFound();

        $event = AuditEvent::query()->where('company_id', $this->companyB->id)->firstOrFail();
        $this->getJson("/api/v1/audit/{$event->id}")->assertNotFound();
    }

    #[Test]
    public function foreign_records_cannot_be_modified(): void
    {
        $this->actingAs($this->adminA);

        $this->patchJson("/api/v1/companies/{$this->companyB->id}", ['name' => 'Взлом'])->assertNotFound();
        $this->patchJson("/api/v1/dealerships/{$this->dealershipB->id}", ['name' => 'Взлом'])->assertNotFound();
        $this->patchJson("/api/v1/employees/{$this->employeeB->id}", ['last_name' => 'Взлом'])->assertNotFound();
        $this->patchJson("/api/v1/users/{$this->userB->id}", ['name' => 'Взлом'])->assertNotFound();
        $this->postJson("/api/v1/users/{$this->userB->id}/status", ['is_active' => false, 'reason' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/users/{$this->userB->id}/password", ['password' => 'Hacked-Password-1'])->assertNotFound();

        $this->assertSame('Компания B', $this->companyB->refresh()->name);
        $this->assertTrue($this->userB->refresh()->is_active);
    }

    #[Test]
    public function cannot_create_records_inside_another_company(): void
    {
        $this->actingAs($this->adminA);

        $this->postJson('/api/v1/dealerships', ['company_id' => $this->companyB->id, 'code' => 'x', 'name' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors('company_id');
        $this->postJson('/api/v1/departments', ['company_id' => $this->companyB->id, 'code' => 'x', 'name' => 'X'])
            ->assertStatus(422);
        $this->postJson('/api/v1/employees', ['company_id' => $this->companyB->id, 'last_name' => 'X', 'first_name' => 'Y'])
            ->assertStatus(422);
        $this->postJson('/api/v1/users', [
            'login' => 'intruder', 'name' => 'X', 'password' => 'Strong-Password-1', 'company_id' => $this->companyB->id,
        ])->assertForbidden();
        // Компании создаются только на уровне группы.
        $this->postJson('/api/v1/companies', ['group_id' => $this->group->id, 'code' => 'c', 'name' => 'C'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['login' => 'intruder']);
    }

    #[Test]
    public function cannot_assign_own_employee_into_another_company(): void
    {
        $employeeA = Employee::factory()->create(['company_id' => $this->companyA->id]);

        $this->actingAs($this->adminA)
            ->postJson("/api/v1/employees/{$employeeA->id}/assignments", [
                'department_id' => $this->salesB->id, 'starts_on' => '2026-01-01',
            ])->assertForbidden();
    }

    #[Test]
    public function org_tree_contains_only_visible_units(): void
    {
        $tree = $this->actingAs($this->directorA)->getJson('/api/v1/org/tree')->assertOk()->json('data');
        $json = json_encode($tree, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Компания A', $json);
        $this->assertStringNotContainsString('Компания B', $json);
        $this->assertStringNotContainsString('ДЦ B', $json);
        $this->assertFalse($tree[0]['selectable'], 'Группа показывается только как контекст');
    }

    #[Test]
    public function director_without_manage_permission_cannot_change_own_company(): void
    {
        $this->actingAs($this->directorA)
            ->patchJson("/api/v1/companies/{$this->companyA->id}", ['name' => 'Новое имя'])
            ->assertForbidden();
        $this->actingAs($this->directorA)->getJson('/api/v1/audit')->assertForbidden();
    }

    #[Test]
    public function superadmin_sees_all_companies(): void
    {
        $ids = collect($this->actingAs($this->superadmin())->getJson('/api/v1/companies')->json('data'))->pluck('id');

        $this->assertEqualsCanonicalizing([$this->companyA->id, $this->companyB->id], $ids->all());
    }

    #[Test]
    public function user_without_roles_gets_nothing(): void
    {
        $nobody = User::factory()->homeAt($this->companyA)->create();
        $this->actingAs($nobody);

        $this->getJson('/api/v1/companies')->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson('/api/v1/roles')->assertForbidden();
        $this->getJson('/api/v1/audit')->assertForbidden();
        $this->assertSame([], $this->getJson('/api/v1/org/tree')->assertOk()->json('data'));
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('permissions', []);
    }
}
