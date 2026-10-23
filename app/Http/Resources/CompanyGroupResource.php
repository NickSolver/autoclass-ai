<?php

namespace App\Http\Resources;

use App\Domain\Organization\Models\CompanyGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompanyGroup */
class CompanyGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->is_active,
            'can' => ['update' => $request->user()->can('update', $this->resource)],
        ];
    }
}
