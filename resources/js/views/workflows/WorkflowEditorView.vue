<script setup lang="ts">
import { workflowsApi } from '@/api';
import { ApiError } from '@/api/http';
import type { GraphError, SimulationResult, WorkflowCatalog, WorkflowDetail, WorkflowGraph } from '@/api/types';
import PageHeader from '@/components/ui/PageHeader.vue';
import UiAlert from '@/components/ui/UiAlert.vue';
import UiBadge from '@/components/ui/UiBadge.vue';
import UiButton from '@/components/ui/UiButton.vue';
import UiCheckbox from '@/components/ui/UiCheckbox.vue';
import UiInput from '@/components/ui/UiInput.vue';
import UiModal from '@/components/ui/UiModal.vue';
import UiSelect from '@/components/ui/UiSelect.vue';
import UiSpinner from '@/components/ui/UiSpinner.vue';
import UiTextarea from '@/components/ui/UiTextarea.vue';
import NodeProperties from '@/components/workflows/NodeProperties.vue';
import SimulationPanel from '@/components/workflows/SimulationPanel.vue';
import WorkflowNode from '@/components/workflows/WorkflowNode.vue';
import { defaultData, describeNode, editorKey, groupErrors, nodeOutputs } from '@/components/workflows/describe';
import { useForm } from '@/composables/useForm';
import { useToasts } from '@/composables/useToasts';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MarkerType, VueFlow, useVueFlow, type Connection, type NodeTypesObject } from '@vue-flow/core';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import { computed, markRaw, nextTick, onMounted, provide, reactive, ref } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';

const props = defineProps<{ id: number }>();
const { push } = useToasts();
const flowId = `workflow-${props.id}`;
const { setNodes, setEdges, addNodes, addEdges, removeEdges, removeNodes, updateNodeData, toObject, findNode, fitView, onConnect, onNodesChange, onEdgesChange, nodes, edges } =
    useVueFlow(flowId);

const detail = ref<WorkflowDetail | null>(null);
const catalog = ref<WorkflowCatalog | null>(null);
const loading = ref(true);
const loadError = ref<string | null>(null);
const busy = ref<'' | 'save' | 'validate' | 'publish' | 'simulate'>('');
const actionError = ref<string | null>(null);
const dirty = ref(false);
const selectedId = ref<string | null>(null);
const errors = ref<GraphError[]>([]);
const validated = ref(false);
const panel = ref<'properties' | 'simulation'>('properties');
const simulation = ref<SimulationResult | null>(null);
const simulationError = ref<string | null>(null);

const selectedVersion = computed(() => detail.value?.selected ?? null);
const editable = computed(() => selectedVersion.value?.status === 'draft' && !!detail.value?.can.design);
const errorsByNode = computed(() => groupErrors(errors.value));
const traced = ref(new Set<string>());
provide(editorKey, { catalog, errorsByNode, traced });

const nodeTypes = computed<NodeTypesObject>(() =>
    Object.fromEntries(Object.keys(catalog.value?.node_types ?? {}).map((t) => [t, markRaw(WorkflowNode)])) as NodeTypesObject,
);

function loadGraph(graph: WorkflowGraph): void {
    setNodes(graph.nodes.map((n) => ({ ...n, deletable: n.type !== 'start' })));
    setEdges(graph.edges.map((e) => ({ ...e, sourceHandle: e.sourceHandle ?? 'out', type: 'smoothstep', markerEnd: MarkerType.ArrowClosed })));
    selectedId.value = null;
    traced.value = new Set();
    errors.value = [];
    validated.value = false;
    simulation.value = null;
    nextTick(() => setTimeout(() => fitView({ padding: 0.2 }), 50));
    dirty.value = false;
}

async function load(version?: number): Promise<void> {
    loadError.value = null;
    try {
        const d = await workflowsApi.show(props.id, version);
        if (!catalog.value || catalog.value && detail.value?.company?.id !== d.company?.id) {
            catalog.value = await workflowsApi.catalog(d.company?.id ?? null);
        }
        detail.value = d;
        if (d.selected) loadGraph(d.selected.graph);
    } catch (e) {
        loadError.value = e instanceof ApiError ? e.message : 'Не удалось загрузить процесс.';
    } finally {
        loading.value = false;
    }
}
onMounted(() => load());

onBeforeRouteLeave(() => !dirty.value || window.confirm('Есть несохранённые изменения. Уйти без сохранения?'));

onNodesChange((changes) => {
    if (changes.some((c) => c.type === 'remove' || (c.type === 'position' && c.dragging))) dirty.value = true;
    if (changes.some((c) => c.type === 'remove' && c.id === selectedId.value)) selectedId.value = null;
});
onEdgesChange((changes) => {
    if (changes.some((c) => c.type === 'remove')) dirty.value = true;
});

/** Один выход — одна связь: новая связь заменяет прежнюю. */
function connect(source: string, handle: string, target: string | null): void {
    const existing = edges.value.filter((e) => e.source === source && (e.sourceHandle ?? 'out') === handle);
    if (existing.length) removeEdges(existing.map((e) => e.id));
    if (target && target !== source) {
        addEdges([{ id: `e-${source}-${handle}-${target}`, source, sourceHandle: handle, target, type: 'smoothstep', markerEnd: MarkerType.ArrowClosed }]);
    }
    dirty.value = true;
}

onConnect((c: Connection) => editable.value && connect(c.source, c.sourceHandle ?? 'out', c.target));

/** Удаление ветки «выбора»: её связь снимается, следующие ветки сдвигаются. */
function removeCase(nodeId: string, index: number): void {
    const updated = edges.value
        .filter((e) => e.source === nodeId && e.sourceHandle?.startsWith('case:'))
        .map((e) => ({ edge: e, i: Number(e.sourceHandle?.slice(5)) }));
    removeEdges(updated.map((u) => u.edge.id));
    addEdges(
        updated
            .filter((u) => u.i !== index)
            .map(({ edge, i }) => {
                const handle = `case:${i > index ? i - 1 : i}`;
                return { id: `e-${nodeId}-${handle}-${edge.target}`, source: nodeId, sourceHandle: handle, target: edge.target, type: 'smoothstep', markerEnd: MarkerType.ArrowClosed };
            }),
    );
    dirty.value = true;
}

function updateData(nodeId: string, data: Record<string, unknown>): void {
    updateNodeData(nodeId, data, { replace: true });
    dirty.value = true;
}

/** Новый узел ставится правее выбранного. */
function addNode(type: string): void {
    const from = selectedId.value ? findNode(selectedId.value) : null;
    const base = from?.position ?? { x: 120, y: 120 + nodes.value.length * 30 };
    let id = `${type}-${nodes.value.length + 1}`;
    while (findNode(id)) id = `${id}x`;
    const position = { x: base.x + 300, y: base.y };
    // Не накладывать на существующие узлы.
    while (nodes.value.some((n) => Math.abs(n.position.x - position.x) < 240 && Math.abs(n.position.y - position.y) < 120)) position.y += 140;
    addNodes([{ id, type, position, data: defaultData(type), deletable: true }]);
    // Узел с одним выходом: новый встаёт между ним и следующим узлом.
    const outputs = from ? nodeOutputs(from.type ?? '', from.data as Record<string, unknown>) : [];
    if (from && outputs.length === 1 && outputs[0]) {
        const next = edges.value.find((e) => e.source === from.id && (e.sourceHandle ?? 'out') === outputs[0])?.target;
        connect(from.id, outputs[0], id);
        const first = nodeOutputs(type, defaultData(type))[0];
        if (next && first) connect(id, first, next);
        // Следующий узел сдвигается правее нового — схема читается слева направо.
        const nextNode = next ? findNode(next) : undefined;
        if (nextNode && nextNode.position.x < position.x + 280) nextNode.position = { x: position.x + 300, y: nextNode.position.y };
    }
    selectedId.value = id;
    panel.value = 'properties';
    dirty.value = true;
    // Новый узел — в поле зрения.
    nextTick(() => fitView({ nodes: [id, ...(from ? [from.id] : [])], padding: 0.6, maxZoom: 1, duration: 200 }));
}

function removeSelected(): void {
    if (!selectedId.value) return;
    removeNodes([selectedId.value], true);
    selectedId.value = null;
    dirty.value = true;
}

function serialize(): WorkflowGraph {
    const o = toObject();
    return {
        nodes: o.nodes.map((n) => ({ id: n.id, type: n.type ?? 'default', position: { x: Math.round(n.position.x), y: Math.round(n.position.y) }, data: n.data as Record<string, unknown> })),
        edges: o.edges.map((e) => ({ id: e.id, source: e.source, sourceHandle: e.sourceHandle ?? 'out', target: e.target })),
    };
}

async function run<T>(kind: typeof busy.value, action: () => Promise<T>): Promise<T | undefined> {
    busy.value = kind;
    actionError.value = null;
    try {
        return await action();
    } catch (e) {
        actionError.value = e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : 'Не удалось выполнить действие.';
        return undefined;
    } finally {
        busy.value = '';
    }
}

async function saveIfDirty(): Promise<boolean> {
    const v = selectedVersion.value;
    if (!v || !editable.value || !dirty.value) return true;
    const saved = await workflowsApi.saveDraft(props.id, v.id, serialize(), v.lock_version);
    if (detail.value) detail.value.selected = saved;
    dirty.value = false;
    return true;
}

async function validate(): Promise<GraphError[]> {
    const v = selectedVersion.value;
    if (!v) return [];
    errors.value = (await workflowsApi.validate(props.id, v.id)).errors;
    validated.value = true;
    return errors.value;
}

async function save(): Promise<void> {
    await run('save', async () => {
        await saveIfDirty();
        await validate();
        push('Черновик сохранён.');
    });
}

async function check(): Promise<void> {
    await run('validate', async () => {
        await saveIfDirty();
        const found = await validate();
        push(found.length ? `Найдено ошибок: ${found.length}.` : 'Ошибок нет — процесс можно публиковать.', found.length ? 'danger' : 'success');
    });
}

async function publish(): Promise<void> {
    const v = selectedVersion.value;
    if (!v) return;
    await run('publish', async () => {
        await saveIfDirty();
        if ((await validate()).length) {
            actionError.value = 'Процесс не прошёл проверку — исправьте отмеченные узлы.';
            return;
        }
        if (!window.confirm(`Опубликовать версию ${v.version}? Новые звонки пойдут по ней; уже запущенные экземпляры не изменятся.`)) return;
        await workflowsApi.publish(props.id, v.id);
        await load(v.id);
        push(`Версия ${v.version} опубликована.`);
    });
}

async function newDraft(): Promise<void> {
    await run('save', async () => {
        const draft = await workflowsApi.newDraft(props.id);
        await load(draft.id);
        push('Создан черновик — копия опубликованной версии.');
    });
}

async function selectVersion(id?: number | string | null): Promise<void> {
    if (!id || (dirty.value && !window.confirm('Есть несохранённые изменения. Переключить версию без сохранения?'))) return;
    await load(Number(id));
}

async function simulate(body: { input: Record<string, unknown>; call_id: number | null }): Promise<void> {
    const v = selectedVersion.value;
    if (!v) return;
    simulationError.value = null;
    busy.value = 'simulate';
    try {
        await saveIfDirty();
        const result = await workflowsApi.simulate(props.id, v.id, body);
        simulation.value = result;
        errors.value = result.errors;
        traced.value = new Set(result.trace.map((s) => s.node_id));
    } catch (e) {
        simulationError.value = e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : 'Симуляция не выполнена.';
    } finally {
        busy.value = '';
    }
}

const selectedNode = computed(() => {
    const n = selectedId.value ? nodes.value.find((x) => x.id === selectedId.value) : null;
    return n ? { id: n.id, type: n.type ?? '', data: n.data as Record<string, unknown> } : null;
});

function nodeLabel(id: string): string {
    const n = nodes.value.find((x) => x.id === id);
    if (!n || !catalog.value) return id;
    const data = n.data as Record<string, unknown>;
    const title = (data.label as string) || catalog.value.node_types[n.type ?? '']?.label || n.type || id;
    const summary = describeNode(catalog.value, n.type ?? '', data)[0];
    return summary ? `${title}: ${summary}` : title;
}

const targets = computed(() => nodes.value.map((n) => ({ id: n.id, label: nodeLabel(n.id) })));
const connections = computed(() =>
    Object.fromEntries(edges.value.filter((e) => e.source === selectedId.value).map((e) => [e.sourceHandle ?? 'out', e.target])),
);

const palette = computed(() => {
    const groups = new Map<string, { type: string; label: string; available: boolean; description: string }[]>();
    for (const [type, meta] of Object.entries(catalog.value?.node_types ?? {})) {
        if (type === 'start') continue;
        groups.set(meta.group, [...(groups.get(meta.group) ?? []), { type, ...meta }]);
    }
    return [...groups.entries()];
});

const versionOptions = computed(() =>
    (detail.value?.versions ?? []).map((v) => ({ value: v.id, label: `v${v.version} · ${{ draft: 'черновик', published: 'действует', archived: 'архив' }[v.status]}` })),
);

function focusNode(id: string | null): void {
    if (!id) return;
    selectedId.value = id;
    panel.value = 'properties';
    fitView({ nodes: [id], padding: 1.5, duration: 300 });
}

// Параметры процесса.
const settingsOpen = ref(false);
const settings = reactive({ name: '', description: '', priority: 100 as number | string | null, is_archived: false });
const settingsForm = useForm();

function openSettings(): void {
    const d = detail.value;
    if (!d) return;
    Object.assign(settings, { name: d.name, description: d.description ?? '', priority: d.priority, is_archived: d.is_archived });
    settingsForm.reset();
    settingsOpen.value = true;
}

async function saveSettings(): Promise<void> {
    const saved = await settingsForm.submit(() =>
        workflowsApi.update(props.id, { name: settings.name, description: settings.description || null, priority: Number(settings.priority), is_archived: settings.is_archived }),
    );
    if (saved && detail.value) {
        Object.assign(detail.value, saved);
        settingsOpen.value = false;
        push('Параметры сохранены.');
    }
}
</script>

<template>
    <div v-if="loading" class="flex justify-center py-16 text-muted"><UiSpinner :size="24" /></div>
    <UiAlert v-else-if="loadError || !detail || !catalog" tone="danger" title="Процесс недоступен">{{ loadError }}</UiAlert>
    <template v-else>
        <PageHeader :title="detail.name" :description="`${detail.company?.name ?? 'Вся группа'} · приоритет ${detail.priority}${detail.description ? ' · ' + detail.description : ''}`">
            <template #actions>
                <UiBadge v-if="detail.is_archived">в архиве</UiBadge>
                <UiSelect :model-value="selectedVersion?.id ?? null" :options="versionOptions" class="w-48" aria-label="Версия" @update:model-value="selectVersion" />
                <UiButton v-if="detail.can.design" @click="openSettings">Параметры</UiButton>
                <UiButton v-if="detail.can.design && !detail.has_draft" :loading="busy === 'save'" @click="newDraft">Новый черновик</UiButton>
                <UiButton v-if="selectedVersion" :loading="busy === 'validate'" @click="check">Проверить</UiButton>
                <UiButton v-if="editable" :loading="busy === 'save'" :disabled="!dirty" @click="save">Сохранить</UiButton>
                <UiButton v-if="selectedVersion?.status === 'draft' && detail.can.publish" variant="primary" :loading="busy === 'publish'" @click="publish">Опубликовать</UiButton>
            </template>
        </PageHeader>

        <UiAlert v-if="actionError" tone="danger" class="mb-4">{{ actionError }}</UiAlert>
        <UiAlert v-if="selectedVersion && selectedVersion.status !== 'draft'" tone="info" class="mb-4">
            Версия {{ selectedVersion.version }} {{ selectedVersion.status === 'published' ? 'действует' : 'в архиве' }} и не изменяется.
            {{ detail.can.design ? (detail.has_draft ? 'Правки — в черновике (выберите его в списке версий).' : 'Для правок создайте новый черновик.') : '' }}
        </UiAlert>
        <UiAlert v-else-if="dirty" tone="warning" class="mb-4">Есть несохранённые изменения.</UiAlert>

        <div class="grid gap-4 lg:grid-cols-[190px_1fr_330px]">
            <aside class="space-y-4">
                <div v-if="editable" class="rounded-xl border border-line bg-white p-3">
                    <p class="mb-2 text-[13px] font-semibold">Добавить узел</p>
                    <p class="mb-3 text-[11px] text-muted">Узел встанет после выбранного и подключится к нему.</p>
                    <div v-for="[group, items] in palette" :key="group" class="mb-3">
                        <p class="mb-1 text-[11px] uppercase tracking-wide text-muted">{{ group }}</p>
                        <button
                            v-for="item in items"
                            :key="item.type"
                            type="button"
                            class="mb-1 block w-full rounded-md border border-line px-2 py-1.5 text-left text-[12px] hover:border-brand-500 hover:bg-brand-50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="!item.available"
                            :title="item.description"
                            @click="addNode(item.type)"
                        >
                            {{ item.label }}<span v-if="!item.available" class="block text-[10px] text-muted">в разработке</span>
                        </button>
                    </div>
                </div>
                <div class="rounded-xl border border-line bg-white p-3" data-testid="graph-errors">
                    <p class="mb-2 text-[13px] font-semibold">Проверка</p>
                    <p v-if="!validated" class="text-[12px] text-muted">Нажмите «Проверить».</p>
                    <p v-else-if="!errors.length" class="text-[12px] text-emerald-700">Ошибок нет.</p>
                    <ul v-else class="space-y-1.5">
                        <li v-for="(e, i) in errors" :key="i">
                            <button type="button" class="text-left text-[12px] text-red-700 hover:underline" :disabled="!e.node_id" @click="focusNode(e.node_id)">
                                <span v-if="e.node_id" class="font-medium">{{ nodeLabel(e.node_id) }}: </span>{{ e.message }}
                            </button>
                        </li>
                    </ul>
                </div>
            </aside>

            <div class="h-[calc(100vh-230px)] min-h-[520px] overflow-hidden rounded-xl border border-line bg-white">
                <VueFlow
                    :id="flowId"
                    :node-types="nodeTypes"
                    :nodes-draggable="editable"
                    :nodes-connectable="editable"
                    :edges-updatable="false"
                    :delete-key-code="editable ? 'Delete' : null"
                    :min-zoom="0.2"
                    :max-zoom="1.5"
                    @node-click="({ node }) => { selectedId = node.id; panel = 'properties'; }"
                    @pane-click="selectedId = null"
                >
                    <Background :gap="20" />
                    <Controls :show-interactive="false" />
                </VueFlow>
            </div>

            <aside class="rounded-xl border border-line bg-white">
                <div class="flex border-b border-line">
                    <button type="button" class="flex-1 px-3 py-2 text-[13px]" :class="panel === 'properties' ? 'border-b-2 border-brand-600 font-semibold' : 'text-muted'" @click="panel = 'properties'">Свойства</button>
                    <button type="button" class="flex-1 px-3 py-2 text-[13px]" :class="panel === 'simulation' ? 'border-b-2 border-brand-600 font-semibold' : 'text-muted'" @click="panel = 'simulation'">Симуляция</button>
                </div>
                <div class="max-h-[calc(100vh-280px)] overflow-y-auto p-4">
                    <template v-if="panel === 'properties'">
                        <NodeProperties
                            v-if="selectedNode"
                            :key="selectedNode.id"
                            :node="selectedNode"
                            :catalog="catalog"
                            :editable="editable"
                            :targets="targets"
                            :connections="connections"
                            :errors="errorsByNode.get(selectedNode.id) ?? []"
                            @update="(data) => updateData(selectedNode!.id, data)"
                            @connect="(handle, target) => connect(selectedNode!.id, handle, target)"
                            @remove-case="(i) => removeCase(selectedNode!.id, i)"
                            @remove="removeSelected"
                        />
                        <div v-else class="space-y-2 text-[13px] text-muted">
                            <p>Выберите узел на схеме, чтобы настроить его.</p>
                            <p>Связь — протяните от точки выхода справа к узлу или выберите переход в свойствах узла. Удаление выбранного — клавиша Delete.</p>
                            <p>Поля результата оценки доступны только после узла «Запустить анализ».</p>
                        </div>
                    </template>
                    <SimulationPanel v-else :catalog="catalog" :result="simulation" :running="busy === 'simulate'" :error="simulationError" :node-label="nodeLabel" @run="simulate" />
                </div>
            </aside>
        </div>

        <UiModal v-model:open="settingsOpen" title="Параметры процесса">
            <form id="wf-settings" class="space-y-4" @submit.prevent="saveSettings">
                <UiAlert v-if="settingsForm.formError.value" tone="danger">{{ settingsForm.formError.value }}</UiAlert>
                <UiInput v-model="settings.name" label="Название" required :error="settingsForm.errors.value.name" />
                <UiTextarea v-model="settings.description" label="Описание" :rows="2" />
                <UiInput
                    v-model="settings.priority"
                    type="number"
                    label="Приоритет"
                    hint="Если подходят несколько процессов, выбирается процесс компании, затем — с меньшим числом."
                    :error="settingsForm.errors.value.priority"
                />
                <UiCheckbox v-model="settings.is_archived" label="В архиве (не запускается для новых звонков)" />
            </form>
            <template #footer>
                <UiButton @click="settingsOpen = false">Отмена</UiButton>
                <UiButton type="submit" form="wf-settings" variant="primary" :loading="settingsForm.submitting.value">Сохранить</UiButton>
            </template>
        </UiModal>
    </template>
</template>
