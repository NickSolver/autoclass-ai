<?php

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SessionsTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrg();
        $this->user = $this->userWithRole('company_admin', $this->companyA);
        DB::table('sessions')->insert([
            ['id' => 'laptop', 'user_id' => $this->user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Laptop', 'payload' => '', 'last_activity' => time()],
            ['id' => 'phone', 'user_id' => $this->user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'Phone', 'payload' => '', 'last_activity' => time() - 60],
        ]);
    }

    #[Test]
    public function user_sees_own_sessions_without_raw_session_ids(): void
    {
        $data = $this->actingAs($this->user)->getJson('/api/v1/auth/sessions')->assertOk()->json('data');

        $this->assertCount(2, $data);
        $this->assertSame(hash('sha256', 'laptop'), $data[0]['id']);
        $this->assertStringNotContainsString('laptop', json_encode($data));
    }

    #[Test]
    public function user_terminates_a_single_session(): void
    {
        $this->actingAs($this->user)->deleteJson('/api/v1/auth/sessions/'.hash('sha256', 'phone'))->assertNoContent();

        $this->assertDatabaseMissing('sessions', ['id' => 'phone']);
        $this->assertDatabaseHas('sessions', ['id' => 'laptop']);
    }

    #[Test]
    public function user_cannot_terminate_someone_elses_session(): void
    {
        $other = $this->userWithRole('company_admin', $this->companyB);
        DB::table('sessions')->insert(['id' => 'foreign', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->user)->deleteJson('/api/v1/auth/sessions/'.hash('sha256', 'foreign'))->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => 'foreign']);
    }

    #[Test]
    public function user_terminates_all_other_sessions(): void
    {
        $this->actingAs($this->user)->deleteJson('/api/v1/auth/sessions')->assertOk()->assertJsonPath('terminated', 2);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
    }

    #[Test]
    public function admin_terminates_sessions_only_for_users_in_scope(): void
    {
        $adminA = $this->userWithRole('company_admin', $this->companyA);
        $adminB = $this->userWithRole('company_admin', $this->companyB);

        $this->actingAs($adminB)->deleteJson("/api/v1/users/{$this->user->id}/sessions")->assertNotFound();
        $this->assertSame(2, DB::table('sessions')->where('user_id', $this->user->id)->count());

        $this->actingAs($adminA)->deleteJson("/api/v1/users/{$this->user->id}/sessions")->assertOk();
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->user->id)->count());
    }
}
