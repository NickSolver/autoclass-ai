<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\PasswordResetLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Сброс пароля по одноразовой ссылке. В БД хранится только SHA-256 токена;
 * новый запрос заменяет прежний токен; токен удаляется после использования.
 */
final class PasswordResetService
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /** Ответ вызывающему всегда одинаков — по нему нельзя понять, существует ли учётная запись. */
    public function request(string $loginOrEmail): void
    {
        $needle = mb_strtolower(trim($loginOrEmail));
        $user = User::query()
            ->where('login', $needle)
            ->orWhere('email', $needle)
            ->first();

        if ($user === null || ! $user->is_active || $user->email === null) {
            $this->audit->record('auth.password_reset_ignored', $user, ['reason' => $user === null ? 'unknown' : 'inactive_or_no_email']);

            return;
        }

        $token = Str::random(64);
        DB::table('password_reset_tokens')->upsert(
            [['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'created_at' => now()]],
            ['user_id'],
            ['token_hash', 'created_at'],
        );

        $user->notify(new PasswordResetLink($token, $this->expiresInMinutes()));
        $this->audit->record('auth.password_reset_requested', $user, actor: $user);
    }

    public function reset(string $token, string $password): User
    {
        $row = DB::table('password_reset_tokens')->where('token_hash', hash('sha256', $token))->first();
        $invalid = ValidationException::withMessages(['token' => 'Ссылка недействительна или устарела. Запросите новую.']);

        if ($row === null) {
            throw $invalid;
        }
        if (now()->diffInMinutes($row->created_at, true) > $this->expiresInMinutes()) {
            DB::table('password_reset_tokens')->where('user_id', $row->user_id)->delete();
            throw $invalid;
        }

        $user = User::query()->find($row->user_id);
        if ($user === null || ! $user->is_active) {
            throw $invalid;
        }

        DB::transaction(function () use ($user, $password) {
            DB::table('password_reset_tokens')->where('user_id', $user->id)->delete();
            $user->forceFill([
                'password' => $password,
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->saveQuietly();
        });

        $this->sessions->terminateAll($user, audit: false);
        $this->audit->record('auth.password_reset_completed', $user, actor: $user);

        return $user;
    }

    private function expiresInMinutes(): int
    {
        return (int) config('auth.password_reset_expire', 30);
    }
}
