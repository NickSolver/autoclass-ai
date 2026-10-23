<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Organization\Models\EmployeeAssignment;
use Illuminate\Foundation\Http\FormRequest;

class EmployeeAssignmentRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var ?EmployeeAssignment $assignment */
        $assignment = $this->route('assignment');
        $creating = $assignment === null;

        return [
            // Подразделение назначения неизменяемо: при переводе закрывается старое и создаётся новое.
            'company_id' => $creating ? ['required_without_all:dealership_id,department_id', 'nullable', 'integer'] : ['prohibited'],
            'dealership_id' => $creating ? ['nullable', 'integer'] : ['prohibited'],
            'department_id' => $creating ? ['nullable', 'integer'] : ['prohibited'],
            'position' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_primary' => ['sometimes', 'boolean'],
            'starts_on' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d',
                'after_or_equal:'.($this->input('starts_on') ?? $assignment?->starts_on?->toDateString() ?? '1900-01-01')],
        ];
    }
}
