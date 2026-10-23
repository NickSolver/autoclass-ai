<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UserStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
            'reason' => ['required_if:is_active,false', 'nullable', 'string', 'max:500'],
        ];
    }
}
