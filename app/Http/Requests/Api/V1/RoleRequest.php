<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Identity\Permission;
use App\Domain\Identity\ScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->route('role') === null;
        $req = $creating ? 'required' : 'sometimes';

        return [
            'code' => $creating ? ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/', 'unique:roles,code'] : ['prohibited'],
            'name' => [$req, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'allowed_scope_types' => [$req, 'array', 'min:1'],
            'allowed_scope_types.*' => ['distinct', Rule::enum(ScopeType::class)],
            'permissions' => [$req, 'array'],
            'permissions.*' => ['distinct', Rule::enum(Permission::class)],
            'lock_version' => $creating ? ['prohibited'] : ['required', 'integer'],
        ];
    }
}
