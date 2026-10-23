<?php

namespace App\Http\Resources;

use App\Domain\Identity\Permission;
use App\Domain\Organization\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Employee */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();

        return [
            'id' => $this->id,
            'group_id' => $this->group_id,
            'company_id' => $this->company_id,
            'company' => $this->whenLoaded('company', fn () => ['id' => $this->company->id, 'name' => $this->company->name]),
            'full_name' => $this->fullName(),
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'personnel_number' => $this->personnel_number,
            'email' => $this->email,
            'work_phone' => $this->work_phone,
            'phone_extension' => $this->phone_extension,
            'is_active' => $this->is_active,
            'assignments' => EmployeeAssignmentResource::collection($this->whenLoaded('assignments')),
            // Связанная учётная запись раскрывается только тем, кто видит этого пользователя.
            'user' => $this->whenLoaded('user', fn () => $this->user !== null && $this->user->isVisibleTo($actor, Permission::UsersView)
                ? ['id' => $this->user->id, 'login' => $this->user->login]
                : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => ['update' => $actor->can('update', $this->resource)],
        ];
    }
}
