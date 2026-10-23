<?php

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\PasswordResetLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    private const NEW_PASSWORD = 'Brand-New-Pass-2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildOrg();
        Notification::fake();
    }

    private function requestToken(User $user): string
    {
        $token = null;
        $this->postJson('/api/v1/auth/password-reset/request', ['login' => $user->login])->assertStatus(202);
        Notification::assertSentTo($user, PasswordResetLink::class, function (PasswordResetLink $n) use (&$token, $user) {
            preg_match('/token=([A-Za-z0-9]+)/', $n->toMail($user)->actionUrl, $m);
            $token = $m[1] ?? null;

            return true;
        });

        return (string) $token;
    }

    #[Test]
    public function reset_link_sets_a_new_password_once_and_terminates_sessions(): void
    {
        $user = User::factory()->homeAt($this->companyA)->create(['email' => 'user@example.test']);
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $token = $this->requestToken($user);
        $this->assertDatabaseMissing('password_reset_tokens', ['token_hash' => $token]); // хранится только хеш

        $payload = ['token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD];
        $this->postJson('/api/v1/auth/password-reset/confirm', $payload)->assertNoContent();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->refresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->postJson('/api/v1/auth/password-reset/confirm', $payload)->assertStatus(422)->assertJsonValidationErrors('token');
    }

    #[Test]
    public function response_does_not_reveal_whether_the_account_exists(): void
    {
        $user = User::factory()->homeAt($this->companyA)->create(['email' => 'user@example.test']);

        $known = $this->postJson('/api/v1/auth/password-reset/request', ['login' => $user->login])->assertStatus(202)->json();
        $unknown = $this->postJson('/api/v1/auth/password-reset/request', ['login' => 'no-such-user'])->assertStatus(202)->json();

        $this->assertSame($known, $unknown);
    }

    #[Test]
    public function expired_token_is_rejected(): void
    {
        $user = User::factory()->homeAt($this->companyA)->create(['email' => 'user@example.test']);
        $token = $this->requestToken($user);
        $this->travel(31)->minutes();

        $this->postJson('/api/v1/auth/password-reset/confirm', [
            'token' => $token, 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('token');
    }

    #[Test]
    public function disabled_user_or_user_without_email_gets_no_link(): void
    {
        $disabled = User::factory()->homeAt($this->companyA)->inactive()->create(['email' => 'off@example.test']);
        $noEmail = User::factory()->homeAt($this->companyA)->create(['email' => null]);

        $this->postJson('/api/v1/auth/password-reset/request', ['login' => $disabled->login])->assertStatus(202);
        $this->postJson('/api/v1/auth/password-reset/request', ['login' => $noEmail->login])->assertStatus(202);

        Notification::assertNothingSent();
    }
}
