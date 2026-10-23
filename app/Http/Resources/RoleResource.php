<?php

namespace App\Http\Resources;

use App\Domain\Identity\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Role */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'allowed_scope_types' => $this->allowed_scope_types,
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('code')->sort()->values()),
            'assignments_count' => $this->whenCounted('assignments'),
            'lock_version' => $this->lock_version,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ],
        ];
    }
}
