<?php

namespace App\Domain\Workflow\Graph;

/**
 * Белый список полей и операторов условий (ТЗ, разд. 6.1): никакого eval, PHP/JS/SQL из админки.
 * Поля phase = after_analysis доступны только в узлах после «Запустить анализ».
 */
final class FieldCatalog
{
    public const OPERATORS = [
        'enum' => ['eq', 'neq', 'in', 'not_in'],
        'org' => ['eq', 'neq', 'in', 'not_in', 'is_null', 'is_not_null'],
        'number' => ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'is_null', 'is_not_null'],
        'bool' => ['is_true', 'is_false'],
    ];

    public const OPERATOR_LABELS = [
        'eq' => 'равно', 'neq' => 'не равно', 'in' => 'одно из', 'not_in' => 'ни одно из',
        'gt' => 'больше', 'gte' => 'не меньше', 'lt' => 'меньше', 'lte' => 'не больше',
        'is_null' => 'не задано', 'is_not_null' => 'задано', 'is_true' => 'да', 'is_false' => 'нет',
    ];

    /** @return array<string, array{label: string, type: string, phase: string, values?: array<string, string>, org?: string}> */
    public static function fields(): array
    {
        return [
            'direction' => ['label' => 'Направление звонка', 'type' => 'enum', 'phase' => 'before_analysis',
                'values' => ['incoming' => 'Входящий', 'outgoing' => 'Исходящий', 'unknown' => 'Не указано']],
            'source' => ['label' => 'Источник', 'type' => 'enum', 'phase' => 'before_analysis',
                'values' => ['manual' => 'Ручная загрузка', 'calltouch' => 'Calltouch', 'lyagushka' => '«Лягушка»']],
            'company_id' => ['label' => 'Компания', 'type' => 'org', 'phase' => 'before_analysis', 'org' => 'company'],
            'dealership_id' => ['label' => 'Дилерский центр', 'type' => 'org', 'phase' => 'before_analysis', 'org' => 'dealership'],
            'department_id' => ['label' => 'Отдел', 'type' => 'org', 'phase' => 'before_analysis', 'org' => 'department'],
            'department_kind' => ['label' => 'Тип отдела', 'type' => 'enum', 'phase' => 'before_analysis',
                'values' => ['sales' => 'Продажи', 'service' => 'Сервис', 'parts' => 'Запчасти', 'call_center' => 'Колл-центр', 'marketing' => 'Маркетинг', 'other' => 'Другое']],
            'duration_seconds' => ['label' => 'Длительность, с', 'type' => 'number', 'phase' => 'before_analysis'],
            'employee_assigned' => ['label' => 'Сотрудник установлен', 'type' => 'bool', 'phase' => 'before_analysis'],
            'transcript_synthetic' => ['label' => 'Расшифровка тестовая', 'type' => 'bool', 'phase' => 'before_analysis'],
            'transcript_confidence' => ['label' => 'Качество распознавания (0–1)', 'type' => 'number', 'phase' => 'before_analysis'],
            'speakers_count' => ['label' => 'Число говорящих', 'type' => 'number', 'phase' => 'before_analysis'],
            'business_status' => ['label' => 'Статус оценки (основной чек-лист)', 'type' => 'enum', 'phase' => 'after_analysis',
                'values' => ['full_assessment' => 'Полная оценка', 'human_intervention_required' => 'Требуется вмешательство человека']],
            'primary_score' => ['label' => 'Оценка основного чек-листа', 'type' => 'number', 'phase' => 'after_analysis'],
            'critical_violation' => ['label' => 'Есть критическое нарушение', 'type' => 'bool', 'phase' => 'after_analysis'],
            'any_intervention' => ['label' => 'Любой чек-лист требует вмешательства', 'type' => 'bool', 'phase' => 'after_analysis'],
        ];
    }

    /** @param array<string, mixed> $context */
    public static function evaluate(string $field, string $operator, mixed $expected, array $context): bool
    {
        $actual = $context[$field] ?? null;
        $list = array_map('strval', (array) $expected);

        return match ($operator) {
            'eq' => $actual !== null && (string) $actual === (string) $expected,
            'neq' => $actual === null || (string) $actual !== (string) $expected,
            'in' => $actual !== null && in_array((string) $actual, $list, true),
            'not_in' => $actual === null || ! in_array((string) $actual, $list, true),
            'gt' => $actual !== null && (float) $actual > (float) $expected,
            'gte' => $actual !== null && (float) $actual >= (float) $expected,
            'lt' => $actual !== null && (float) $actual < (float) $expected,
            'lte' => $actual !== null && (float) $actual <= (float) $expected,
            'is_null' => $actual === null,
            'is_not_null' => $actual !== null,
            'is_true' => $actual === true,
            'is_false' => $actual === false,
            default => false,
        };
    }
}
