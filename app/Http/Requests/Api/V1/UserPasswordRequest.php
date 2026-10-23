<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UserPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:255', Password::defaults()]];
    }
}
