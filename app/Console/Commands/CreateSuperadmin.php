<?php

namespace App\Console\Commands;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleTemplates;
use App\Domain\Identity\ScopeType;
use App\Domain\Identity\Services\RoleManagerGuard;
use App\Domain\Identity\Services\RoleService;
use App\Domain\Organization\Models\CompanyGroup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Первичная настройка: создаёт группу компаний (если её нет), шаблоны ролей и первого
 * суперадминистратора. Пароль вводится интерактивно и не попадает в историю shell.
 * Если активный суперадминистратор уже есть, команда отказывает без --additional.
 */
#[Signature('avtoklass:create-superadmin
    {--login= : Логин}
    {--name= : Отображаемое имя}
    {--email= : E-mail (для восстановления пароля)}
    {--group-code= : Код группы компаний (создаётся, если не существует)}
    {--group-name= : Название группы компаний при создании}
    {--additional : Разрешить создание ещё одного суперадминистратора}')]
#[Description('Создать первого суперадминистратора портала (bootstrap)')]
class CreateSuperadmin extends Command
{
    public function handle(RoleService $roles, RoleManagerGuard $guard, AuditLogger $audit): int
    {
        $roles->syncPermissionRegistry();

        if ($guard->hasActiveRoleManager() && ! $this->option('additional')) {
            $this->error('Активный суперадминистратор уже существует. Для создания ещё одного используйте --additional.');

            return self::FAILURE;
        }

        $login = mb_strtolower((string) ($this->option('login') ?: $this->ask('Логин')));
        $name = (string) ($this->option('name') ?: $this->ask('Отображаемое имя'));
        $email = $this->option('email') ?: $this->ask('E-mail (можно оставить пустым)');
        $password = (string) $this->secret('Пароль (не менее 12 символов, буквы разного регистра и цифры)');
        $confirmation = (string) $this->secret('Повторите пароль');

        $validator = Validator::make(
            ['login' => $login, 'name' => $name, 'email' => $email ?: null, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'login' => ['required', 'min:3', 'max:64', 'regex:/^[a-z0-9._-]+$/', 'unique:users,login'],
                'name' => ['required', 'max:255'],
                'email' => ['nullable', 'email', 'unique:users,email'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($roles, $audit, $login, $name, $email, $password) {
            $group = $this->resolveGroup();

            $role = Role::query()->where('code', RoleTemplates::SUPERADMIN)->first();
            if ($role === null) {
                foreach (RoleTemplates::all() as $code => $template) {
                    if (! Role::query()->where('code', $code)->exists()) {
                        $roles->create(['code' => $code, ...$template], isSystem: true);
                    }
                }
                $role = Role::query()->where('code', RoleTemplates::SUPERADMIN)->firstOrFail();
            }

            $user = User::create([
                'login' => $login,
                'name' => $name,
                'email' => $email ? mb_strtolower($email) : null,
                'password' => $password,
                'must_change_password' => false,
                'password_changed_at' => now(),
                'group_id' => $group->id,
            ]);
            $user->roleScopes()->create(['role_id' => $role->id, 'scope_type' => ScopeType::Group, 'scope_id' => $group->id]);
            $audit->record('user.bootstrap_superadmin', $user, reason: 'Создан консольной командой avtoklass:create-superadmin');
        });

        $this->info("Суперадминистратор «{$login}» создан.");

        return self::SUCCESS;
    }

    private function resolveGroup(): CompanyGroup
    {
        $code = $this->option('group-code');
        if ($code === null && CompanyGroup::query()->count() === 1) {
            return CompanyGroup::query()->firstOrFail();
        }
        $code ??= (string) $this->ask('Код группы компаний', 'avtoklass');

        return CompanyGroup::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $this->option('group-name') ?: (string) $this->ask('Название группы компаний', 'ГК Автокласс')],
        );
    }
}
