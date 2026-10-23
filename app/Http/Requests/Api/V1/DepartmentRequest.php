<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Organization\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DepartmentRequest extends FormRequest
{
    public const KINDS = ['sales', 'service', 'parts', 'call_center', 'marketing', 'other'];

    public function rules(): array
    {
        /** @var ?Department $department */
        $department = $this->route('department');
        $companyId = $department !== null ? $department->company_id : $this->integer('company_id');
        $creating = $department === null;

        return [
            'company_id' => $creating ? ['required', 'integer'] : ['prohibited'],
            'dealership_id' => $creating ? ['nullable', 'integer'] : ['prohibited'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('departments', 'code')->where('company_id', $companyId)->ignore($department?->id)],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'kind' => ['sometimes', 'nullable', Rule::in(self::KINDS)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
