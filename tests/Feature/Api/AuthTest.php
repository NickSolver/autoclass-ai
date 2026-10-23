<?php

namespace Tests\Feature\Api;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrg();
        RateLimiter::clear('login');
    }

    #[Test]
    public function user_logs_in_with_login_and_password_case_insensitively(): void
    {
        $user = $this->userWithRole('company_admin', $this->companyA, attributes: ['login' => 'ivan.admin']);

        $this->postJson('/api/v1/auth/login', ['login' => 'IVAN.Admin', 'password' => UserFactory::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.login', 'ivan.admin')
            ->assertJsonPath('user.must_change_password', false)
            ->assertJsonFragment(['users.create']);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->last_login_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.login', 'subject_id' => $user->id]);
    }

    #[Test]
    public function wrong_password_unknown_login_and_disabled_user_get_the_same_generic_error(): void
    {
        User::factory()->homeAt($this->companyA)->create(['login' => 'known']);
        User::factory()->homeAt($this->companyA)->inactive()->create(['login' => 'disabled']);

        $responses = [
            $this->postJson('/api/v1/auth/login', ['login' => 'known', 'password' => 'wrong-password']),
            $this->postJson('/api/v1/auth/login', ['login' => 'nobody', 'password' => 'wrong-password']),
            $this->postJson('/api/v1/auth/login', ['login' => 'disabled', 'password' => UserFactory::PASSWORD]),
        ];

        foreach ($responses as $response) {
            $response->assertStatus(422)->assertJsonPath('errors.login.0', 'Неверный логин или пароль.');
        }
        $this->assertGuest();
        $this->assertSame(3, AuditEvent::query()->where('action', 'auth.login_failed')->count());
    }

    #[Test]
    public function login_attempts_are_throttled_per_login_and_ip(): void
    {
        User::factory()->homeAt($this->companyA)->create(['login' => 'target']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['login' => 'target', 'password' => 'bad'])->assertStatus(422);
        }

        // Даже верный пароль не принимается, пока действует блокировка.
        $this->postJson('/api/v1/auth/login', ['login' => 'target', 'password' => UserFactory::PASSWORD])
            ->assertStatus(429);
        $this->assertGuest();
    }

    #[Test]
    public function there_is_no_public_registration(): void
    {
        $this->postJson('/api/v1/auth/register', ['login' => 'x', 'password' => 'y'])->assertNotFound();
        $this->postJson('/api/v1/users', ['login' => 'x'])->assertUnauthorized();
    }

    #[Test]
    public function guests_get_401_on_protected_endpoints(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->getJson('/api/v1/companies')->assertUnauthorized();
        $this->getJson('/api/v1/audit')->assertUnauthorized();
    }

    #[Test]
    public function logout_ends_the_session(): void
    {
        $user = $this->userWithRole('company_admin', $this->companyA);
        $this->postJson('/api/v1/auth/login', ['login' => $user->login, 'password' => UserFactory::PASSWORD])->assertOk();

        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.logout', 'subject_id' => $user->id]);
    }

    #[Test]
    public function temporary_password_must_be_changed_before_using_the_portal(): void
    {
        $user = $this->userWithRole('company_admin', $this->companyA, attributes: ['must_change_password' => true]);
        $this->actingAs($user);

        $this->getJson('/api/v1/companies')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('user.must_change_password', true);

        $this->postJson('/api/v1/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'New-Strong-Pass-2026',
            'password_confirmation' => 'New-Strong-Pass-2026',
        ])->assertNoContent();

        $this->assertFalse($user->refresh()->must_change_password);
        $this->getJson('/api/v1/companies')->assertOk();
    }

    #[Test]
    public function password_change_requires_current_password_and_a_strong_new_one(): void
    {
        $this->actingAs($this->userWithRole('company_admin', $this->companyA));

        $this->postJson('/api/v1/auth/password', [
            'current_password' => 'wrong',
            'password' => 'New-Strong-Pass-2026',
            'password_confirmation' => 'New-Strong-Pass-2026',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->postJson('/api/v1/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    #[Test]
    public function disabled_user_loses_access_immediately_even_with_a_live_session(): void
    {
        $user = $this->userWithRole('company_admin', $this->companyA);
        $this->actingAs($user);
        $this->getJson('/api/v1/auth/me')->assertOk();

        $user->forceFill(['is_active' => false])->saveQuietly();

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
