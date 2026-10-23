<?php

namespace App\Domain\Identity\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Access\AccessResolver;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Двухфакторная аутентификация по TOTP (ADR-0002).
 *
 * Состояние сессии: mfa.passed — второй фактор пройден в этой сессии (кодом или доверенным
 * устройством); mfa.confirmed_at — время последнего ввода кода (для повторного подтверждения
 * критичных действий; доверенное устройство его не обновляет).
 */
final class MfaService
{
    public const STATUS_OK = 'ok';

    public const STATUS_VERIFY_REQUIRED = 'verify_required';

    public const STATUS_SETUP_REQUIRED = 'setup_required';

    public const STATUS_SETUP_RECOMMENDED = 'setup_recommended';

    private const SESSION_PASSED = 'mfa.passed';

    private const SESSION_CONFIRMED_AT = 'mfa.confirmed_at';

    private const SESSION_PENDING_SECRET = 'mfa.pending_secret';

    public const TRUST_COOKIE = 'avtoklass_mfa_trust';

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Google2FA $totp,
        private readonly AccessResolver $resolver,
        private readonly SessionService $sessions,
        private readonly AuditLogger $audit,
    ) {}

    public function isEnabled(User $user): bool
    {
        return $user->mfa_enabled_at !== null && $user->mfa_secret !== null;
    }

    /** MFA обязательна, если у пользователя есть хотя бы одно право управления. */
    public function isRequired(User $user): bool
    {
        foreach (Permission::requiringMfa() as $permission) {
            if ($this->resolver->has($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function graceUntil(User $user): ?Carbon
    {
        if ($this->isEnabled($user) || ! $this->isRequired($user)) {
            return null;
        }
        $start = $user->mfa_grace_started_at ?? now();

        return $start->copy()->addDays((int) config('auth.mfa.grace_days', 7));
    }

    public function status(User $user, Session $session): string
    {
        if ($this->isEnabled($user)) {
            return $session->get(self::SESSION_PASSED) === true ? self::STATUS_OK : self::STATUS_VERIFY_REQUIRED;
        }
        if (! $this->isRequired($user)) {
            return self::STATUS_OK;
        }

        return $this->graceUntil($user)?->isPast() ? self::STATUS_SETUP_REQUIRED : self::STATUS_SETUP_RECOMMENDED;
    }

    /** @return array<string, mixed> */
    public function summary(User $user, Session $session): array
    {
        return [
            'status' => $this->status($user, $session),
            'enabled' => $this->isEnabled($user),
            'required' => $this->isRequired($user),
            'grace_until' => $this->graceUntil($user)?->toIso8601String(),
            'recovery_codes_left' => $this->isEnabled($user)
                ? DB::table('mfa_recovery_codes')->where('user_id', $user->id)->whereNull('used_at')->count()
                : 0,
            'trusted_devices' => DB::table('mfa_trusted_devices')->where('user_id', $user->id)->where('expires_at', '>', now())->count(),
        ];
    }

    /**
     * Вызывается после проверки пароля: запускает льготный период и учитывает доверенное устройство.
     *
     * @return 'not_enabled'|'trusted_device'|'pending'
     */
    public function onLogin(User $user, Request $request): string
    {
        if (! $this->isEnabled($user) && $user->mfa_grace_started_at === null && $this->isRequired($user)) {
            $user->forceFill(['mfa_grace_started_at' => now()])->saveQuietly();
        }
        if (! $this->isEnabled($user)) {
            return 'not_enabled';
        }
        if ($this->isTrustedDevice($user, $request)) {
            $request->session()->put(self::SESSION_PASSED, true);

            return 'trusted_device';
        }
        $request->session()->put(self::SESSION_PASSED, false);

        return 'pending';
    }

    /**
     * Начало подключения: новый секрет хранится в сессии (зашифрованным) до подтверждения кодом.
     *
     * @return array{secret: string, otpauth_url: string, qr_svg: string}
     */
    public function beginSetup(User $user, Session $session): array
    {
        if ($this->isEnabled($user)) {
            throw ValidationException::withMessages(['mfa' => 'Двухфакторная аутентификация уже подключена.']);
        }

        $secret = $this->totp->generateSecretKey(32);
        $session->put(self::SESSION_PENDING_SECRET, Crypt::encryptString($secret));
        $url = $this->totp->getQRCodeUrl((string) config('auth.mfa.issuer'), $user->login, $secret);

        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($url);

        return ['secret' => $secret, 'otpauth_url' => $url, 'qr_svg' => $svg];
    }

    /**
     * Подтверждение подключения кодом из приложения. Возвращает резервные коды (показываются один раз).
     *
     * @return list<string>
     */
    public function confirmSetup(User $user, Session $session, string $code): array
    {
        $encrypted = $session->get(self::SESSION_PENDING_SECRET);
        if (! is_string($encrypted) || $this->isEnabled($user)) {
            throw ValidationException::withMessages(['code' => 'Начните подключение заново.']);
        }
        $secret = Crypt::decryptString($encrypted);

        $this->throttle($user);
        $timestep = $this->totp->verifyKeyNewer($secret, $this->normalizeCode($code), 0);
        if ($timestep === false) {
            $this->failAttempt($user, 'setup');
        }

        $codes = DB::transaction(function () use ($user, $secret, $timestep) {
            $user->forceFill([
                'mfa_secret' => $secret,
                'mfa_enabled_at' => now(),
                'mfa_last_timestep' => $timestep,
            ])->saveQuietly();

            return $this->replaceRecoveryCodes($user);
        });

        $session->forget(self::SESSION_PENDING_SECRET);
        $this->markConfirmed($session);
        RateLimiter::clear($this->throttleKey($user));
        $this->audit->record('auth.mfa_enabled', $user);

        return $codes;
    }

    /** Проверка второго фактора: код из приложения либо одноразовый резервный код. */
    public function verify(User $user, Session $session, ?string $code, ?string $recoveryCode): void
    {
        if (! $this->isEnabled($user)) {
            throw ValidationException::withMessages(['code' => 'Двухфакторная аутентификация не подключена.']);
        }
        $this->throttle($user);

        if ($recoveryCode !== null && $recoveryCode !== '') {
            $used = DB::table('mfa_recovery_codes')
                ->where('user_id', $user->id)
                ->where('code_hash', $this->hashRecoveryCode($recoveryCode))
                ->whereNull('used_at')
                ->update(['used_at' => now()]);
            if ($used === 0) {
                $this->failAttempt($user, 'recovery_code', 'recovery_code');
            }
            $this->audit->record('auth.mfa_recovery_code_used', $user, ['left' => $this->summary($user, $session)['recovery_codes_left']]);
        } else {
            $timestep = $this->totp->verifyKeyNewer((string) $user->mfa_secret, $this->normalizeCode((string) $code), $user->mfa_last_timestep ?? 0);
            if ($timestep === false) {
                $this->failAttempt($user, 'code');
            }
            if (is_int($timestep)) {
                $user->forceFill(['mfa_last_timestep' => $timestep])->saveQuietly();
            }
        }

        RateLimiter::clear($this->throttleKey($user));
        $this->markConfirmed($session);
    }

    public function isRecentlyConfirmed(User $user, Session $session): bool
    {
        // Без подключённой MFA (льготный период) повторное подтверждение невозможно — не блокируем.
        if (! $this->isEnabled($user)) {
            return true;
        }
        $at = $session->get(self::SESSION_CONFIRMED_AT);

        return is_int($at) && $at >= now()->subMinutes((int) config('auth.mfa.step_up_minutes', 15))->getTimestamp();
    }

    /** Запомнить устройство: случайный токен в cookie, в БД — только его хеш. */
    public function trustDevice(User $user, Request $request): SymfonyCookie
    {
        $token = Str::random(64);
        $days = (int) config('auth.mfa.trust_days', 30);
        DB::table('mfa_trusted_devices')->insert([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 497),
            'expires_at' => now()->addDays($days),
            'created_at' => now(),
        ]);
        $this->audit->record('auth.mfa_device_trusted', $user);

        return Cookie::make(self::TRUST_COOKIE, $user->id.'|'.$token, $days * 24 * 60, httpOnly: true, sameSite: 'lax');
    }

    public function forgetDevices(User $user): int
    {
        $count = DB::table('mfa_trusted_devices')->where('user_id', $user->id)->delete();
        if ($count > 0) {
            $this->audit->record('auth.mfa_devices_forgotten', $user, ['count' => $count]);
        }

        return $count;
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->replaceRecoveryCodes($user);
        $this->audit->record('auth.mfa_recovery_codes_regenerated', $user);

        return $codes;
    }

    /** Отключить свою MFA можно, только если она для пользователя не обязательна. */
    public function disable(User $user): void
    {
        if ($this->isRequired($user)) {
            throw ValidationException::withMessages(['mfa' => 'Для вашей роли двухфакторная аутентификация обязательна.']);
        }
        $this->clear($user);
        $this->audit->record('auth.mfa_disabled', $user);
    }

    /**
     * Сброс администратором (потерян телефон и резервные коды). Сессии пользователя завершаются;
     * подключить MFA заново потребуется при следующем входе — льготный период не возобновляется.
     */
    public function reset(User $target, string $reason): void
    {
        $this->clear($target);
        $target->forceFill(['mfa_grace_started_at' => now()->subDays((int) config('auth.mfa.grace_days', 7) + 1)])->saveQuietly();
        $this->sessions->terminateAll($target, audit: false);
        $this->audit->record('auth.mfa_reset', $target, reason: $reason);
    }

    private function clear(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->forceFill(['mfa_secret' => null, 'mfa_enabled_at' => null, 'mfa_last_timestep' => null])->saveQuietly();
            DB::table('mfa_recovery_codes')->where('user_id', $user->id)->delete();
            DB::table('mfa_trusted_devices')->where('user_id', $user->id)->delete();
        });
    }

    private function isTrustedDevice(User $user, Request $request): bool
    {
        $value = (string) $request->cookie(self::TRUST_COOKIE, '');
        [$userId, $token] = array_pad(explode('|', $value, 2), 2, '');
        if ((int) $userId !== $user->id || $token === '') {
            return false;
        }

        return DB::table('mfa_trusted_devices')
            ->where('user_id', $user->id)
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->update(['last_used_at' => now()]) > 0;
    }

    /** @return list<string> */
    private function replaceRecoveryCodes(User $user): array
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $codes = [];
        for ($i = 0; $i < (int) config('auth.mfa.recovery_codes', 10); $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }

        DB::table('mfa_recovery_codes')->where('user_id', $user->id)->delete();
        DB::table('mfa_recovery_codes')->insert(array_map(fn (string $c) => [
            'user_id' => $user->id,
            'code_hash' => $this->hashRecoveryCode($c),
            'created_at' => now(),
        ], $codes));

        return $codes;
    }

    private function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }

    private function normalizeCode(string $code): string
    {
        return (string) preg_replace('/\D/', '', $code);
    }

    private function markConfirmed(Session $session): void
    {
        $session->put(self::SESSION_PASSED, true);
        $session->put(self::SESSION_CONFIRMED_AT, now()->getTimestamp());
    }

    private function throttleKey(User $user): string
    {
        return 'mfa:'.$user->id;
    }

    private function throttle(User $user): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey($user), self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'code' => 'Слишком много неверных кодов. Повторите через '.RateLimiter::availableIn($this->throttleKey($user)).' сек.',
            ])->status(429);
        }
    }

    private function failAttempt(User $user, string $kind, string $field = 'code'): never
    {
        RateLimiter::hit($this->throttleKey($user), 60);
        $this->audit->record('auth.mfa_failed', $user, ['kind' => $kind]);

        throw ValidationException::withMessages([
            $field => $field === 'recovery_code' ? 'Резервный код неверен или уже использован.' : 'Неверный код. Проверьте время на телефоне и попробуйте ещё раз.',
        ]);
    }
}
