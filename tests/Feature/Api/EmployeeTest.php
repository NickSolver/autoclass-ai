<?php

namespace Tests\Feature\Api;

use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Models\EmployeeAssignment;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrg();
    }

    #[Test]
    public function department_head_sees_only_employees_assigned_to_their_department(): void
    {
        $mine = Employee::factory()->create(['company_id' => $this->companyA->id]);
        EmployeeAssignment::factory()->for($mine)->inDepartment($this->salesA)->create();
        $notMine = Employee::factory()->create(['company_id' => $this->companyA->id]); // без назначения в отдел

        $rop = $this->userWithRole('head_of_department', $this->salesA);
        $ids = collect($this->actingAs($rop)->getJson('/api/v1/employees')->assertOk()->json('data'))->pluck('id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($notMine->id, $ids);
        $this->getJson("/api/v1/employees/{$notMine->id}")->assertNotFound();
    }

    #[Test]
    public function employee_with_own_scope_sees_only_themself(): void
    {
        $self = Employee::factory()->create(['company_id' => $this->companyA->id]);
        $colleague = Employee::factory()->create(['company_id' => $this->companyA->id]);
        $user = $this->userWithRole('employee', null, $this->companyA, ['employee_id' => $self->id]);

        $ids = collect($this->actingAs($user)->getJson('/api/v1/employees')->assertOk()->json('data'))->pluck('id');

        $this->assertEquals([$self->id], $ids->all());
        $this->getJson("/api/v1/employees/{$colleague->id}")->assertNotFound();
        $this->patchJson("/api/v1/employees/{$self->id}", ['last_name' => 'Новая'])->assertForbidden();
    }

    #[Test]
    public function transfer_keeps_assignment_history(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $employee = Employee::factory()->create(['company_id' => $this->companyA->id]);
        $this->actingAs($admin);

        $first = $this->postJson("/api/v1/employees/{$employee->id}/assignments", [
            'department_id' => $this->salesA->id, 'position' => 'Менеджер', 'is_primary' => true, 'starts_on' => '2025-01-01',
        ])->assertCreated()->json('id');

        $this->patchJson("/api/v1/employees/{$employee->id}/assignments/{$first}", ['ends_on' => '2025-12-31'])
            ->assertOk()->assertJsonPath('is_current', false);

        $this->patchJson("/api/v1/employees/{$employee->id}/assignments/{$first}", ['ends_on' => '2024-01-01'])
            ->assertStatus(422)->assertJsonValidationErrors('ends_on');

        $this->patchJson("/api/v1/employees/{$employee->id}/assignments/{$first}", ['department_id' => $this->salesB->id])
            ->assertStatus(422);

        $this->postJson("/api/v1/employees/{$employee->id}/assignments", [
            'dealership_id' => $this->dealershipA->id, 'position' => 'Старший менеджер', 'is_primary' => true, 'starts_on' => '2026-01-01',
        ])->assertCreated()->assertJsonPath('is_current', true);

        $assignments = $this->getJson("/api/v1/employees/{$employee->id}")->assertOk()->json('assignments');
        $this->assertCount(2, $assignments);
        $this->assertSame(1, collect($assignments)->where('is_primary', true)->count());
    }

    #[Test]
    public function assignment_of_another_employee_cannot_be_addressed_through_a_different_employee(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $e1 = Employee::factory()->create(['company_id' => $this->companyA->id]);
        $e2 = Employee::factory()->create(['company_id' => $this->companyA->id]);
        $a2 = EmployeeAssignment::factory()->for($e2)->inDepartment($this->salesA)->create();

        $this->actingAs($admin)->deleteJson("/api/v1/employees/{$e1->id}/assignments/{$a2->id}")->assertNotFound();
        $this->assertModelExists($a2);
    }
}
