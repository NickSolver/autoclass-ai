<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\ScopeType;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\CompanyGroup;
use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Синтетические демоданные: вымышленные компании, подразделения, сотрудники и пользователи.
 * Реальных персональных данных нет; e-mail — в зарезервированном домене .invalid.
 * Пароль всех демо-пользователей — DemoSeeder::PASSWORD (см. README). В production не запускается.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo-Password-2026';

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Демоданные запрещено загружать в production.');
        }
        if (User::query()->where('login', 'demo.admin')->exists()) {
            $this->command?->warn('Демоданные уже загружены — пропуск.');

            return;
        }

        $group = CompanyGroup::query()->firstOrCreate(['code' => 'demo-group'], ['name' => 'Демо-группа компаний']);

        $structure = [
            'north' => ['name' => 'Демо Авто Север', 'dealerships' => ['nord-1' => 'ДЦ Северный-1', 'nord-2' => 'ДЦ Северный-2']],
            'south' => ['name' => 'Демо Авто Юг', 'dealerships' => ['south-1' => 'ДЦ Южный-1']],
        ];
        $departments = ['sales' => 'Отдел продаж', 'service' => 'Сервис'];

        $companies = [];
        $deps = [];
        foreach ($structure as $code => $c) {
            $company = $companies[$code] = Company::create(['group_id' => $group->id, 'code' => $code, 'name' => $c['name']]);
            foreach ($c['dealerships'] as $dCode => $dName) {
                $dealership = Dealership::create([
                    'group_id' => $group->id, 'company_id' => $company->id, 'code' => $dCode, 'name' => $dName, 'city' => 'Демоград',
                ]);
                foreach ($departments as $kind => $depName) {
                    $deps["{$dCode}-{$kind}"] = Department::create([
                        'group_id' => $group->id, 'company_id' => $company->id, 'dealership_id' => $dealership->id,
                        'code' => "{$dCode}-{$kind}", 'name' => $depName, 'kind' => $kind,
                    ]);
                }
            }
            Department::create([
                'group_id' => $group->id, 'company_id' => $company->id, 'dealership_id' => null,
                'code' => "{$code}-cc", 'name' => 'Колл-центр', 'kind' => 'call_center',
            ]);
        }

        $employees = [];
        $people = [
            ['Иванов', 'Пётр', 'nord-1-sales', 'Менеджер по продажам', '101'],
            ['Петрова', 'Анна', 'nord-1-sales', 'Руководитель отдела продаж', '100'],
            ['Сидоров', 'Олег', 'nord-1-service', 'Мастер-приёмщик', '201'],
            ['Кузнецова', 'Мария', 'nord-2-sales', 'Менеджер по продажам', '301'],
            ['Смирнов', 'Илья', 'south-1-sales', 'Менеджер по продажам', '401'],
            ['Волкова', 'Елена', 'south-1-service', 'Мастер-приёмщик', '501'],
        ];
        foreach ($people as $i => [$last, $first, $depKey, $position, $ext]) {
            $dep = $deps[$depKey];
            $employee = $employees[$depKey.'-'.$i] = Employee::create([
                'group_id' => $group->id, 'company_id' => $dep->company_id,
                'last_name' => $last, 'first_name' => $first,
                'personnel_number' => sprintf('DEMO-%04d', $i + 1),
                'email' => 'employee'.($i + 1).'@demo.invalid',
                'phone_extension' => $ext,
            ]);
            $employee->assignments()->create([
                ...$dep->orgPath()->toColumns(),
                'position' => $position, 'is_primary' => true, 'starts_on' => now()->subYear()->toDateString(),
            ]);
        }

        $role = fn (string $code) => Role::query()->where('code', $code)->firstOrFail();

        $this->user('demo.admin', 'Демо Суперадминистратор', $group, [['superadmin', ScopeType::Group, $group->id]], $role);
        $this->user('demo.north.admin', 'Демо Администратор «Север»', $companies['north'], [['company_admin', ScopeType::Company, $companies['north']->id]], $role);
        $this->user('demo.south.admin', 'Демо Администратор «Юг»', $companies['south'], [['company_admin', ScopeType::Company, $companies['south']->id]], $role);
        $this->user('demo.north.director', 'Демо Директор «Север»', $companies['north'], [['director', ScopeType::Company, $companies['north']->id]], $role);
        $this->user('demo.qc', 'Демо Контролёр качества', $group, [['quality_controller', ScopeType::Group, $group->id]], $role);
        $this->user('demo.north.rop', 'Петрова Анна (демо)', $deps['nord-1-sales'], [['head_of_department', ScopeType::Department, $deps['nord-1-sales']->id]], $role,
            employee: $employees['nord-1-sales-1']);
        $this->user('demo.north.manager', 'Иванов Пётр (демо)', $deps['nord-1-sales'], [['employee', ScopeType::Own, 0]], $role,
            employee: $employees['nord-1-sales-0']);

        $this->command?->info('Демоданные загружены. Пароль демо-пользователей: '.self::PASSWORD);
    }

    /**
     * @param  list<array{0: string, 1: ScopeType, 2: int}>  $grants
     * @param  callable(string): Role  $role
     */
    private function user(string $login, string $name, CompanyGroup|Company|Department $home, array $grants, callable $role, ?Employee $employee = null): void
    {
        $user = User::create([
            'login' => $login,
            'name' => $name,
            'email' => $login.'@demo.invalid',
            'password' => self::PASSWORD,
            'must_change_password' => false,
            'password_changed_at' => now(),
            'employee_id' => $employee?->id,
            ...$home->orgPath()->toColumns(),
        ]);
        foreach ($grants as [$code, $type, $id]) {
            $user->roleScopes()->create(['role_id' => $role($code)->id, 'scope_type' => $type, 'scope_id' => $id]);
        }
    }
}
