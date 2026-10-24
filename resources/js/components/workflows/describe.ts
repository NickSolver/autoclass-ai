import type { GraphError, WorkflowCatalog } from '@/api/types';
import type { ComputedRef, InjectionKey, Ref } from 'vue';

/** Общее состояние редактора для узлов холста. */
export interface EditorContext {
    catalog: Ref<WorkflowCatalog | null>;
    errorsByNode: ComputedRef<Map<string, string[]>>;
    traced: Ref<Set<string>>;
}

export const editorKey: InjectionKey<EditorContext> = Symbol('workflow-editor');

export interface Rule {
    field: string;
    operator: string;
    value?: string | number | null | (string | number)[];
}

const NO_VALUE = ['is_null', 'is_not_null', 'is_true', 'is_false'];
export const MULTI_VALUE = ['in', 'not_in'];

export function operatorNeedsValue(operator: string): boolean {
    return !NO_VALUE.includes(operator);
}

/** Подпись значения поля: перечисление, подразделение или число. */
export function valueLabel(catalog: WorkflowCatalog, field: string, value: unknown): string {
    const meta = catalog.fields[field];
    if (!meta) return String(value ?? '');
    if (meta.type === 'enum') return meta.values?.[String(value)] ?? String(value);
    if (meta.type === 'org' && meta.org) return catalog.org[meta.org].find((o) => o.id === Number(value))?.name ?? `#${String(value)}`;
    return String(value ?? '');
}

export function ruleText(catalog: WorkflowCatalog, rule: Rule): string {
    const meta = catalog.fields[rule.field];
    if (!meta) return 'поле не выбрано';
    const op = catalog.operator_labels[rule.operator] ?? rule.operator;
    if (!operatorNeedsValue(rule.operator)) return `${meta.label}: ${op}`;
    const values = Array.isArray(rule.value) ? rule.value : [rule.value];
    return `${meta.label} ${op} ${values.map((v) => valueLabel(catalog, rule.field, v)).join(', ')}`;
}

const checklistName = (catalog: WorkflowCatalog, id: unknown) => catalog.checklists.find((c) => c.id === Number(id))?.name ?? (id ? `#${String(id)}` : 'не выбран');

/** Краткое содержание узла для холста и списков. */
export function describeNode(catalog: WorkflowCatalog, type: string, data: Record<string, unknown>): string[] {
    switch (type) {
        case 'start':
            return [catalog.triggers[String(data.trigger)]?.label ?? 'событие не выбрано'];
        case 'condition': {
            const rules = (data.rules as Rule[] | undefined) ?? [];
            if (!rules.length) return ['правила не заданы'];
            const glue = data.match === 'any' ? 'или' : 'и';
            return rules.map((r, i) => (i ? `${glue} ` : '') + ruleText(catalog, r));
        }
        case 'switch':
            return [catalog.fields[String(data.field)]?.label ?? 'поле не выбрано'];
        case 'select_primary':
            return [checklistName(catalog, data.checklist_id)];
        case 'add_additional': {
            const ids = (data.checklist_ids as number[] | undefined) ?? [];
            return ids.length ? ids.map((id) => checklistName(catalog, id)) : ['не выбраны'];
        }
        case 'create_review_task':
            return [String(data.reason || 'причина не указана')];
        case 'set_label':
            return [String(data.label || 'метка не указана')];
        case 'set_priority':
            return [catalog.priorities[String(data.priority)] ?? 'не выбран'];
        default:
            return [];
    }
}

/** Выходы узла — как на сервере (NodeTypes::outputs). */
export function nodeOutputs(type: string, data: Record<string, unknown>): string[] {
    if (type === 'end') return [];
    if (type === 'condition') return ['true', 'false'];
    if (type === 'switch') return [...((data.cases as unknown[] | undefined) ?? []).map((_, i) => `case:${i}`), 'default'];
    return ['out'];
}

export function outputLabel(catalog: WorkflowCatalog | null, handle: string, data: Record<string, unknown>): string {
    if (handle === 'true') return 'да';
    if (handle === 'false') return 'нет';
    if (handle === 'default') return 'иначе';
    if (handle.startsWith('case:')) {
        const value = ((data.cases as { value: unknown }[] | undefined) ?? [])[Number(handle.slice(5))]?.value;
        return catalog && value !== undefined && value !== null && value !== '' ? valueLabel(catalog, String(data.field), value) : 'значение не задано';
    }
    return 'далее';
}

/** Начальные настройки нового узла. */
export function defaultData(type: string): Record<string, unknown> {
    switch (type) {
        case 'start':
            return { trigger: 'transcription_completed' };
        case 'condition':
            return { match: 'all', rules: [{ field: 'direction', operator: 'eq', value: 'incoming' }] };
        case 'switch':
            return { field: 'direction', cases: [{ value: 'incoming' }, { value: 'outgoing' }] };
        case 'select_primary':
            return { checklist_id: null };
        case 'add_additional':
            return { checklist_ids: [] };
        case 'create_review_task':
            return { reason: '' };
        case 'set_label':
            return { label: '' };
        case 'set_priority':
            return { priority: 'high' };
        default:
            return {};
    }
}

export function groupErrors(errors: GraphError[]): Map<string, string[]> {
    const map = new Map<string, string[]>();
    for (const e of errors) if (e.node_id) map.set(e.node_id, [...(map.get(e.node_id) ?? []), e.message]);
    return map;
}
