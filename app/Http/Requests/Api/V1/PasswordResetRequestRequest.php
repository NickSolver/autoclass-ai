<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class PasswordResetRequestRequest extends FormRequest
{
    public function rules(): array
    {
        return ['login' => ['required', 'string', 'max:255']];
    }
}
