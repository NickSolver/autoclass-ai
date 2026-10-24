<?php

namespace App\Domain\Workflow\Graph;

use App\Domain\Evaluation\Models\Checklist;
use App\Domain\Organization\Models\Company;
use App\Domain\Organization\Models\Dealership;
use App\Domain\Organization\Models\Department;

/**
 * Проверка графа перед публикацией (ТЗ, разд. 6.1): достижимость, отсутствие циклов, все выходы
 * подключены (значит, каждый путь заканчивается «Завершением»), допустимые узлы и настройки,
 * а по каждому пути — ровно один основной чек-лист до анализа и поля результата только после него.
 */
final class GraphValidator
{
    public const MAX_NODES = 100;

    public const MAX_DEPTH = 50;

    /** @var list<array{node_id: ?string, message: string}> */
    private array $errors = [];

    private Graph $graph;

    private ?int $companyId;

    /** @var array<string, true> */
    private array $visitedStates = [];

    /**
     * @param  array<string, mixed>  $json
     * @param  ?int  $companyId  область процесса: чек-листы и подразделения — только из неё (NULL — вся группа)
     * @return list<array{node_id: ?string, message: string}>
     */
    public function validate(array $json, ?int $companyId): array
    {
        $this->errors = [];
        $this->visitedStates = [];
        $this->graph = new Graph($json);
        $this->companyId = $companyId;
        $types = NodeTypes::all();

        if (count($this->graph->nodes) > self::MAX_NODES) {
            return [$this->error(null, 'Слишком много узлов (максимум '.self::MAX_NODES.').')];
        }

        $starts = array_filter($this->graph->nodes, fn (array $n) => $n['type'] === 'start');
        if (count($starts) !== 1) {
            $this->error(null, 'Нужен ровно один узел «Старт».');
        }
        if (! array_filter($this->graph->nodes, fn (array $n) => $n['type'] === 'end')) {
            $this->error(null, 'Нужен хотя бы один узел «Завершение».');
        }

        foreach ($this->graph->nodes as $id => $node) {
            $meta = $types[$node['type']] ?? null;
            if ($meta === null) {
                $this->error($id, "Неизвестный тип узла «{$node['type']}».");

                continue;
            }
            if (! $meta['available']) {
                $this->error($id, "Узел «{$meta['label']}» пока недоступен.");
            }
            $this->validateConfig($id, $node['type'], $node['data']);

            $outputs = NodeTypes::outputs($node['type'], $node['data']);
            foreach ($outputs as $handle) {
                $target = $this->graph->next($id, $handle);
                if ($target === null) {
                    $this->error($id, "Выход «{$this->handleLabel($handle, $node['data'])}» не подключён.");
                } elseif (! isset($this->graph->nodes[$target])) {
                    $this->error($id, 'Связь ведёт к несуществующему узлу.');
                }
            }
            foreach (array_keys($this->graph->edges[$id] ?? []) as $handle) {
                if (! in_array($handle, $outputs, true)) {
                    $this->error($id, 'Лишняя связь из узла.');
                }
            }
        }

        $start = $this->graph->start();
        if ($start !== null && $this->errors === []) {
            $reachable = [];
            if ($this->hasCycle($start, [], $reachable)) {
                $this->error(null, 'В процессе есть цикл. В этой версии допускается только граф без циклов.');
            } else {
                foreach (array_keys($this->graph->nodes) as $id) {
                    if (! isset($reachable[$id])) {
                        $this->error($id, 'Узел недостижим из «Старта».');
                    }
                }
                $this->walk($start, false, false, 0);
            }
        }

        return $this->errors;
    }

    /**
     * @param  array<string, true>  $stack
     * @param  array<string, true>  $reachable
     */
    private function hasCycle(string $id, array $stack, array &$reachable): bool
    {
        if (isset($stack[$id])) {
            return true;
        }
        if (isset($reachable[$id])) {
            return false;
        }
        $reachable[$id] = true;
        $stack[$id] = true;
        foreach ($this->graph->edges[$id] ?? [] as $target) {
            if (isset($this->graph->nodes[$target]) && $this->hasCycle($target, $stack, $reachable)) {
                return true;
            }
        }

        return false;
    }

    /** Обход путей с состоянием (основной чек-лист выбран / анализ выполнен), с мемоизацией. */
    private function walk(string $id, bool $primary, bool $analyzed, int $depth): void
    {
        $key = "{$id}:".(int) $primary.(int) $analyzed;
        if (isset($this->visitedStates[$key])) {
            return;
        }
        $this->visitedStates[$key] = true;
        if ($depth > self::MAX_DEPTH) {
            $this->error($id, 'Слишком длинный путь (максимум '.self::MAX_DEPTH.' шагов).');

            return;
        }

        $node = $this->graph->nodes[$id];
        $data = $node['data'];
        switch ($node['type']) {
            case 'select_primary':
                if ($analyzed) {
                    $this->error($id, 'Основной чек-лист выбирается до «Запустить анализ».');
                } elseif ($primary) {
                    $this->error($id, 'На этом пути основной чек-лист уже выбран — должен быть ровно один.');
                }
                $primary = true;
                break;
            case 'add_additional':
                if ($analyzed) {
                    $this->error($id, 'Дополнительные чек-листы добавляются до «Запустить анализ».');
                }
                break;
            case 'run_analysis':
                if (! $primary) {
                    $this->error($id, 'Перед «Запустить анализ» на каждом пути нужен основной чек-лист.');
                }
                if ($analyzed) {
                    $this->error($id, 'Анализ на одном пути запускается один раз.');
                }
                $analyzed = true;
                break;
            case 'condition':
                foreach ((array) ($data['rules'] ?? []) as $rule) {
                    $this->checkPhase($id, (string) ($rule['field'] ?? ''), $analyzed);
                }
                break;
            case 'switch':
                $this->checkPhase($id, (string) ($data['field'] ?? ''), $analyzed);
                break;
        }

        foreach (NodeTypes::outputs($node['type'], $data) as $handle) {
            $next = $this->graph->next($id, $handle);
            if ($next !== null && isset($this->graph->nodes[$next])) {
                $this->walk($next, $primary, $analyzed, $depth + 1);
            }
        }
    }

    private function checkPhase(string $id, string $field, bool $analyzed): void
    {
        $meta = FieldCatalog::fields()[$field] ?? null;
        if ($meta !== null && $meta['phase'] === 'after_analysis' && ! $analyzed) {
            $this->error($id, "Поле «{$meta['label']}» доступно только после «Запустить анализ».");
        }
    }

    /** @param array<string, mixed> $data */
    private function validateConfig(string $id, string $type, array $data): void
    {
        match ($type) {
            'start' => $this->validateStart($id, $data),
            'condition' => $this->validateCondition($id, $data),
            'switch' => $this->validateSwitch($id, $data),
            'select_primary' => $this->validateChecklist($id, $data['checklist_id'] ?? null),
            'add_additional' => $this->validateAdditional($id, $data),
            'create_review_task' => trim((string) ($data['reason'] ?? '')) === '' || mb_strlen((string) $data['reason']) > 300
                ? $this->error($id, 'Укажите причину проверки (до 300 символов).') : null,
            'set_label' => trim((string) ($data['label'] ?? '')) === '' || mb_strlen((string) $data['label']) > 40
                ? $this->error($id, 'Укажите метку (до 40 символов).') : null,
            'set_priority' => ! isset(NodeTypes::PRIORITIES[(string) ($data['priority'] ?? '')])
                ? $this->error($id, 'Выберите приоритет.') : null,
            default => null,
        };
    }

    /** @param array<string, mixed> $data */
    private function validateStart(string $id, array $data): void
    {
        $trigger = NodeTypes::TRIGGERS[(string) ($data['trigger'] ?? '')] ?? null;
        if ($trigger === null || ! $trigger['available']) {
            $this->error($id, 'Выберите доступное событие запуска.');
        }
    }

    /** @param array<string, mixed> $data */
    private function validateCondition(string $id, array $data): void
    {
        if (! in_array($data['match'] ?? 'all', ['all', 'any'], true)) {
            $this->error($id, 'Режим условий — «все» или «любое».');
        }
        $rules = (array) ($data['rules'] ?? []);
        if ($rules === []) {
            $this->error($id, 'Добавьте хотя бы одно правило.');
        }
        foreach ($rules as $i => $rule) {
            $this->validateRule($id, is_array($rule) ? $rule : [], $i + 1);
        }
    }

    /** @param array<string, mixed> $rule */
    private function validateRule(string $id, array $rule, int $n): void
    {
        $field = FieldCatalog::fields()[(string) ($rule['field'] ?? '')] ?? null;
        if ($field === null) {
            $this->error($id, "Правило {$n}: выберите поле из списка.");

            return;
        }
        $operator = (string) ($rule['operator'] ?? '');
        if (! in_array($operator, FieldCatalog::OPERATORS[$field['type']], true)) {
            $this->error($id, "Правило {$n}: оператор не подходит для поля «{$field['label']}».");

            return;
        }
        if (in_array($operator, ['is_null', 'is_not_null', 'is_true', 'is_false'], true)) {
            return;
        }
        $values = in_array($operator, ['in', 'not_in'], true) ? (array) ($rule['value'] ?? []) : [$rule['value'] ?? null];
        if ($values === [] || in_array(null, $values, true) || in_array('', $values, true)) {
            $this->error($id, "Правило {$n}: укажите значение.");

            return;
        }
        foreach ($values as $value) {
            if (! $this->valueValid($field, $value)) {
                $this->error($id, "Правило {$n}: недопустимое значение для поля «{$field['label']}».");

                return;
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function validateSwitch(string $id, array $data): void
    {
        $field = FieldCatalog::fields()[(string) ($data['field'] ?? '')] ?? null;
        if ($field === null || ! in_array($field['type'], ['enum', 'org'], true)) {
            $this->error($id, 'Выберите поле с перечислимыми значениями.');

            return;
        }
        $cases = array_map(fn ($c) => is_array($c) ? ($c['value'] ?? null) : null, (array) ($data['cases'] ?? []));
        if ($cases === []) {
            $this->error($id, 'Добавьте хотя бы одно значение.');
        }
        if (count($cases) !== count(array_unique(array_map('strval', $cases)))) {
            $this->error($id, 'Значения не должны повторяться.');
        }
        foreach ($cases as $value) {
            if ($value === null || ! $this->valueValid($field, $value)) {
                $this->error($id, 'Недопустимое значение ветки.');
            }
        }
    }

    /** @param array{type: string, values?: array<string, string>, org?: string} $field */
    private function valueValid(array $field, mixed $value): bool
    {
        return match ($field['type']) {
            'enum' => isset($field['values'][(string) $value]),
            'number' => is_numeric($value),
            'org' => is_numeric($value) && $this->orgInScope((string) ($field['org'] ?? ''), (int) $value),
            default => false,
        };
    }

    /** Подразделение существует и лежит в области процесса. */
    private function orgInScope(string $level, int $id): bool
    {
        $companyId = match ($level) {
            'company' => Company::query()->whereKey($id)->value('id'),
            'dealership' => Dealership::query()->whereKey($id)->value('company_id'),
            'department' => Department::query()->whereKey($id)->value('company_id'),
            default => null,
        };

        return $companyId !== null && ($this->companyId === null || (int) $companyId === $this->companyId);
    }

    /** @param array<string, mixed> $data */
    private function validateAdditional(string $id, array $data): void
    {
        $ids = (array) ($data['checklist_ids'] ?? []);
        if (count($ids) !== count(array_unique($ids))) {
            $this->error($id, 'Чек-листы не должны повторяться.');
        }
        foreach ($ids as $checklistId) {
            $this->validateChecklist($id, $checklistId);
        }
    }

    private function validateChecklist(string $id, mixed $checklistId): void
    {
        $checklist = is_numeric($checklistId) ? Checklist::query()->find((int) $checklistId) : null;
        if ($checklist === null) {
            $this->error($id, 'Выберите чек-лист.');
        } elseif ($checklist->is_archived) {
            $this->error($id, "Чек-лист «{$checklist->name}» в архиве.");
        } elseif ($checklist->company_id !== null && $checklist->company_id !== $this->companyId) {
            $this->error($id, "Чек-лист «{$checklist->name}» принадлежит другой компании.");
        } elseif ($checklist->latestPublished() === null) {
            $this->error($id, "У чек-листа «{$checklist->name}» нет опубликованной версии.");
        }
    }

    /** @param array<string, mixed> $data */
    private function handleLabel(string $handle, array $data): string
    {
        return match (true) {
            $handle === 'true' => 'да',
            $handle === 'false' => 'нет',
            $handle === 'default' => 'иначе',
            str_starts_with($handle, 'case:') => (string) (((array) ($data['cases'] ?? []))[(int) substr($handle, 5)]['label'] ?? $handle),
            default => 'далее',
        };
    }

    /** @return array{node_id: ?string, message: string} */
    private function error(?string $nodeId, string $message): array
    {
        $error = ['node_id' => $nodeId, 'message' => $message];
        if (! in_array($error, $this->errors, true)) {
            $this->errors[] = $error;
        }

        return $error;
    }
}
