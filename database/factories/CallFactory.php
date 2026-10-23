<?php

namespace Database\Factories;

use App\Domain\CallIntake\AttributionStatus;
use App\Domain\CallIntake\CallDirection;
use App\Domain\CallIntake\CallStatus;
use App\Domain\CallIntake\Models\Call;
use App\Domain\CallIntake\Models\CallSource;
use App\Domain\Organization\Models\Department;
use App\Domain\Organization\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Звонок без аудиофайла — для тестов списков и прав. Загрузка с файлом проверяется через API.
 *
 * @extends Factory<Call>
 */
class CallFactory extends Factory
{
    protected $model = Call::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'source_id' => fn () => CallSource::manual()->id,
            'external_id' => (string) Str::uuid(),
            'direction' => CallDirection::Incoming,
            'duration_ms' => 60_000,
            'attribution_status' => AttributionStatus::NeedsEmployee,
            'org_snapshot' => [],
            'status' => CallStatus::AwaitingTranscription,
            'status_changed_at' => now(),
        ];
    }

    public function inDepartment(Department $department, ?Employee $employee = null): static
    {
        return $this->state(fn () => [
            ...$department->orgPath()->toColumns(),
            'employee_id' => $employee?->id,
            'attribution_status' => $employee ? AttributionStatus::Resolved : AttributionStatus::NeedsEmployee,
            'org_snapshot' => [
                'company' => ['id' => $department->company_id, 'name' => $department->company->name],
                'department' => ['id' => $department->id, 'name' => $department->name],
                'employee' => $employee ? ['id' => $employee->id, 'full_name' => $employee->fullName(), 'position' => null] : null,
            ],
        ]);
    }
}
