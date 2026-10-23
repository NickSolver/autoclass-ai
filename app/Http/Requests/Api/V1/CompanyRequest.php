<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Organization\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var ?Company $company */
        $company = $this->route('company');
        $groupId = $company !== null ? $company->group_id : $this->integer('group_id');
        $creating = $company === null;

        return [
            // Родитель неизменяем после создания (ADR-0001).
            'group_id' => $creating ? ['required', 'integer'] : ['prohibited'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('companies', 'code')->where('group_id', $groupId)->ignore($company?->id)],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
