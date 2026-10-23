<?php

namespace Tests\Feature\Api;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MfaService;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Двухфакторная аутентификация (ADR-0002, ТЗ разд. 3.1). */
class MfaTest extends TestCase
{
    private Google2FA $totp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrg();
        $this->totp = new Google2FA;
    }

    /** Подключить MFA напрямую; возвращает секрет. */
    private function enableMfa(User $user): string
    {
        $secret = $this->totp->generateSecretKey(32);
        $user->forceFill(['mfa_secret' => $secret, 'mfa_enabled_at' => now(), 'mfa_last_timestep' => null])->saveQuietly();

        return $secret;
    }

    private function code(string $secret, int $offsetSteps = 0): string
    {
        return $this->totp->oathTotp($secret, $this->totp->getTimestamp() + $offsetSteps);
    }

    private function login(User $user): void
    {
        $this->postJson('/api/v1/auth/login', ['login' => $user->login, 'password' => UserFactory::PASSWORD])->assertOk();
    }

    #[Test]
    public function admin_without_mfa_works_during_grace_period_and_is_blocked_after(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $this->login($admin);

        $this->getJson('/api/v1/auth/me')->assertJsonPath('mfa.status', 'setup_recommended')->assertJsonPath('mfa.required', true);
        $this->getJson('/api/v1/companies')->assertOk();
        $this->assertNotNull($admin->refresh()->mfa_grace_started_at);

        $this->travel(8)->days();

        $this->getJson('/api/v1/companies')->assertForbidden()->assertJsonPath('code', 'mfa_setup_required');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('mfa.status', 'setup_required');
    }

    #[Test]
    public function setup_with_qr_and_code_returns_recovery_codes_once(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA, attributes: ['mfa_grace_started_at' => now()->subDays(30)]);
        $this->login($admin);

        $setup = $this->postJson('/api/v1/auth/mfa/setup')->assertOk();
        $this->assertStringStartsWith('otpauth://totp/', $setup->json('otpauth_url'));
        $this->assertStringContainsString('<svg', $setup->json('qr_svg'));
        $this->assertNull($admin->refresh()->mfa_secret, 'Секрет сохраняется только после подтверждения');

        $this->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000'])->assertStatus(422)->assertJsonValidationErrors('code');

        $confirm = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->code($setup->json('secret'))])->assertOk();
        $this->assertCount(10, $confirm->json('recovery_codes'));
        $confirm->assertJsonPath('mfa.status', 'ok')->assertJsonPath('mfa.enabled', true);

        $this->getJson('/api/v1/companies')->assertOk();
        $this->postJson('/api/v1/auth/mfa/setup')->assertStatus(422); // повторно подключить нельзя
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.mfa_enabled', 'subject_id' => $admin->id]);
    }

    #[Test]
    public function secret_is_encrypted_at_rest_and_never_exposed(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $secret = $this->enableMfa($admin);

        $raw = DB::table('users')->where('id', $admin->id)->value('mfa_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, (string) $raw);

        $root = $this->superadmin();
        $json = $this->actingAs($root)->getJson("/api/v1/users/{$admin->id}")->assertOk()->assertJsonPath('mfa_enabled', true)->getContent();
        $this->assertStringNotContainsString($secret, $json);
        $this->assertStringNotContainsString($secret, AuditEvent::query()->get()->toJson());
    }

    #[Test]
    public function login_with_mfa_requires_a_valid_fresh_code(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $secret = $this->enableMfa($admin);
        $this->login($admin);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('mfa.status', 'verify_required');
        $this->getJson('/api/v1/companies')->assertForbidden()->assertJsonPath('code', 'mfa_verification_required');
        $this->getJson('/api/v1/auth/sessions')->assertForbidden();

        $this->postJson('/api/v1/auth/mfa/verify', ['code' => '123456'])->assertStatus(422);
        $code = $this->code($secret);
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $code])->assertOk()->assertJsonPath('mfa.status', 'ok');
        $this->getJson('/api/v1/companies')->assertOk();

        // Тот же код повторно не принимается (защита от перехвата и повтора).
        $this->postJson('/api/v1/auth/logout');
        $this->login($admin);
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $code])->assertStatus(422);
    }

    #[Test]
    public function recovery_code_works_exactly_once(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA, attributes: ['mfa_grace_started_at' => now()->subDays(30)]);
        $this->login($admin);
        $secret = $this->postJson('/api/v1/auth/mfa/setup')->json('secret');
        $codes = $this->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->code($secret)])->json('recovery_codes');
        $this->postJson('/api/v1/auth/logout');

        $this->login($admin);
        $this->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => strtolower($codes[0])])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('mfa.recovery_codes_left', 9);
        $this->postJson('/api/v1/auth/logout');

        $this->login($admin);
        $this->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $codes[0]])->assertStatus(422)->assertJsonValidationErrors('recovery_code');
    }

    #[Test]
    public function verification_attempts_are_throttled(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $secret = $this->enableMfa($admin);
        $this->login($admin);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $this->code($secret)])->assertStatus(429);
        $this->assertSame(5, AuditEvent::query()->where('action', 'auth.mfa_failed')->count());
    }

    #[Test]
    public function ordinary_api_traffic_does_not_exhaust_the_mfa_limit(): void
    {
        // Регрессия: безымянные throttle:N,M делили один счётчик на пользователя,
        // и после ~20 обычных запросов ввод кода отвечал 429 (нашлось e2e-проверкой в браузере).
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $secret = $this->enableMfa($admin);
        $this->login($admin);
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $this->code($secret)])->assertOk();

        for ($i = 0; $i < 25; $i++) {
            $this->getJson('/api/v1/companies')->assertOk();
        }

        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $this->code($secret, 1)])->assertOk();
    }

    #[Test]
    public function trusted_device_skips_the_code_until_forgotten(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $secret = $this->enableMfa($admin);
        $this->login($admin);
        $verify = $this->postJson('/api/v1/auth/mfa/verify', ['code' => $this->code($secret), 'trust_device' => true])->assertOk();
        $cookie = $verify->getCookie(MfaService::TRUST_COOKIE);
        $this->assertNotNull($cookie);
        $this->postJson('/api/v1/auth/logout');

        // Тестовый клиент по умолчанию не отправляет cookie с JSON-запросами (браузер — отправляет).
        $this->withCredentials()->withCookie(MfaService::TRUST_COOKIE, $cookie->getValue());
        $this->login($admin);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('mfa.status', 'ok');
        $this->getJson('/api/v1/companies')->assertOk();

        $this->deleteJson('/api/v1/auth/mfa/trusted-devices')->assertOk()->assertJsonPath('forgotten', 1);
        $this->postJson('/api/v1/auth/logout');
        $this->login($admin);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('mfa.status', 'verify_required');
    }

    #[Test]
    public function critical_actions_require_a_recent_code(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $secret = $this->enableMfa($admin);
        $target = User::factory()->homeAt($this->companyA)->create();
        $director = Role::query()->where('code', 'director')->firstOrFail();
        $grant = fn () => $this->postJson("/api/v1/users/{$target->id}/role-scopes", [
            'role_id' => $director->id, 'scope_type' => 'company', 'scope_id' => $this->companyA->id,
        ]);

        $this->login($admin);
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $this->code($secret)])->assertOk();
        $this->travel(16)->minutes();

        $grant()->assertForbidden()->assertJsonPath('code', 'mfa_confirmation_required');
        $this->getJson('/api/v1/companies')->assertOk(); // обычные действия не затронуты

        // travel() не двигает системное время, по которому считается TOTP: берём код следующего шага,
        // иначе сервер справедливо отклонит повтор уже использованного кода.
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $this->code($secret, 1)])->assertOk();
        $grant()->assertCreated();
    }

    #[Test]
    public function admin_resets_mfa_only_within_scope_and_never_for_self(): void
    {
        $adminA = $this->userWithRole('company_admin', $this->companyA);
        $adminB = $this->userWithRole('company_admin', $this->companyB);
        $target = User::factory()->homeAt($this->companyA)->create();
        $this->enableMfa($target);
        DB::table('sessions')->insert(['id' => 'target-session', 'user_id' => $target->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($adminB)->deleteJson("/api/v1/users/{$target->id}/mfa", ['reason' => 'x'])->assertNotFound();
        $this->actingAs($adminA)->deleteJson("/api/v1/users/{$adminA->id}/mfa", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($adminA)->deleteJson("/api/v1/users/{$target->id}/mfa", [])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->actingAs($adminA)->deleteJson("/api/v1/users/{$target->id}/mfa", ['reason' => 'Потерян телефон'])
            ->assertOk()->assertJsonPath('mfa_enabled', false);

        $this->assertNull($target->refresh()->mfa_secret);
        $this->assertDatabaseMissing('sessions', ['id' => 'target-session']);
        $this->assertDatabaseHas('audit_events', ['action' => 'auth.mfa_reset', 'subject_id' => $target->id, 'reason' => 'Потерян телефон']);
    }

    #[Test]
    public function after_reset_a_required_user_must_set_up_mfa_immediately(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $this->enableMfa($admin);
        $this->artisan('avtoklass:mfa-reset', ['login' => $admin->login, '--reason' => 'Тест'])
            ->expectsConfirmation("Сбросить MFA пользователя «{$admin->login}» и завершить его сессии?", 'yes')
            ->assertSuccessful();

        $this->login($admin);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('mfa.status', 'setup_required');
    }

    #[Test]
    public function only_users_without_mandatory_mfa_may_disable_it(): void
    {
        $admin = $this->userWithRole('company_admin', $this->companyA);
        $adminSecret = $this->enableMfa($admin);
        $this->login($admin);
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $this->code($adminSecret)])->assertOk();
        $this->deleteJson('/api/v1/auth/mfa')->assertStatus(422);
        $this->postJson('/api/v1/auth/logout');

        $director = $this->userWithRole('director', $this->companyA);
        $directorSecret = $this->enableMfa($director);
        $this->login($director);
        $this->getJson('/api/v1/auth/me')->assertJsonPath('mfa.required', false);
        $this->postJson('/api/v1/auth/mfa/verify', ['code' => $this->code($directorSecret)])->assertOk();
        $this->deleteJson('/api/v1/auth/mfa')->assertOk()->assertJsonPath('mfa.enabled', false);
    }

    #[Test]
    public function users_without_management_rights_are_not_forced(): void
    {
        $director = $this->userWithRole('director', $this->companyA);
        $this->login($director);
        $this->travel(30)->days();

        $this->getJson('/api/v1/auth/me')->assertJsonPath('mfa.status', 'ok')->assertJsonPath('mfa.required', false);
        $this->getJson('/api/v1/companies')->assertOk();
    }
}
