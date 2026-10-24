<?php

namespace App\Domain\Workflow\Engine;

/** Решения процесса: что оценивать и какие действия выполнить над звонком. */
final class Plan
{
    public ?int $primaryChecklistId = null;

    /** @var list<int> */
    public array $additionalChecklistIds = [];

    public bool $analysisRequested = false;

    /** @var list<string> */
    public array $reviewReasons = [];

    /** @var list<string> */
    public array $labels = [];

    public ?string $priority = null;

    /** @var array<int, int> checklist_id → закреплённая версия (на момент выбора маршрута) */
    public array $pinnedVersions = [];

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'primary_checklist_id' => $this->primaryChecklistId,
            'additional_checklist_ids' => $this->additionalChecklistIds,
            'analysis_requested' => $this->analysisRequested,
            'review_reasons' => $this->reviewReasons,
            'labels' => $this->labels,
            'priority' => $this->priority,
            'pinned_versions' => $this->pinnedVersions,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $plan = new self;
        $plan->primaryChecklistId = isset($data['primary_checklist_id']) ? (int) $data['primary_checklist_id'] : null;
        $plan->additionalChecklistIds = array_map('intval', (array) ($data['additional_checklist_ids'] ?? []));
        $plan->analysisRequested = (bool) ($data['analysis_requested'] ?? false);
        $plan->reviewReasons = array_values((array) ($data['review_reasons'] ?? []));
        $plan->labels = array_values((array) ($data['labels'] ?? []));
        $plan->priority = $data['priority'] ?? null;
        foreach ((array) ($data['pinned_versions'] ?? []) as $checklistId => $versionId) {
            $plan->pinnedVersions[(int) $checklistId] = (int) $versionId;
        }

        return $plan;
    }
}
