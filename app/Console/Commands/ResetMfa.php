<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MfaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Аварийный сброс MFA с сервера — например, единственному суперадминистратору (ADR-0002). */
#[Signature('avtoklass:mfa-reset {login : Логин пользователя} {--reason= : Причина (попадает в аудит)}')]
#[Description('Сбросить двухфакторную аутентификацию пользователя')]
class ResetMfa extends Command
{
    public function handle(MfaService $mfa): int
    {
        $user = User::query()->where('login', mb_strtolower((string) $this->argument('login')))->first();
        if ($user === null) {
            $this->error('Пользователь не найден.');

            return self::FAILURE;
        }

        $reason = trim((string) ($this->option('reason') ?: $this->ask('Причина сброса')));
        if ($reason === '') {
            $this->error('Причина обязательна.');

            return self::FAILURE;
        }
        if (! $this->confirm("Сбросить MFA пользователя «{$user->login}» и завершить его сессии?", true)) {
            return self::FAILURE;
        }

        $mfa->reset($user, 'Консоль: '.$reason);
        $this->info('MFA сброшена. При следующем входе пользователь подключит её заново.');

        return self::SUCCESS;
    }
}
