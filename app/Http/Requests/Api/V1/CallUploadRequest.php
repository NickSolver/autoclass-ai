<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\CallIntake\CallDirection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CallUploadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Содержимое проверяется сигнатурой и ffprobe в CallUploadService; здесь — размер.
            'file' => ['required', 'file', 'max:'.((int) config('calls.max_file_size_mb', 100) * 1024)],
            'company_id' => ['nullable', 'integer', 'required_without_all:dealership_id,department_id'],
            'dealership_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'direction' => ['nullable', Rule::enum(CallDirection::class)],
            'started_at' => ['nullable', 'date', 'before_or_equal:now'],
            'started_at_tz' => ['nullable', 'timezone:all'],
            'note' => ['nullable', 'string', 'max:2000'],
            'confirm_duplicate' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'Файл больше допустимого размера ('.config('calls.max_file_size_mb').' МБ).',
            'file.uploaded' => 'Файл не удалось загрузить: возможно, он больше допустимого размера ('.config('calls.max_file_size_mb').' МБ).',
        ];
    }
}
