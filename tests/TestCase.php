<?php

namespace Tests;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\ScopeType;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\CompanyGroup;
use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase {
        refreshTestDatabase as baseRefreshTestDatabase;
    }

    /** Каждому тесту — реестр прав и шаблоны ролей. */
    protected bool $seed = true;

    protected string $seeder = RoleSeeder::class;

    protected CompanyGroup $group;

    protected Company $companyA;

    protected Company $companyB;

    protected Dealership $dealershipA;

    protected Dealership $dealershipB;

    protected Department $salesA;

    protected Department $salesB;

    /**
     * Защита рабочей БД: RefreshDatabase очищает базу, поэтому тесты запускаются
     * только на базе с суффиксом _test. Проверка — до любых миграций.
     */
    protected function refreshTestDatabase(): void
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! str_ends_with($database, '_test')) {
            throw new \RuntimeException("Тесты остановлены: база «{$database}» не тестовая (ожидается суффикс _test).");
        }

        $this->baseRefreshTestDatabase();
    }

    /** Группа с двумя компаниями A и B, в каждой — центр и отдел продаж. */
    protected function buildOrg(): void
    {
        $this->group = CompanyGroup::factory()->create(['code' => 'test-group', 'name' => 'Тестовая группа']);
        $this->companyA = Company::factory()->for($this->group, 'group')->create(['code' => 'a', 'name' => 'Компания A']);
        $this->companyB = Company::factory()->for($this->group, 'group')->create(['code' => 'b', 'name' => 'Компания B']);
        $this->dealershipA = Dealership::factory()->create(['company_id' => $this->companyA->id, 'name' => 'ДЦ A']);
        $this->dealershipB = Dealership::factory()->create(['company_id' => $this->companyB->id, 'name' => 'ДЦ B']);
        $this->salesA = Department::factory()->create(['dealership_id' => $this->dealershipA->id, 'name' => 'Продажи A']);
        $this->salesB = Department::factory()->create(['dealership_id' => $this->dealershipB->id, 'name' => 'Продажи B']);
    }

    /** Пользователь с одной ролью-шаблоном в области. Домашнее подразделение — по умолчанию сама область. */
    protected function userWithRole(
        string $roleCode,
        CompanyGroup|Company|Dealership|Department|null $scope,
        CompanyGroup|Company|Dealership|Department|null $home = null,
        array $attributes = [],
    ): User {
        $home ??= $scope ?? $this->group;
        $user = User::factory()->homeAt($home)->create($attributes);
        $type = match (true) {
            $scope instanceof CompanyGroup => ScopeType::Group,
            $scope instanceof Company => ScopeType::Company,
            $scope instanceof Dealership => ScopeType::Dealership,
            $scope instanceof Department => ScopeType::Department,
            default => ScopeType::Own,
        };
        $user->roleScopes()->create([
            'role_id' => Role::query()->where('code', $roleCode)->firstOrFail()->id,
            'scope_type' => $type,
            'scope_id' => $scope?->id ?? 0,
        ]);

        return $user->refresh();
    }

    protected function superadmin(): User
    {
        return $this->userWithRole('superadmin', $this->group);
    }
}
