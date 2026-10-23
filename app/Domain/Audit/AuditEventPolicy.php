<?php

namespace App\Domain\Audit;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Permission;

final class AuditEventPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::AuditView);
    }

    public function view(User $actor, AuditEvent $event): bool
    {
        return $event->isVisibleTo($actor, Permission::AuditView);
    }
}
