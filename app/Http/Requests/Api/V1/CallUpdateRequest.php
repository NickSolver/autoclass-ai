<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\CallIntake\CallDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CallUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['sometimes', 'nullable', 'integer'],
            'dealership_id' => ['sometimes', 'nullable', 'integer'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'employee_id' => ['sometimes', 'nullable', 'integer'],
            'direction' => ['sometimes', Rule::enum(CallDirection::class)],
            'started_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
            'started_at_tz' => ['sometimes', 'nullable', 'timezone:all'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
