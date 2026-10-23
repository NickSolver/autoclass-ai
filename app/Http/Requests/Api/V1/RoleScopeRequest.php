<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Identity\ScopeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleScopeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'scope_type' => ['required', Rule::enum(ScopeType::class)],
            'scope_id' => ['required_unless:scope_type,own', 'nullable', 'integer'],
        ];
    }
}
