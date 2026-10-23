<?php

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\PermissionDefinition;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Employee;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Защита от эскалации прав и потери управления (ADR-0001, п. 5). */
class RoleEscalationTest extends TestCase
{
    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrg();
        $this->adminA = $this->userWithRole('company_admin', $this->companyA);
    }

    private function role(string $code): Role
    {
        return Role::query()->where('code', $code)->firstOrFail();
    }

    #[Test]
    public function company_admin_grants_roles_inside_own_company(): void
    {
        $target = User::factory()->homeAt($this->companyA)->create();

        $this->actingAs($this->adminA)->postJson("/api/v1/users/{$target->id}/role-scopes", [
            'role_id' => $this->role('director')->id, 'scope_type' => 'company', 'scope_id' => $this->companyA->id,
        ])->assertCreated()->assertJsonPath('role_scopes.0.role.name', 'Директор / руководитель группы');

        $this->assertDatabaseHas('audit_events', ['action' => 'user.role_granted', 'subject_id' => $target->id]);
    }

    #[Test]
    public function company_admin_cannot_grant_superadmin_or_roles_outside_scope(): void
    {
        $target = User::factory()->homeAt($this->companyA)->create();
        $this->actingAs($this->adminA);

        // Роль суперадминистратора содержит права, которых нет у администратора компании.
        $this->postJson("/api/v1/users/{$target->id}/role-scopes", [
            'role_id' => $this->role('superadmin')->id, 'scope_type' => 'group', 'scope_id' => $this->group->id,
        ])->assertForbidden();

        // Область другой компании.
        $this->postJson("/api/v1/users/{$target->id}/role-scopes", [
            'role_id' => $this->role('director')->id, 'scope_type' => 'company', 'scope_id' => $this->companyB->id,
        ])->assertForbidden();

        // Выше своей области (вся группа).
        $this->postJson("/api/v1/users/{$target->id}/role-scopes", [
            'role_id' => $this->role('director')->id, 'scope_type' => 'group', 'scope_id' => $this->group->id,
        ])->assertForbidden();

        $this->assertSame(0, $target->roleScopes()->count());
    }

    #[Test]
    public function company_admin_cannot_grant_a_role_with_permissions_they_lack(): void
    {
        $powerful = Role::factory()->create(['allowed_scope_types' => ['company']]);
        $powerful->permissions()->sync(PermissionDefinition::query()
            ->whereIn('code', ['users.view', 'ai.manage'])->pluck('id'));
        $target = User::factory()->homeAt($this->companyA)->create();

        $this->actingAs($this->adminA)->postJson("/api/v1/users/{$target->id}/role-scopes", [
            'role_id' => $powerful->id, 'scope_type' => 'company', 'scope_id' => $this->companyA->id,
        ])->assertForbidden();
    }

    #[Test]
    public function company_admin_cannot_grant_themselves_more(): void
    {
        $this->actingAs($this->adminA)->postJson("/api/v1/users/{$this->adminA->id}/role-scopes", [
            'role_id' => $this->role('company_admin')->id, 'scope_type' => 'company', 'scope_id' => $this->companyB->id,
        ])->assertForbidden();
    }

    #[Test]
    public function role_scope_type_must_be_allowed_by_role(): void
    {
        $target = User::factory()->homeAt($this->companyA)->create();

        $this->actingAs($this->superadmin())->postJson("/api/v1/users/{$target->id}/role-scopes", [
            'role_id' => $this->role('superadmin')->id, 'scope_type' => 'company', 'scope_id' => $this->companyA->id,
        ])->assertStatus(422)->assertJsonValidationErrors('scope_type');
    }

    #[Test]
    public function own_scope_requires_linked_employee(): void
    {
        $target = User::factory()->homeAt($this->companyA)->create();
        $this->actingAs($this->adminA);

        $this->postJson("/api/v1/users/{$target->id}/role-scopes", ['role_id' => $this->role('employee')->id, 'scope_type' => 'own'])
            ->assertStatus(422);

        $employee = Employee::factory()->create(['company_id' => $this->companyA->id]);
        $this->patchJson("/api/v1/users/{$target->id}", ['employee_id' => $employee->id])->assertOk();
        $this->postJson("/api/v1/users/{$target->id}/role-scopes", ['role_id' => $this->role('employee')->id, 'scope_type' => 'own'])
            ->assertCreated();
    }

    #[Test]
    public function company_admin_cannot_manage_user_who_also_has_a_role_elsewhere(): void
    {
        // Пользователь «живёт» в A, но имеет роль и в B: админ A не должен его отключать или менять пароль.
        $shared = $this->userWithRole('director', $this->companyA);
        $shared->roleScopes()->create(['role_id' => $this->role('director')->id, 'scope_type' => 'company', 'scope_id' => $this->companyB->id]);
        $this->actingAs($this->adminA);

        $this->getJson("/api/v1/users/{$shared->id}")->assertOk()
            ->assertJsonPath('can.update', false)
            ->assertJsonPath('can.disable', false);
        $this->postJson("/api/v1/users/{$shared->id}/status", ['is_active' => false, 'reason' => 'тест'])->assertForbidden();
        $this->postJson("/api/v1/users/{$shared->id}/password", ['password' => 'Another-Password-1'])->assertForbidden();
    }

    #[Test]
    public function company_admin_cannot_revoke_superadmin_role(): void
    {
        $root = $this->superadmin();
        // Суперадминистратор формально «живёт» в компании A.
        $root->forceFill($this->companyA->orgPath()->toColumns())->save();
        $assignment = $root->roleScopes()->firstOrFail();

        $this->actingAs($this->adminA)
            ->deleteJson("/api/v1/users/{$root->id}/role-scopes/{$assignment->id}")
            ->assertForbidden();
        $this->assertModelExists($assignment);
    }

    #[Test]
    public function last_superadmin_cannot_be_revoked_or_disabled(): void
    {
        $root = $this->superadmin();
        $other = $this->superadmin();
        $this->actingAs($other)->postJson("/api/v1/users/{$root->id}/status", ['is_active' => false, 'reason' => 'тест'])->assertOk();

        // Теперь $other — единственный активный суперадминистратор.
        $assignment = $other->roleScopes()->firstOrFail();
        $this->actingAs($other)->deleteJson("/api/v1/users/{$other->id}/role-scopes/{$assignment->id}")
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertModelExists($assignment);

        // Отключить себя нельзя в принципе.
        $this->actingAs($other)->postJson("/api/v1/users/{$other->id}/status", ['is_active' => false, 'reason' => 'тест'])
            ->assertForbidden();
    }

    #[Test]
    public function removing_roles_manage_from_the_only_admin_role_is_rejected(): void
    {
        $root = $this->superadmin();
        $role = $this->role('superadmin');

        $this->actingAs($root)->patchJson("/api/v1/roles/{$role->id}", [
            'permissions' => ['users.view'],
            'lock_version' => $role->lock_version,
        ])->assertStatus(422);

        $this->assertTrue($role->refresh()->permissions()->where('code', 'roles.manage')->exists());
    }

    #[Test]
    public function only_group_level_role_managers_edit_roles(): void
    {
        $role = $this->role('director');
        $this->actingAs($this->adminA);

        $this->getJson('/api/v1/roles')->assertOk(); // видит, чтобы назначать
        $this->patchJson("/api/v1/roles/{$role->id}", ['name' => 'X', 'lock_version' => $role->lock_version])->assertForbidden();
        $this->postJson('/api/v1/roles', [
            'code' => 'custom', 'name' => 'X', 'allowed_scope_types' => ['company'], 'permissions' => ['users.view'],
        ])->assertForbidden();
    }

    #[Test]
    public function role_update_uses_optimistic_locking(): void
    {
        $root = $this->superadmin();
        $role = $this->role('director');
        $version = $role->lock_version;
        $this->actingAs($root);

        $this->patchJson("/api/v1/roles/{$role->id}", ['name' => 'Первое изменение', 'lock_version' => $version])
            ->assertOk()->assertJsonPath('lock_version', $version + 1);
        $this->patchJson("/api/v1/roles/{$role->id}", ['name' => 'Устаревшее изменение', 'lock_version' => $version])
            ->assertStatus(422)->assertJsonValidationErrors('lock_version');

        $this->assertSame('Первое изменение', $role->refresh()->name);
    }

    #[Test]
    public function permission_changes_take_effect_and_are_audited(): void
    {
        $root = $this->superadmin();
        $director = $this->userWithRole('director', $this->companyA);
        $role = $this->role('director');

        $this->actingAs($director)->getJson('/api/v1/employees')->assertOk();

        $this->actingAs($root)->patchJson("/api/v1/roles/{$role->id}", [
            'permissions' => ['organization.view'], 'lock_version' => $role->lock_version,
        ])->assertOk();

        $this->actingAs($director)->getJson('/api/v1/employees')->assertForbidden();
        $this->assertDatabaseHas('audit_events', ['action' => 'role.permissions_changed', 'subject_id' => $role->id]);
    }

    #[Test]
    public function system_roles_and_assigned_roles_cannot_be_deleted(): void
    {
        $root = $this->superadmin();
        $this->actingAs($root);

        $this->deleteJson('/api/v1/roles/'.$this->role('director')->id)->assertForbidden();

        $custom = Role::factory()->create();
        $holder = User::factory()->homeAt($this->companyA)->create();
        $holder->roleScopes()->create(['role_id' => $custom->id, 'scope_type' => 'company', 'scope_id' => $this->companyA->id]);
        $this->deleteJson("/api/v1/roles/{$custom->id}")->assertStatus(422);

        $holder->roleScopes()->delete();
        $this->deleteJson("/api/v1/roles/{$custom->id}")->assertNoContent();
    }
}
