<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UserStoreRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('login')) {
            $this->merge(['login' => mb_strtolower(trim((string) $this->input('login')))]);
        }
        if ($this->filled('email')) {
            $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9._-]+$/', 'unique:users,login'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'max:255', Password::defaults()],
            'employee_id' => ['nullable', 'integer'],
            'group_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
            'dealership_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
        ];
    }
}
