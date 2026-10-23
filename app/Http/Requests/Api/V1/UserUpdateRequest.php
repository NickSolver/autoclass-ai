<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->route('user');

        return [
            'login' => ['sometimes', 'required', 'string', 'min:3', 'max:64', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'login')->ignore($user->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'employee_id' => ['sometimes', 'nullable', 'integer'],
            'group_id' => ['sometimes', 'nullable', 'integer'],
            'company_id' => ['sometimes', 'nullable', 'integer'],
            'dealership_id' => ['sometimes', 'nullable', 'integer'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
