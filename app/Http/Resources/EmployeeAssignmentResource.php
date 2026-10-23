<?php

namespace App\Http\Resources;

use App\Domain\Organization\Models\EmployeeAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmployeeAssignment */
class EmployeeAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'company' => ['id' => $this->company_id, 'name' => $this->company->name],
            'dealership' => $this->dealership ? ['id' => $this->dealership->id, 'name' => $this->dealership->name] : null,
            'department' => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null,
            'position' => $this->position,
            'is_primary' => $this->is_primary,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'is_current' => $this->isCurrent(),
        ];
    }
}
