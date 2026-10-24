<?php

namespace App\Domain\Workflow\Graph;

/**
 * Типы узлов v1 (ТЗ, разд. 6.1). Узлы available = false показываются в палитре, но пока недоступны.
 */
final class NodeTypes
{
    public const TRIGGERS = [
        'transcription_completed' => ['label' => 'Завершено распознавание', 'available' => true],
        'call_received' => ['label' => 'Поступил звонок', 'available' => false],
        'manual' => ['label' => 'Ручное событие', 'available' => false],
    ];

    public const PRIORITIES = ['normal' => 'Обычный', 'high' => 'Высокий', 'urgent' => 'Срочный'];

    /** @return array<string, array{label: string, group: string, available: bool, description: string}> */
    public static function all(): array
    {
        return [
            'start' => ['label' => 'Старт', 'group' => 'Управление', 'available' => true, 'description' => 'Событие, запускающее процесс.'],
            'condition' => ['label' => 'Условие (если / иначе)', 'group' => 'Управление', 'available' => true, 'description' => 'Ветвление по правилам: да / нет.'],
            'switch' => ['label' => 'Выбор по значению', 'group' => 'Управление', 'available' => true, 'description' => 'Ветка на каждое значение поля и ветка «иначе».'],
            'end' => ['label' => 'Завершение', 'group' => 'Управление', 'available' => true, 'description' => 'Конец процесса.'],
            'select_primary' => ['label' => 'Основной чек-лист', 'group' => 'Оценка', 'available' => true, 'description' => 'Ровно один основной чек-лист для оценки.'],
            'add_additional' => ['label' => 'Дополнительные чек-листы', 'group' => 'Оценка', 'available' => true, 'description' => 'Ноль или больше дополнительных чек-листов.'],
            'run_analysis' => ['label' => 'Запустить анализ', 'group' => 'Оценка', 'available' => true, 'description' => 'Оценка по выбранным чек-листам. После него доступны поля результата.'],
            'create_review_task' => ['label' => 'Задача на проверку', 'group' => 'Действия', 'available' => true, 'description' => 'Отправить звонок на ручную проверку с причиной.'],
            'set_label' => ['label' => 'Метка', 'group' => 'Действия', 'available' => true, 'description' => 'Добавить метку звонку.'],
            'set_priority' => ['label' => 'Приоритет', 'group' => 'Действия', 'available' => true, 'description' => 'Изменить приоритет звонка.'],
            'assign' => ['label' => 'Назначить сотрудника / отдел', 'group' => 'Действия', 'available' => false, 'description' => 'В следующей версии.'],
            'notify' => ['label' => 'Уведомление', 'group' => 'Действия', 'available' => false, 'description' => 'В следующей версии (нет модуля уведомлений).'],
        ];
    }

    /**
     * Выходы узла (handles), каждый должен вести к следующему узлу.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function outputs(string $type, array $data): array
    {
        return match ($type) {
            'end' => [],
            'condition' => ['true', 'false'],
            'switch' => [...array_map(fn (int $i) => "case:{$i}", array_keys(array_values((array) ($data['cases'] ?? [])))), 'default'],
            default => ['out'],
        };
    }
}
