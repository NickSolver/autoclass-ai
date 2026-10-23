<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Organization\Models\Dealership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DealershipRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var ?Dealership $dealership */
        $dealership = $this->route('dealership');
        $companyId = $dealership !== null ? $dealership->company_id : $this->integer('company_id');
        $creating = $dealership === null;

        return [
            'company_id' => $creating ? ['required', 'integer'] : ['prohibited'],
            'code' => [$creating ? 'required' : 'sometimes', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('dealerships', 'code')->where('company_id', $companyId)->ignore($dealership?->id)],
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
