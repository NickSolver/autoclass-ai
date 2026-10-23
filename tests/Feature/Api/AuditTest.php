<?php

namespace Tests\Feature\Api;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrg();
    }

    #[Test]
    public function update_is_recorded_with_actor_and_old_and_new_values(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA);

        $this->actingAs($admin)->patchJson("/api/v1/dealerships/{$this->dealershipA->id}", ['name' => 'ДЦ A (новый)'])->assertOk();

        $event = AuditEvent::query()->where('action', 'dealership.updated')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame($this->companyA->id, $event->company_id);
        $this->assertEquals(['old' => 'ДЦ A', 'new' => 'ДЦ A (новый)'], $event->changes['name']);
    }

    #[Test]
    public function passwords_never_reach_the_audit_log(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $this->actingAs($admin)->postJson('/api/v1/users', [
            'login' => 'secret.keeper', 'name' => 'X', 'password' => 'Very-Secret-Pass-1', 'company_id' => $this->companyA->id,
        ])->assertCreated();
        $target = User::query()->where('login', 'secret.keeper')->firstOrFail();
        $this->postJson("/api/v1/users/{$target->id}/password", ['password' => 'Another-Secret-Pass-2'])->assertOk();

        $dump = AuditEvent::query()->get()->toJson();
        $this->assertStringNotContainsString('Very-Secret-Pass-1', $dump);
        $this->assertStringNotContainsString('Another-Secret-Pass-2', $dump);
        $this->assertStringNotContainsString($target->refresh()->password, $dump, 'Хеш пароля тоже не пишется');
    }

    #[Test]
    public function audit_records_are_immutable(): void
    {
        $event = AuditEvent::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $event->update(['action' => 'tampered']);
    }

    #[Test]
    public function audit_list_is_scoped_and_filterable(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $events = collect($this->actingAs($admin)->getJson('/api/v1/audit?per_page=100')->assertOk()->json('data'));

        $this->assertNotEmpty($events);
        $this->assertTrue($events->every(fn ($e) => $e['company_id'] === $this->companyA->id));

        $filtered = collect($this->getJson('/api/v1/audit?action=dealership.')->json('data'));
        $this->assertTrue($filtered->every(fn ($e) => str_starts_with($e['action'], 'dealership.')));
    }
}
