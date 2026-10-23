<?php

namespace App\Http\Resources;

use App\Domain\Organization\Models\Dealership;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Dealership */
class DealershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'name' => $this->company->name]),
            'code' => $this->code,
            'name' => $this->name,
            'city' => $this->city,
            'address' => $this->address,
            'is_active' => $this->is_active,
            'departments_count' => $this->whenCounted('departments'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => ['update' => $request->user()->can('update', $this->resource)],
        ];
    }
}
