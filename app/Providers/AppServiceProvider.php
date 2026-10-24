<?php

namespace App\Providers;

use App\Domain\Audit\AuditEventPolicy;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\CallIntake\Models\Call;
use App\Domain\CallIntake\Policies\CallPolicy;
use App\Domain\Identity\Access\AccessResolver;
use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use App\Domain\Identity\Policies\RolePolicy;
use App\Domain\Identity\Policies\UserPolicy;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\CompanyGroup;
use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use App\Domain\Organization\Policies\EmployeePolicy;
use App\Domain\Organization\Policies\OrgUnitPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AccessResolver::class);
    }

    public function boot(): void
    {
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        JsonResource::withoutWrapping();

        Password::defaults(fn () => Password::min(12)->letters()->mixedCase()->numbers());

        // Именованные ограничители: у каждого свой счётчик. Безымянные throttle:N,M делят
        // один счётчик на пользователя, и общий лимит API съедал лимит ввода кодов MFA.
        $byUserOrIp = fn (Request $r) => $r->user()?->getAuthIdentifier() ?? $r->ip();
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(300)->by('api:'.$byUserOrIp($r)));
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(30)->by('login:'.$r->ip()));
        RateLimiter::for('password-reset', fn (Request $r) => Limit::perMinute(5)->by('pwreset:'.$r->ip()));
        RateLimiter::for('password-reset-confirm', fn (Request $r) => Limit::perMinute(10)->by('pwreset-confirm:'.$r->ip()));
        RateLimiter::for('password-change', fn (Request $r) => Limit::perMinute(10)->by('pwchange:'.$byUserOrIp($r)));
        // Запросы к эндпоинтам MFA; неверные коды дополнительно ограничены в MfaService (5 в минуту).
        // Загрузка записей: пакет до calls.max_files_per_batch файлов, с запасом на повторы.
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(120)->by('uploads:'.$byUserOrIp($r)));
        RateLimiter::for('mfa', fn (Request $r) => Limit::perMinute(30)->by('mfa:'.$byUserOrIp($r)));
        RateLimiter::for('source-webhooks', fn (Request $r) => Limit::perMinute(120)->by('srcwh:'.$r->ip()));

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(CompanyGroup::class, OrgUnitPolicy::class);
        Gate::policy(Company::class, OrgUnitPolicy::class);
        Gate::policy(Dealership::class, OrgUnitPolicy::class);
        Gate::policy(Department::class, OrgUnitPolicy::class);
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(AuditEvent::class, AuditEventPolicy::class);
        Gate::policy(Call::class, CallPolicy::class);
        // Провайдеры ИИ глобальны: управление и журнал — только с правом на уровне группы.
        Gate::define('ai.manage', fn (User $u) => app(Authorizer::class)->hasGroupLevel($u, Permission::AiManage));
        Gate::define('ai.logs', fn (User $u) => app(Authorizer::class)->hasGroupLevel($u, Permission::AiLogsView));

    }
}
