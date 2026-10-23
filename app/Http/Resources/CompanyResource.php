<?php

namespace App\Http\Resources;

use App\Domain\Organization\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Company */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'code' => $this->code,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'is_active' => $this->is_active,
            'dealerships_count' => $this->whenCounted('dealerships'),
            'departments_count' => $this->whenCounted('departments'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => ['update' => $request->user()->can('update', $this->resource)],
        ];
    }
}
