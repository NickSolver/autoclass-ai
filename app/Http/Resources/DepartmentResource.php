<?php

namespace App\Http\Resources;

use App\Domain\Organization\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Department */
class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'company_id' => $this->company_id,
            'dealership_id' => $this->dealership_id,
            'company' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'name' => $this->company->name]),
            'dealership' => $this->whenLoaded('dealership', fn () => $this->dealership
                ? ['id' => $this->dealership->id, 'name' => $this->dealership->name]
                : null),
            'code' => $this->code,
            'name' => $this->name,
            'kind' => $this->kind,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => ['update' => $request->user()->can('update', $this->resource)],
        ];
    }
}
