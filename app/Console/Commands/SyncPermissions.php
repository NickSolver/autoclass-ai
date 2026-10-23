<?php

namespace App\Console\Commands;

use App\Domain\Identity\Services\RoleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('avtoklass:sync-permissions')]
#[Description('Синхронизировать таблицу permissions с реестром прав в коде')]
class SyncPermissions extends Command
{
    public function handle(RoleService $roles): int
    {
        $created = $roles->syncPermissionRegistry();
        $this->info("Реестр прав синхронизирован. Новых прав: {$created}.");

        return self::SUCCESS;
    }
}
