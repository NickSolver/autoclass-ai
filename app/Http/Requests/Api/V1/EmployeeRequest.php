<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Organization\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EmployeeRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var ?Employee $employee */
        $employee = $this->route('employee');
        $companyId = $employee !== null ? $employee->company_id : $this->integer('company_id');
        $creating = $employee === null;
        $req = $creating ? 'required' : 'sometimes';

        return [
            'company_id' => $creating ? ['required', 'integer'] : ['prohibited'],
            'last_name' => [$req, 'string', 'max:100'],
            'first_name' => [$req, 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'personnel_number' => ['sometimes', 'nullable', 'string', 'max:64',
                Rule::unique('employees', 'personnel_number')->where('company_id', $companyId)->ignore($employee?->id)],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'work_phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^[0-9+()\s-]+$/'],
            'phone_extension' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^[0-9]+$/'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
