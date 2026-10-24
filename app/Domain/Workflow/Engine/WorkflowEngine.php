<?php

namespace App\Domain\Workflow\Engine;

use App\Domain\Workflow\Graph\FieldCatalog;
use App\Domain\Workflow\Graph\Graph;
use RuntimeException;

/**
 * Серверный интерпретатор графа (ТЗ, разд. 6). Детерминирован и без побочных эффектов: только
 * обход узлов, вычисление условий по белому списку и накопление плана. Останавливается на
 * «Запустить анализ» (pause) — продолжение после оценки идёт по той же версии графа.
 */
final class WorkflowEngine
{
    private const MAX_STEPS = 200;

    /**
     * @param  array<string, mixed>  $context
     * @return array{status: 'paused'|'completed', node_id: ?string, trace: list<array{node_id: string, node_type: string, branch: ?string, output: array<string, mixed>}>}
     */
    public function run(Graph $graph, ?string $fromNode, array $context, Plan $plan): array
    {
        $trace = [];
        $id = $fromNode ?? $graph->start() ?? throw new RuntimeException('В процессе нет узла «Старт».');

        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $node = $graph->nodes[$id] ?? throw new RuntimeException("Узел {$id} не найден.");
            $data = $node['data'];
            $branch = 'out';
            $output = [];

            switch ($node['type']) {
                case 'end':
                    $trace[] = ['node_id' => $id, 'node_type' => 'end', 'branch' => null, 'output' => []];

                    return ['status' => 'completed', 'node_id' => $id, 'trace' => $trace];
                case 'condition':
                    $results = array_map(fn (array $r) => FieldCatalog::evaluate((string) $r['field'], (string) $r['operator'], $r['value'] ?? null, $context), (array) $data['rules']);
                    $passed = ($data['match'] ?? 'all') === 'any' ? in_array(true, $results, true) : ! in_array(false, $results, true);
                    $branch = $passed ? 'true' : 'false';
                    $output = ['rules' => $results];
                    break;
                case 'switch':
                    $branch = 'default';
                    foreach (array_values((array) $data['cases']) as $i => $case) {
                        if ((string) ($context[$data['field']] ?? '') === (string) $case['value']) {
                            $branch = "case:{$i}";
                            break;
                        }
                    }
                    $output = ['value' => $context[$data['field']] ?? null];
                    break;
                case 'select_primary':
                    $plan->primaryChecklistId = (int) $data['checklist_id'];
                    $plan->additionalChecklistIds = array_values(array_diff($plan->additionalChecklistIds, [$plan->primaryChecklistId]));
                    $output = ['checklist_id' => $plan->primaryChecklistId];
                    break;
                case 'add_additional':
                    foreach ((array) $data['checklist_ids'] as $checklistId) {
                        if ((int) $checklistId !== $plan->primaryChecklistId && ! in_array((int) $checklistId, $plan->additionalChecklistIds, true)) {
                            $plan->additionalChecklistIds[] = (int) $checklistId;
                        }
                    }
                    $output = ['checklist_ids' => $plan->additionalChecklistIds];
                    break;
                case 'run_analysis':
                    $plan->analysisRequested = true;
                    $trace[] = ['node_id' => $id, 'node_type' => 'run_analysis', 'branch' => 'out', 'output' => []];

                    return ['status' => 'paused', 'node_id' => $id, 'trace' => $trace];
                case 'create_review_task':
                    $plan->reviewReasons[] = (string) $data['reason'];
                    $output = ['reason' => $data['reason']];
                    break;
                case 'set_label':
                    if (! in_array((string) $data['label'], $plan->labels, true)) {
                        $plan->labels[] = (string) $data['label'];
                    }
                    $output = ['label' => $data['label']];
                    break;
                case 'set_priority':
                    $plan->priority = (string) $data['priority'];
                    $output = ['priority' => $data['priority']];
                    break;
            }

            $trace[] = ['node_id' => $id, 'node_type' => $node['type'], 'branch' => $branch, 'output' => $output];
            $id = $graph->next($id, $branch) ?? throw new RuntimeException("Из узла {$id} нет перехода «{$branch}».");
        }

        throw new RuntimeException('Превышено число шагов процесса.');
    }

    /** Продолжение после анализа: со следующего за «Запустить анализ» узла. */
    public function resume(Graph $graph, string $analysisNodeId, array $context, Plan $plan): array
    {
        $next = $graph->next($analysisNodeId, 'out') ?? throw new RuntimeException('После «Запустить анализ» нет перехода.');

        return $this->run($graph, $next, $context, $plan);
    }
}
