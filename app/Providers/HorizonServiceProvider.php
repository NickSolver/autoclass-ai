<?php

namespace App\Providers;

use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Панель очередей /horizon показывает содержимое задач, поэтому доступна только администратору
 * уровня группы (roles.manage на группе) — во всех окружениях, включая local.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn ($request) => Gate::check('viewHorizon', [$request->user()]));
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null) => $user !== null
            && $user->is_active
            && app(Authorizer::class)->hasGroupLevel($user, Permission::RolesManage));
    }
}
