<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Permission;
use App\Domain\Identity\ScopeType;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Реестр прав и типов областей — для матрицы прав в интерфейсе. */
class PermissionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $this->authorize('viewAny', Role::class);

        return response()->json([
            'data' => array_map(fn (Permission $p) => [
                'code' => $p->value,
                'category' => $p->category(),
                'name' => $p->label(),
                'module_available' => $p->isModuleAvailable(),
            ], Permission::cases()),
            'scope_types' => array_map(fn (ScopeType $t) => ['code' => $t->value, 'name' => $t->label()], ScopeType::cases()),
        ]);
    }
}
