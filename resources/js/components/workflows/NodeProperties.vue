<script setup lang="ts">
import type { CatalogField, WorkflowCatalog } from '@/api/types';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import { computed, ref, watch } from 'vue';
import { MULTI_VALUE, nodeOutputs, operatorNeedsValue, outputLabel, type Rule } from './describe';

/**
 * Настройки выбранного узла и его переходы. Переходы можно задать и здесь, без мыши:
 * каждому выходу — следующий узел.
 */
const props = defineProps<{
    node: { id: string; type: string; data: Record<string, unknown> };
    catalog: WorkflowCatalog;
    editable: boolean;
    targets: { id: string; label: string }[];
    connections: Record<string, string | null>;
    errors: string[];
}>();
const emit = defineEmits<{
    update: [data: Record<string, unknown>];
    connect: [handle: string, target: string | null];
    removeCase: [index: number];
    remove: [];
}>();

interface NodeData {
    label?: string | number | null;
    trigger?: string | number | null;
    match?: string | number | null;
    rules?: Rule[];
    field?: string;
    cases?: { value: string | number | null }[];
    checklist_id?: string | number | null;
    checklist_ids?: number[];
    reason?: string | number | null;
    priority?: string | number | null;
    [key: string]: unknown;
}

const draft = ref<NodeData>({});
let syncing = false;
watch(
    () => props.node.id,
    () => {
        syncing = true;
        draft.value = JSON.parse(JSON.stringify(props.node.data)) as NodeData;
        queueMicrotask(() => (syncing = false));
    },
    { immediate: true },
);
watch(draft, (value) => !syncing && emit('update', JSON.parse(JSON.stringify(value)) as Record<string, unknown>), { deep: true });

const meta = computed(() => props.catalog.node_types[props.node.type]);
const fieldOptions = computed(() =>
    Object.entries(props.catalog.fields).map(([code, f]) => ({ value: code, label: f.phase === 'after_analysis' ? `${f.label} (после анализа)` : f.label })),
);
const switchFieldOptions = computed(() => fieldOptions.value.filter((o) => ['enum', 'org'].includes(props.catalog.fields[String(o.value)]?.type ?? '')));
const checklistOptions = computed(() =>
    props.catalog.checklists.map((c) => ({
        value: c.id,
        label: `${c.name}${c.is_demo ? ' (демо)' : ''}${c.published_version ? ` · v${c.published_version}` : ' · не опубликован'}`,
        disabled: !c.published_version,
    })),
);

function valueOptions(field: CatalogField | undefined): { value: string | number; label: string }[] {
    if (!field) return [];
    if (field.type === 'enum') return Object.entries(field.values ?? {}).map(([value, label]) => ({ value, label }));
    if (field.type === 'org' && field.org) return props.catalog.org[field.org].map((o) => ({ value: o.id, label: o.name }));
    return [];
}

const rules = computed(() => draft.value.rules ?? []);
const cases = computed(() => draft.value.cases ?? []);
const additional = computed(() => draft.value.checklist_ids ?? []);

function operatorOptions(fieldCode: string): { value: string; label: string }[] {
    const field = props.catalog.fields[fieldCode];
    return field ? props.catalog.operators[field.type].map((op) => ({ value: op, label: props.catalog.operator_labels[op] ?? op })) : [];
}

function setField(rule: Rule, field: string): void {
    rule.field = field;
    rule.operator = operatorOptions(field)[0]?.value ?? '';
    rule.value = null;
}

function setOperator(rule: Rule, operator: string): void {
    const wasMulti = MULTI_VALUE.includes(rule.operator);
    rule.operator = operator;
    if (!operatorNeedsValue(operator)) delete rule.value;
    else if (MULTI_VALUE.includes(operator) !== wasMulti) rule.value = MULTI_VALUE.includes(operator) ? [] : null;
}

function scalar(value: Rule['value']): string | number | null {
    return Array.isArray(value) ? null : (value ?? null);
}

function toggleMulti(rule: Rule, value: string | number, on: boolean): void {
    const list = Array.isArray(rule.value) ? [...rule.value] : [];
    rule.value = on ? [...list, value] : list.filter((v) => String(v) !== String(value));
}

function addRule(): void {
    draft.value.rules = [...rules.value, { field: 'direction', operator: 'eq', value: 'incoming' }];
}

function removeRule(i: number): void {
    draft.value.rules = rules.value.filter((_, j) => j !== i);
}

function addCase(): void {
    draft.value.cases = [...cases.value, { value: null }];
}

function removeCase(i: number): void {
    draft.value.cases = cases.value.filter((_, j) => j !== i);
    emit('removeCase', i);
}

function setSwitchField(field: string): void {
    draft.value.field = field;
    draft.value.cases = cases.value.map(() => ({ value: null }));
}

function toggleAdditional(id: number, on: boolean): void {
    draft.value.checklist_ids = on ? [...additional.value, id] : additional.value.filter((x) => x !== id);
}

const outputs = computed(() => nodeOutputs(props.node.type, draft.value));
const targetOptions = computed(() => props.targets.filter((t) => t.id !== props.node.id).map((t) => ({ value: t.id, label: t.label })));
const triggerOptions = computed(() => Object.entries(props.catalog.triggers).map(([value, t]) => ({ value, label: t.available ? t.label : `${t.label} (в разработке)`, disabled: !t.available })));
const priorityOptions = computed(() => Object.entries(props.catalog.priorities).map(([value, label]) => ({ value, label })));
</script>

<template>
    <div class="space-y-4" data-testid="node-properties">
        <div>
            <h3 class="font-semibold">{{ meta?.label ?? node.type }}</h3>
            <p class="text-[12px] text-muted">{{ meta?.description }}</p>
        </div>
        <ul v-if="errors.length" class="space-y-1 rounded-md bg-red-50 p-2 text-[12px] text-red-700">
            <li v-for="e in errors" :key="e">{{ e }}</li>
        </ul>

        <fieldset :disabled="!editable" class="space-y-3">
            <UiInput v-if="node.type !== 'start' && node.type !== 'end'" v-model="draft.label" label="Название на схеме" placeholder="Необязательно" :maxlength="60" />

            <UiSelect v-if="node.type === 'start'" v-model="draft.trigger" label="Событие запуска" :options="triggerOptions" />

            <template v-if="node.type === 'condition'">
                <UiSelect
                    v-model="draft.match"
                    label="Выполнено, если"
                    :options="[{ value: 'all', label: 'все правила верны (и)' }, { value: 'any', label: 'хотя бы одно правило верно (или)' }]"
                />
                <div v-for="(rule, i) in rules" :key="i" class="space-y-2 rounded-md border border-line p-2" data-testid="rule">
                    <div class="flex items-center justify-between text-[12px] text-muted">
                        <span>Правило {{ i + 1 }}</span>
                        <button v-if="editable && rules.length > 1" type="button" class="text-red-600 hover:underline" @click="removeRule(i)">удалить</button>
                    </div>
                    <UiSelect :model-value="rule.field" label="Поле" :options="fieldOptions" @update:model-value="(v) => setField(rule, String(v))" />
                    <UiSelect :model-value="rule.operator" label="Условие" :options="operatorOptions(rule.field)" @update:model-value="(v) => setOperator(rule, String(v))" />
                    <template v-if="operatorNeedsValue(rule.operator)">
                        <div v-if="MULTI_VALUE.includes(rule.operator)" class="space-y-1">
                            <UiCheckbox
                                v-for="o in valueOptions(catalog.fields[rule.field])"
                                :key="o.value"
                                :label="o.label"
                                :model-value="Array.isArray(rule.value) && rule.value.map(String).includes(String(o.value))"
                                @update:model-value="(on) => toggleMulti(rule, o.value, !!on)"
                            />
                        </div>
                        <UiSelect
                            v-else-if="valueOptions(catalog.fields[rule.field]).length"
                            :model-value="scalar(rule.value)" label="Значение"
                            placeholder="Выберите значение"
                            :options="valueOptions(catalog.fields[rule.field])"
                            @update:model-value="(v) => (rule.value = v ?? null)"
                        />
                        <UiInput v-else :model-value="scalar(rule.value)" type="number" label="Значение" @update:model-value="(v) => (rule.value = v ?? null)" />
                    </template>
                </div>
                <UiButton v-if="editable" size="sm" @click="addRule">Добавить правило</UiButton>
            </template>

            <template v-if="node.type === 'switch'">
                <UiSelect :model-value="draft.field" label="Поле" :options="switchFieldOptions" @update:model-value="(v) => setSwitchField(String(v))" />
                <div v-for="(c, i) in cases" :key="i" class="flex items-end gap-2">
                    <UiSelect v-model="c.value" :label="`Ветка ${i + 1}`" placeholder="Выберите значение" :options="valueOptions(catalog.fields[draft.field ?? ''])" class="flex-1" />
                    <UiButton v-if="editable" size="sm" variant="ghost" @click="removeCase(i)">×</UiButton>
                </div>
                <UiButton v-if="editable" size="sm" @click="addCase">Добавить ветку</UiButton>
            </template>

            <UiSelect v-if="node.type === 'select_primary'" v-model="draft.checklist_id" label="Основной чек-лист" placeholder="Выберите чек-лист" :options="checklistOptions" />

            <div v-if="node.type === 'add_additional'" class="space-y-1">
                <p class="text-[13px] font-medium">Дополнительные чек-листы</p>
                <UiCheckbox
                    v-for="o in checklistOptions"
                    :key="o.value"
                    :label="o.label"
                    :disabled="o.disabled"
                    :model-value="additional.includes(o.value)"
                    @update:model-value="(on) => toggleAdditional(o.value, !!on)"
                />
            </div>

            <UiInput v-if="node.type === 'create_review_task'" v-model="draft.reason" label="Причина проверки" :maxlength="300" required />
            <UiInput v-if="node.type === 'set_label'" v-model="draft.label" label="Метка" :maxlength="40" required />
            <UiSelect v-if="node.type === 'set_priority'" v-model="draft.priority" label="Приоритет" :options="priorityOptions" />
        </fieldset>

        <div v-if="outputs.length" class="space-y-2 border-t border-line pt-3">
            <p class="text-[13px] font-medium">Переходы</p>
            <UiSelect
                v-for="handle in outputs"
                :key="handle"
                :model-value="connections[handle] ?? null"
                :label="`Выход «${outputLabel(catalog, handle, draft)}»`"
                placeholder="Не подключён"
                :options="targetOptions"
                :disabled="!editable"
                @update:model-value="(v) => emit('connect', handle, v ? String(v) : null)"
            />
        </div>

        <UiButton v-if="editable && node.type !== 'start'" variant="danger" size="sm" @click="emit('remove')">Удалить узел</UiButton>
    </div>
</template>
