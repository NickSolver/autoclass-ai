<?php

namespace App\Domain\CallIntake\Policies;

use App\Domain\CallIntake\Models\Call;
use App\Domain\Identity\Access\Authorizer;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;

final class CallPolicy
{
    public function __construct(private readonly Authorizer $authorizer) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::CallsView);
    }

    public function view(User $user, Call $call): bool
    {
        return $call->isVisibleTo($user, Permission::CallsView);
    }

    public function upload(User $user): bool
    {
        return $user->hasPermission(Permission::CallsUpload);
    }

    /** Исправление подразделения/сотрудника/метаданных — право загрузки в подразделении звонка. */
    public function update(User $user, Call $call): bool
    {
        return $this->authorizer->covers($user, Permission::CallsUpload, $call->orgPath());
    }

    public function reprocess(User $user, Call $call): bool
    {
        return $this->authorizer->covers($user, Permission::CallsReprocess, $call->orgPath());
    }

    public function exclude(User $user, Call $call): bool
    {
        return $this->authorizer->covers($user, Permission::CallsDelete, $call->orgPath());
    }

    public function play(User $user, Call $call): bool
    {
        return $call->isVisibleTo($user, Permission::AudioPlay);
    }

    public function download(User $user, Call $call): bool
    {
        return $call->isVisibleTo($user, Permission::AudioDownload);
    }
}
