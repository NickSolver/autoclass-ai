<script setup lang="ts">
import { Handle, Position, type NodeProps } from '@vue-flow/core';
import { computed, inject } from 'vue';
import { describeNode, editorKey, nodeOutputs, outputLabel } from './describe';

/** Узел процесса на холсте: тип, краткое содержание, выходы с подписями, ошибки проверки. */
const props = defineProps<NodeProps<Record<string, unknown>>>();
const ctx = inject(editorKey);

const catalog = computed(() => ctx?.catalog.value ?? null);
const title = computed(() => catalog.value?.node_types[props.type]?.label ?? props.type);
const lines = computed(() => (catalog.value ? describeNode(catalog.value, props.type, props.data) : []));
const outputs = computed(() => nodeOutputs(props.type, props.data));
const errors = computed(() => ctx?.errorsByNode.value.get(props.id) ?? []);
const traced = computed(() => ctx?.traced.value.has(props.id) ?? false);

const accent: Record<string, string> = {
    start: 'bg-emerald-600',
    end: 'bg-gray-500',
    condition: 'bg-amber-500',
    switch: 'bg-amber-500',
    select_primary: 'bg-brand-600',
    add_additional: 'bg-brand-500',
    run_analysis: 'bg-indigo-600',
};
</script>

<template>
    <div
        class="w-56 rounded-lg border bg-white text-[12px] shadow-sm"
        :class="[errors.length ? 'border-red-400' : selected ? 'border-brand-500' : 'border-line', traced ? 'ring-2 ring-emerald-400' : '', selected ? 'ring-2 ring-brand-200' : '']"
        :data-testid="`node-${id}`"
    >
        <Handle v-if="type !== 'start'" type="target" :position="Position.Left" class="!h-3 !w-3 !border-2 !border-white !bg-gray-400" />
        <div class="flex items-center gap-2 rounded-t-lg px-3 py-1.5 text-white" :class="accent[type] ?? 'bg-slate-600'">
            <span class="truncate font-semibold">{{ (data.label as string) || title }}</span>
            <span v-if="errors.length" class="ml-auto rounded bg-white px-1.5 text-[10px] font-semibold text-red-600" :title="errors.join('\n')">{{ errors.length }}</span>
        </div>
        <div v-if="lines.length" class="space-y-0.5 px-3 py-2 text-ink">
            <p v-for="(line, i) in lines.slice(0, 4)" :key="i" class="truncate" :title="line">{{ line }}</p>
            <p v-if="lines.length > 4" class="text-muted">ещё {{ lines.length - 4 }}</p>
        </div>
        <div v-if="outputs.length > 1" class="border-t border-line py-1">
            <div v-for="handle in outputs" :key="handle" class="relative pr-4 text-right leading-6 text-muted">
                {{ outputLabel(catalog, handle, data) }}
                <Handle :id="handle" type="source" :position="Position.Right" class="!h-3 !w-3 !border-2 !border-white !bg-brand-600" />
            </div>
        </div>
        <Handle v-else-if="outputs.length === 1" id="out" type="source" :position="Position.Right" class="!h-3 !w-3 !border-2 !border-white !bg-brand-600" />
    </div>
</template>
