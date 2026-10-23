<?php

namespace App\Http\Resources;

use App\Domain\Audio\Models\AudioFile;
use App\Domain\CallIntake\Models\Call;
use App\Domain\CallIntake\Models\CallProcessingAttempt;
use App\Domain\Identity\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Call */
class CallResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $snapshot = $this->org_snapshot;

        return [
            'id' => $this->id,
            'source' => $this->whenLoaded('source', fn () => ['code' => $this->source->code, 'name' => $this->source->name]),
            'status' => ['code' => $this->status->value, 'label' => $this->status->label()],
            'status_changed_at' => $this->status_changed_at?->toIso8601String(),
            'direction' => ['code' => $this->direction->value, 'label' => $this->direction->label()],
            'started_at' => $this->started_at?->toIso8601String(),
            'started_at_tz' => $this->started_at_tz,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'duration_ms' => $this->duration_ms,
            'company_id' => $this->company_id,
            'dealership_id' => $this->dealership_id,
            'department_id' => $this->department_id,
            'employee_id' => $this->employee_id,
            'attribution_status' => $this->attribution_status->value,
            // Названия — из снимка на момент приёма: перевод сотрудника не меняет историю.
            'org' => [
                'company' => $snapshot['company']['name'] ?? null,
                'dealership' => $snapshot['dealership']['name'] ?? null,
                'department' => $snapshot['department']['name'] ?? null,
                'employee' => $snapshot['employee']['full_name'] ?? null,
                'employee_position' => $snapshot['employee']['position'] ?? null,
                'captured_at' => $snapshot['captured_at'] ?? null,
            ],
            'note' => $this->note,
            'last_error' => $this->last_error,
            'excluded' => $this->excluded_at ? ['at' => $this->excluded_at->toIso8601String(), 'reason' => $this->excluded_reason] : null,
            // Ссылка на дубль — только если он в области пользователя.
            'duplicate_of' => $this->duplicate_of_call_id ? [
                'id' => $this->duplicateOf?->isVisibleTo($actor, Permission::CallsView) ? $this->duplicate_of_call_id : null,
            ] : null,
            'uploader' => $this->whenLoaded('uploader', fn () => $this->uploader ? ['id' => $this->uploader->id, 'name' => $this->uploader->name] : null),
            'original' => $this->whenLoaded('original', fn () => $this->original ? $this->audio($this->original) : null),
            'normalized' => $this->whenLoaded('normalized', fn () => $this->normalized ? $this->audio($this->normalized) : null),
            'attempts' => $this->whenLoaded('attempts', fn () => $this->attempts->map(fn (CallProcessingAttempt $a) => [
                'id' => $a->id,
                'step' => $a->step,
                'attempt' => $a->attempt,
                'status' => $a->status,
                'started_at' => $a->started_at->toIso8601String(),
                'finished_at' => $a->finished_at?->toIso8601String(),
                'error_code' => $a->error_code,
                'error_message' => $a->error_message,
            ])->values()),
            'created_at' => $this->created_at?->toIso8601String(),
            'can' => [
                'update' => $actor->can('update', $this->resource),
                'reprocess' => $actor->can('reprocess', $this->resource),
                'exclude' => $actor->can('exclude', $this->resource),
                'play' => $actor->can('play', $this->resource),
                'download' => $actor->can('download', $this->resource),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function audio(AudioFile $file): array
    {
        return [
            'name' => $file->original_name,
            'format' => $file->format,
            'size_bytes' => $file->size_bytes,
            'duration_ms' => $file->duration_ms,
            'codec' => $file->codec,
            'sample_rate' => $file->sample_rate,
            'channels' => $file->channels,
            'bit_rate' => $file->bit_rate,
            'sha256' => $file->sha256,
        ];
    }
}
